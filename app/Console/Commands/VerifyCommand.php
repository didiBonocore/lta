<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\ParseFailure;
use App\Models\Repository;
use App\Models\Snapshot;
use App\Models\TestObservation;
use Illuminate\Console\Command;

/**
 * Operator sanity checks over the emitted dataset — the queries an operator would otherwise
 * type by hand. Hard failures (structural corruption: orphan rows, duplicate or
 * non-ascending majors, a repository with no observations at all) exit non-zero so the
 * command can gate a batch script. Legitimate-but-noteworthy states (an empty individual
 * snapshot, an unblamed repository, parse failures, non-monotonic snapshot dates) warn
 * without failing: the pilot corpus itself contains all of them for honest reasons.
 */
class VerifyCommand extends Command
{
    protected $signature = 'analyse:verify {full_name? : owner/repo (default: every repository)}';

    protected $description = 'Run operator sanity checks over the emitted dataset';

    /** @var list<array{0: string, 1: string, 2: string, 3: string}> */
    private array $rows = [];

    private int $hardFailures = 0;

    public function handle(): int
    {
        $repositories = $this->argument('full_name') !== null
            ? Repository::where('full_name', $this->argument('full_name'))->get()
            : Repository::orderBy('full_name')->get();

        if ($repositories->isEmpty()) {
            $this->error('No matching repository — run analyse:acquire first.');

            return self::FAILURE;
        }

        $this->checkOrphans();
        foreach ($repositories as $repository) {
            $this->checkRepository($repository);
        }

        $this->table(['repository', 'check', 'status', 'detail'], $this->rows);

        if ($this->hardFailures > 0) {
            $this->error("{$this->hardFailures} hard failure(s) — dataset is not fit to report on.");

            return self::FAILURE;
        }

        $this->info('All hard checks passed.');

        return self::SUCCESS;
    }

    /**
     * Cross-table referential integrity, independent of SQLite's FK enforcement.
     */
    private function checkOrphans(): void
    {
        $orphans =
            TestObservation::whereNotIn('snapshot_id', Snapshot::select('id'))->count()
            + TestObservation::whereNotIn('repository_id', Repository::select('id'))->count()
            + Snapshot::whereNotIn('repository_id', Repository::select('id'))->count()
            + ParseFailure::whereNotIn('snapshot_id', Snapshot::select('id'))->count()
            // Cross-link consistency FKs cannot enforce: an observation must belong to the
            // same repository as its snapshot.
            + TestObservation::join('snapshots', 'snapshots.id', '=', 'test_observations.snapshot_id')
                ->whereColumn('snapshots.repository_id', '!=', 'test_observations.repository_id')
                ->count();

        $this->record('—', 'orphan rows', $orphans === 0, $orphans === 0 ? 'none' : "{$orphans} orphan row(s)");
    }

    private function checkRepository(Repository $repository): void
    {
        $name = (string) $repository->full_name;
        $snapshots = $repository->snapshots()->where('kind', 'version_boundary')->orderBy('framework_version')->get();

        // Strictly ascending, duplicate-free majors.
        $majors = $snapshots->pluck('framework_version')->all();
        $strictlyAscending = $majors === array_values(array_unique($majors))
            && $majors !== []
            && $majors === collect($majors)->sort()->values()->all();
        $this->record(
            $name,
            'majors strictly ascending',
            $strictlyAscending,
            $majors === [] ? 'no version-boundary snapshots' : implode(' → ', $majors),
        );

        $duplicates = $snapshots->count() - $snapshots->pluck('framework_version')->unique()->count();
        $this->record($name, 'no duplicate (repository, major)', $duplicates === 0, $duplicates === 0 ? 'none' : "{$duplicates} duplicate(s)");

        // Observations exist at all (hard), and per snapshot (soft — empty eras are real).
        $total = $repository->observations()->count();
        $this->record($name, 'observations present', $total > 0, "{$total} rows");

        $empty = $snapshots->filter(fn (Snapshot $s): bool => $s->observations()->count() === 0);
        if ($empty->isNotEmpty()) {
            $this->warnRow($name, 'empty snapshots', 'majors '.$empty->pluck('framework_version')->implode(', ').' have 0 observations');
        }

        // Snapshot author dates monotonic with major order (soft — messy histories exist).
        $stamps = $snapshots->pluck('commit_date')->filter()->map(fn ($d) => $d->timestamp)->values()->all();
        $sorted = $stamps;
        sort($sorted);
        if (count($stamps) > 1 && $stamps !== $sorted) {
            $this->warnRow($name, 'snapshot dates monotonic', 'representative dates are not in major order');
        }

        // Instrument B attrition within the blame scope (the newest extracted snapshot).
        $newest = $repository->snapshots()
            ->has('observations')
            ->orderByRaw("kind = 'head' desc")
            ->orderByDesc('framework_version')
            ->first();
        if ($newest === null || $newest->observations()->whereNotNull('ai_window')->count() === 0) {
            $this->warnRow($name, 'instrument B coverage', 'blame has not run — ai_window is empty');
        } else {
            $scope = $newest->observations()->count();
            $nulls = $newest->observations()->whereNull('ai_window')->count();
            $this->okRow($name, 'instrument B coverage', sprintf(
                '%.1f%% unattributed (%d of %d, newest snapshot)',
                $scope > 0 ? $nulls / $scope * 100 : 0,
                $nulls,
                $scope,
            ));
        }

        // Parse-failure rate (informational — feeds threats-to-validity).
        $failures = ParseFailure::where('repository_id', $repository->id)->count();
        $this->okRow($name, 'parse-failure rate', sprintf(
            '%.2f%% (%d failures / %d parsed+failed)',
            ($failures + $total) > 0 ? $failures / ($failures + $total) * 100 : 0,
            $failures,
            $failures + $total,
        ));
    }

    private function record(string $repository, string $check, bool $passed, string $detail): void
    {
        if (! $passed) {
            $this->hardFailures++;
        }
        $this->rows[] = [$repository, $check, $passed ? 'ok' : 'FAIL', $detail];
    }

    private function okRow(string $repository, string $check, string $detail): void
    {
        $this->rows[] = [$repository, $check, 'ok', $detail];
    }

    private function warnRow(string $repository, string $check, string $detail): void
    {
        $this->rows[] = [$repository, $check, 'warn', $detail];
    }
}
