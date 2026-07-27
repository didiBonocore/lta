<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Analysis\Discovery\SuiteDiscovery;
use App\Analysis\FrontEnd\FrontEnd;
use App\Analysis\FrontEnd\PestFrontEnd;
use App\Analysis\FrontEnd\PhpUnitFrontEnd;
use App\Analysis\Ir\Enums\TestType;
use App\Analysis\Ir\TestFileRecord;
use App\Analysis\Tree\GitTree;
use App\Analysis\Tree\SourceTree;
use App\Analysis\Tree\WorkingTree;
use App\Models\ParseFailure;
use App\Models\Repository;
use App\Models\Snapshot;
use App\Models\TestObservation;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use PhpParser\Error as ParseError;

use function Laravel\Prompts\progress;

/**
 * Stages 2-4 — discover the suite, route each test file to the owning FrontEnd by content,
 * and flatten the resulting IR into TestObservation rows. --head reads the working tree at
 * the acquired HEAD (M1); without it, every version-boundary snapshot is extracted through
 * `git show` (Instrument A, M2) so the clone is never checked out serially.
 */
class ExtractCommand extends Command
{
    protected $signature = 'analyse:extract {full_name : owner/repo} {--head : extract HEAD only}';

    protected $description = 'Parse test suites into the IR and write metric rows to the dataset';

    public function handle(SuiteDiscovery $discovery): int
    {
        $repository = Repository::where('full_name', $this->argument('full_name'))->first();
        if ($repository === null) {
            $this->error('Repository not acquired yet — run analyse:acquire first.');

            return self::FAILURE;
        }

        $root = (string) $repository->clone_path;
        if (! is_dir($root)) {
            $this->error("Clone path missing on disk: {$root}");

            return self::FAILURE;
        }

        if ($this->option('head')) {
            $snapshot = Snapshot::updateOrCreate(
                ['repository_id' => $repository->id, 'kind' => 'head'],
                ['commit_sha' => (string) $repository->head_sha, 'framework_version' => null],
            );

            $summary = $this->extractSnapshot($discovery, $repository, $snapshot, new WorkingTree($root));
            $repository->update(['primary_test_framework' => $this->primaryFramework($summary['perFrontEnd'])]);

            $this->reportSnapshot($snapshot, $summary);
            $this->summariseByFrontEndAndType($summary['rows']);

            return self::SUCCESS;
        }

        $snapshots = $repository->snapshots()
            ->where('kind', 'version_boundary')
            ->orderBy('framework_version')
            ->get();

        if ($snapshots->isEmpty()) {
            $this->error('No version-boundary snapshots — run analyse:snapshot first, or pass --head.');

            return self::FAILURE;
        }

        $perVersion = [];
        progress(
            label: "Extracting {$snapshots->count()} version-boundary snapshots",
            steps: $snapshots,
            callback: function (Snapshot $snapshot, $progress) use ($discovery, $repository, $root, &$perVersion): void {
                $progress->hint("Laravel {$snapshot->framework_version} @ ".substr($snapshot->commit_sha, 0, 12));
                $summary = $this->extractSnapshot($discovery, $repository, $snapshot, new GitTree($root, $snapshot->commit_sha));
                $perVersion[] = [
                    $snapshot->framework_version,
                    $summary['files'],
                    count($summary['rows']),
                    $summary['unroutable'],
                    $summary['parseFailures'],
                ];
            },
        );

        $this->table(['Laravel major', 'files', 'observations', 'unroutable', 'parse failures'], $perVersion);

        return self::SUCCESS;
    }

    /**
     * Replace one snapshot's observations with a fresh extraction of its tree.
     *
     * @return array{rows: list<array<string, mixed>>, files: int, unroutable: int, parseFailures: int, perFrontEnd: array<string, int>}
     */
    private function extractSnapshot(
        SuiteDiscovery $discovery,
        Repository $repository,
        Snapshot $snapshot,
        SourceTree $tree,
    ): array {
        $snapshot->observations()->delete();
        $snapshot->parseFailures()->delete();

        $files = $discovery->discover($tree);
        // Pest before PHPUnit: Pest files are plain PHP with no class to anchor on, so the
        // more specific closure-shape check must get first refusal.
        $frontEnds = [new PestFrontEnd, new PhpUnitFrontEnd];

        $rows = [];
        $observationsPerFrontEnd = [];
        $unroutable = 0;
        $parseFailures = 0;

        foreach ($files as $relativePath) {
            $source = $tree->read($relativePath);
            if ($source === null) {
                continue;
            }

            try {
                $record = $this->routeAndParse($frontEnds, $relativePath, $source);
            } catch (ParseError $e) {
                $parseFailures++;
                ParseFailure::create([
                    'repository_id' => $repository->id,
                    'snapshot_id' => $snapshot->id,
                    'file_path' => $relativePath,
                    'commit_sha' => (string) $snapshot->commit_sha,
                    'message' => str($e->getMessage())->limit(250)->toString(),
                ]);
                $this->warn("Parse failure in {$relativePath}: {$e->getMessage()}");

                continue;
            }

            if ($record === null) {
                $unroutable++;

                continue;
            }

            $observationsPerFrontEnd[$record->frontEnd->value] = ($observationsPerFrontEnd[$record->frontEnd->value] ?? 0) + count($record->methods);
            foreach ($record->methods as $method) {
                $rows[] = [
                    'snapshot_id' => $snapshot->id,
                    'repository_id' => $repository->id,
                    'file_path' => $relativePath,
                    'identifier' => $method->identifier,
                    'front_end' => $method->frontEnd->value,
                    'test_type' => $method->type->value,
                    'test_type_rule' => $method->typeRule,
                    'test_assertion_count' => $method->testAssertionCount,
                    'mock_assertion_count' => $method->mockAssertionCount,
                    'total_assertion_count' => $method->totalAssertionCount,
                    'mock_assertion_ratio' => $method->mockAssertionRatio,
                    'mock_breadth' => $method->mockBreadth(),
                    'max_mock_chain_depth' => $method->maxMockChainDepth(),
                    'mock_kinds' => json_encode($method->mockKinds()),
                    'size_statements' => $method->sizeStatements,
                    'size_loc' => $method->sizeLoc,
                    'start_line' => $method->startLine > 0 ? $method->startLine : null,
                    'end_line' => $method->endLine > 0 ? $method->endLine : null,
                    'uses_refresh_database' => $method->usesRefreshDatabase,
                    'setup_signals' => json_encode($method->setupSignals),
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }
        }

        foreach (array_chunk($rows, 200) as $chunk) {
            TestObservation::insert($chunk);
        }

        return [
            'rows' => $rows,
            'files' => count($files),
            'unroutable' => $unroutable,
            'parseFailures' => $parseFailures,
            'perFrontEnd' => $observationsPerFrontEnd,
        ];
    }

    /**
     * @param  array{rows: list<array<string, mixed>>, files: int, unroutable: int, parseFailures: int, perFrontEnd: array<string, int>}  $summary
     */
    private function reportSnapshot(Snapshot $snapshot, array $summary): void
    {
        $this->info(sprintf(
            'Extracted %d observations from %d files (%d unroutable, %d parse failures) @ %s.',
            count($summary['rows']),
            $summary['files'],
            $summary['unroutable'],
            $summary['parseFailures'],
            $snapshot->commit_sha,
        ));
    }

    /**
     * front_end × test_type cross-tab of the rows just written.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    private function summariseByFrontEndAndType(array $rows): void
    {
        if ($rows === []) {
            return;
        }

        $types = array_map(fn (TestType $t): string => $t->value, TestType::cases());
        $matrix = collect($rows)->groupBy('front_end')->sortKeys();

        $this->table(
            ['front_end', ...$types, 'total'],
            $matrix->map(function (Collection $group, string $frontEnd) use ($types): array {
                $byType = $group->countBy('test_type');

                return [$frontEnd, ...array_map(fn (string $t) => $byType[$t] ?? 0, $types), $group->count()];
            })->values()->all(),
        );
    }

    /** @param list<FrontEnd> $frontEnds */
    private function routeAndParse(array $frontEnds, string $path, string $source): ?TestFileRecord
    {
        foreach ($frontEnds as $frontEnd) {
            if ($frontEnd->handles($source)) {
                return $frontEnd->parse($path, $source);
            }
        }

        return null;
    }

    /** @param array<string,int> $observationsPerFrontEnd */
    private function primaryFramework(array $observationsPerFrontEnd): ?string
    {
        $present = array_keys(array_filter($observationsPerFrontEnd));

        return match (count($present)) {
            0 => null,
            1 => $present[0],
            default => 'mixed',
        };
    }
}
