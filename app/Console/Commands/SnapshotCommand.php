<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Analysis\Versioning\LaravelMajorResolver;
use App\Models\ParseFailure;
use App\Models\Repository;
use App\Models\Snapshot;
use App\Models\TestObservation;
use App\Models\UnroutableFile;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;

/**
 * Stage 1 / Instrument A — reconstruct integer-major Laravel checkpoints from composer.json
 * history along the trunk: `git log --first-parent --follow --reverse --format='%H %aI'
 * -- composer.json`; at each touching commit read composer.json via `git show`, resolve the
 * laravel/framework (fallback illuminate/support) constraint to an integer major. The
 * representative commit per major is the LAST commit whose constraint still resolves to that
 * major — the mature state before the upgrade. Locked decision: integer majors only; 5.x
 * sub-splitting is a discussion-chapter sensitivity note, not code.
 *
 * The walk is constrained to first-parent because Instrument A characterises *state*: a
 * constraint declared on a feature or release branch is not a state the project's trunk was
 * ever in, and branch author dates carry no relationship to trunk order. On a merge commit
 * the tree is the post-merge state, so the constraint read there is the trunk value; its
 * author date is therefore the date the change LANDED on trunk, not the date it was written
 * — correct for Instrument A (state), while Instrument B attributes authorship separately
 * through blame. (Same sentence in Appendix D.)
 */
class SnapshotCommand extends Command
{
    protected $signature = 'analyse:snapshot {full_name : owner/repo}';

    protected $description = 'Mine composer.json trunk history to build version-boundary snapshots (integer majors)';

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
            $this->error('No composer.json commit on the first-parent line resolves to a Laravel major — cannot snapshot.');

            return self::FAILURE;
        }

        // Last touching commit per major = the mature state before the upgrade. An
        // oscillation (9 → 10 → 9 on trunk) is deliberately NOT special-cased: the last
        // trunk commit resolving to 9 then sits after the 10 period, and analyse:verify
        // reports it — a real property of the project, unlike a branch artefact.
        $representatives = [];
        foreach ($touches as $touch) {
            $representatives[$touch['major']] = $touch;
        }
        ksort($representatives);

        // Pathspec simplification guard: the constraint the walk parsed must equal the
        // constraint in the commit's tree. If they disagree, the traversal is wrong and no
        // downstream correctness rescues it.
        foreach ($representatives as $major => $touch) {
            $inTree = $this->constraintAt($root, $touch['sha']);
            if ($inTree !== $touch['constraint']) {
                $this->error(sprintf(
                    'Traversal mismatch at %s (major %d): walk parsed "%s" but the tree holds "%s".',
                    $touch['sha'],
                    $major,
                    $touch['constraint'],
                    $inTree ?? '(missing)',
                ));

                return self::FAILURE;
            }
        }

        $created = [];
        foreach ($representatives as $major => $touch) {
            Snapshot::updateOrCreate(
                ['repository_id' => $repository->id, 'framework_version' => $major],
                [
                    'commit_sha' => $touch['sha'],
                    'kind' => 'version_boundary',
                    'commit_date' => $touch['date'],
                    'first_parent_index' => $touch['index'],
                ],
            );
            $created[] = [$major, substr($touch['sha'], 0, 12), $touch['date'], $touch['index']];
        }

        $this->pruneStaleMajors($repository, array_keys($representatives));

        $this->info(sprintf('%d version-boundary snapshots for %s.', count($created), $repository->full_name));
        $this->table(['Laravel major', 'representative commit', 'author date', 'trunk index'], $created);

        return self::SUCCESS;
    }

    /**
     * Every composer.json-touching commit on the first-parent line that resolves to a
     * Laravel major, oldest first. The index is the commit's position in that walk, recorded
     * so major ordering can be checked against trunk position rather than author date.
     *
     * @return list<array{sha: string, date: string, major: int, constraint: string, index: int}>
     */
    private function composerTouches(string $root, LaravelMajorResolver $resolver): array
    {
        $log = Process::path($root)
            ->run(['git', 'log', '--first-parent', '--follow', '--reverse', '--format=%H %aI', '--', 'composer.json'])
            ->throw()
            ->output();

        $touches = [];
        $index = 0;
        foreach (array_filter(explode("\n", trim($log))) as $line) {
            [$sha, $date] = explode(' ', trim($line), 2);
            $walkIndex = $index++;

            $constraint = $this->constraintAt($root, $sha);
            if ($constraint === null) {
                $this->warn("Skipping {$sha}: composer.json absent or framework constraint missing.");

                continue;
            }

            $major = $resolver->resolve($constraint);
            if ($major === null) {
                $this->warn("Skipping {$sha}: framework constraint \"{$constraint}\" unparseable.");

                continue;
            }

            $touches[] = [
                'sha' => $sha,
                'date' => $date,
                'major' => $major,
                'constraint' => $constraint,
                'index' => $walkIndex,
            ];
        }

        return $touches;
    }

    /**
     * The raw laravel/framework (fallback illuminate/support) constraint in composer.json at
     * a commit, read from the tree via `git show`; null where the file or constraint is absent.
     */
    private function constraintAt(string $root, string $sha): ?string
    {
        $shown = Process::path($root)->run(['git', 'show', "{$sha}:composer.json"]);
        if (! $shown->successful()) {
            return null; // composer.json absent at this commit (e.g. the rename source)
        }

        $composer = json_decode($shown->output(), true);
        if (! is_array($composer) || ! is_array($composer['require'] ?? null)) {
            return null;
        }

        $constraint = $composer['require']['laravel/framework']
            ?? $composer['require']['illuminate/support']
            ?? null;

        return is_string($constraint) ? $constraint : null;
    }

    /**
     * A re-snapshot may select a different set of majors: one whose constraint only ever
     * existed off-trunk disappears, and leaving its snapshot behind would orphan the
     * observations extracted from a commit the trunk never contained. Delete such snapshots
     * with their dependents so analyse:verify's orphan check passes without a manual reset.
     *
     * @param  list<int>  $keptMajors
     */
    private function pruneStaleMajors(Repository $repository, array $keptMajors): void
    {
        $stale = $repository->snapshots()
            ->where('kind', 'version_boundary')
            ->whereNotIn('framework_version', $keptMajors)
            ->get();

        foreach ($stale as $snapshot) {
            TestObservation::where('snapshot_id', $snapshot->id)->delete();
            ParseFailure::where('snapshot_id', $snapshot->id)->delete();
            UnroutableFile::where('snapshot_id', $snapshot->id)->delete();
            $snapshot->delete();

            $this->warn(sprintf(
                'Pruned major %d (%s): its constraint no longer exists on the first-parent line.',
                $snapshot->framework_version,
                substr((string) $snapshot->commit_sha, 0, 12),
            ));
        }
    }
}
