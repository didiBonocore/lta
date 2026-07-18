<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Analysis\Versioning\LaravelMajorResolver;
use App\Models\Repository;
use App\Models\Snapshot;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;

/**
 * Stage 1 / Instrument A — reconstruct integer-major Laravel checkpoints from composer.json
 * history. For each major the representative commit is the mature state just before the
 * constraint moved on (the parent of the bump commit); the current major is represented by
 * HEAD. Locked decision: integer majors only — 5.x-splitting is a sensitivity check in the
 * paper, not part of the primary analysis.
 */
class SnapshotCommand extends Command
{
    protected $signature = 'analyse:snapshot {full_name : owner/repo}';

    protected $description = 'Mine composer.json history to build version-boundary snapshots (integer majors)';

    public function handle(LaravelMajorResolver $resolver): int
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

        $touches = $this->composerTouches($root, $resolver);
        if ($touches === []) {
            $this->error('No composer.json commit resolves to a Laravel major — cannot snapshot.');

            return self::FAILURE;
        }

        $created = [];
        foreach ($this->representatives($root, $touches) as $major => $sha) {
            $commitDate = trim($this->git($root, ['show', '-s', '--format=%aI', $sha]));

            Snapshot::updateOrCreate(
                ['repository_id' => $repository->id, 'framework_version' => $major],
                ['commit_sha' => $sha, 'kind' => 'version_boundary', 'commit_date' => $commitDate],
            );
            $created[] = [$major, substr($sha, 0, 12), $commitDate];
        }

        $this->info(sprintf('%d version-boundary snapshots for %s.', count($created), $repository->full_name));
        $this->table(['Laravel major', 'representative commit', 'author date'], $created);

        return self::SUCCESS;
    }

    /**
     * Every composer.json-touching commit that resolves to a Laravel major, oldest first.
     *
     * @return list<array{sha: string, major: int}>
     */
    private function composerTouches(string $root, LaravelMajorResolver $resolver): array
    {
        $log = $this->git($root, ['log', '--follow', '--reverse', '--format=%H', '--', 'composer.json']);

        $touches = [];
        foreach (array_filter(explode("\n", trim($log))) as $sha) {
            $result = Process::path($root)->run(['git', 'show', "{$sha}:composer.json"]);
            if (! $result->successful()) {
                continue; // composer.json absent at this commit (e.g. the rename source)
            }

            $composer = json_decode($result->output(), true);
            if (! is_array($composer)) {
                continue;
            }

            $major = $resolver->resolveFromComposer($composer);
            if ($major !== null) {
                $touches[] = ['sha' => $sha, 'major' => $major];
            }
        }

        return $touches;
    }

    /**
     * One representative commit per major: the parent of the commit that bumped away from
     * it (falling back to the last constraint commit itself for parentless edges), and HEAD
     * for the major still current at clone time.
     *
     * @param  list<array{sha: string, major: int}>  $touches
     * @return array<int, string> major => representative sha
     */
    private function representatives(string $root, array $touches): array
    {
        $lastIndexPerMajor = [];
        foreach ($touches as $index => $touch) {
            $lastIndexPerMajor[$touch['major']] = $index;
        }

        $representatives = [];
        foreach ($lastIndexPerMajor as $major => $lastIndex) {
            $bump = $touches[$lastIndex + 1] ?? null;
            if ($bump === null) {
                $representatives[$major] = trim($this->git($root, ['rev-parse', 'HEAD']));

                continue;
            }

            $parent = Process::path($root)->run(['git', 'rev-parse', "{$bump['sha']}^"]);
            $representatives[$major] = $parent->successful()
                ? trim($parent->output())
                : $touches[$lastIndex]['sha'];
        }

        ksort($representatives);

        return $representatives;
    }

    /** @param list<string> $arguments */
    private function git(string $root, array $arguments): string
    {
        return Process::path($root)->run(['git', ...$arguments])->throw()->output();
    }
}
