<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Analysis\Versioning\TrunkMajorWalk;
use App\Models\ParseFailure;
use App\Models\Repository;
use App\Models\Snapshot;
use App\Models\TestObservation;
use App\Models\UnroutableFile;
use Illuminate\Console\Command;

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

    public function handle(TrunkMajorWalk $walk): int
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

        // Last touching commit per major = the mature state before the upgrade. An
        // oscillation (9 → 10 → 9 on trunk) is deliberately NOT special-cased: the last
        // trunk commit resolving to 9 then sits after the 10 period, and analyse:verify
        // reports it — a real property of the project, unlike a branch artefact.
        // TrunkMajorWalk is shared with analyse:screen so the corpus is defined and
        // measured by one rule.
        $representatives = $walk->representatives($root, fn (string $reason) => $this->warn($reason));
        if ($representatives === []) {
            $this->error('No composer.json commit on the first-parent line resolves to a Laravel major — cannot snapshot.');

            return self::FAILURE;
        }

        // Pathspec simplification guard: the constraint the walk parsed must equal the
        // constraint in the commit's tree. If they disagree, the traversal is wrong and no
        // downstream correctness rescues it.
        foreach ($representatives as $major => $touch) {
            $inTree = $walk->constraintAt($root, $touch['sha']);
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
