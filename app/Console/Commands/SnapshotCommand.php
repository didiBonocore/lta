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
 * history: `git log --follow --reverse --format='%H %aI' -- composer.json`; at each touching
 * commit read composer.json via `git show`, resolve the laravel/framework (fallback
 * illuminate/support) constraint to an integer major. The representative commit per major is
 * the LAST commit whose constraint still resolves to that major — the mature state before
 * the upgrade. Locked decision: integer majors only; 5.x sub-splitting is a discussion-
 * chapter sensitivity note, not code.
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

        // Last touching commit per major = the mature state before the upgrade.
        $representatives = [];
        foreach ($touches as $touch) {
            $representatives[$touch['major']] = $touch;
        }
        ksort($representatives);

        $created = [];
        foreach ($representatives as $major => $touch) {
            Snapshot::updateOrCreate(
                ['repository_id' => $repository->id, 'framework_version' => $major],
                ['commit_sha' => $touch['sha'], 'kind' => 'version_boundary', 'commit_date' => $touch['date']],
            );
            $created[] = [$major, substr($touch['sha'], 0, 12), $touch['date']];
        }

        $this->info(sprintf('%d version-boundary snapshots for %s.', count($created), $repository->full_name));
        $this->table(['Laravel major', 'representative commit', 'author date'], $created);

        return self::SUCCESS;
    }

    /**
     * Every composer.json-touching commit that resolves to a Laravel major, oldest first.
     *
     * @return list<array{sha: string, date: string, major: int}>
     */
    private function composerTouches(string $root, LaravelMajorResolver $resolver): array
    {
        $log = Process::path($root)
            ->run(['git', 'log', '--follow', '--reverse', '--format=%H %aI', '--', 'composer.json'])
            ->throw()
            ->output();

        $touches = [];
        foreach (array_filter(explode("\n", trim($log))) as $line) {
            [$sha, $date] = explode(' ', trim($line), 2);

            $shown = Process::path($root)->run(['git', 'show', "{$sha}:composer.json"]);
            if (! $shown->successful()) {
                continue; // composer.json absent at this commit (e.g. the rename source)
            }

            $composer = json_decode($shown->output(), true);
            $major = is_array($composer) ? $resolver->resolveFromComposer($composer) : null;

            if ($major === null) {
                $this->warn("Skipping {$sha}: framework constraint missing or unparseable.");

                continue;
            }

            $touches[] = ['sha' => $sha, 'date' => $date, 'major' => $major];
        }

        return $touches;
    }
}
