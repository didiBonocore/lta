<?php

declare(strict_types=1);

namespace App\Analysis\Reporting;

use App\Models\Snapshot;
use App\Models\TestObservation;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use MathPHP\Statistics\Average;

/**
 * The two sampling rules every consumer of the dataset
 * must share, so a chart can never silently disagree with the paper's tables —
 * plus the per-repository summary statistics the comparisons are run over
 * ("comparisons use a per-repository summary statistic per group").
 */
final class DatasetQueries
{
    /**
     * Minimum observations per aggregation cell (per checkpoint, or per window side).
     * "Tests are omitted where either group falls below five observations."
     */
    public const int OBSERVATION_FLOOR = 5;

    public const array METRICS = [
        'test_assertion_count',
        'mock_assertion_count',
        'total_assertion_count',
        'mock_assertion_ratio',
        'mock_breadth',
        'max_mock_chain_depth',
        'size_statements',
        'size_loc',
    ];

    /**
     * Instrument A sample: every observation of a version-boundary snapshot, with the
     * snapshot's integer major exposed as `major` (state per checkpoint).
     *
     * @return Collection<int, TestObservation>
     */
    public static function versionBoundaryObservations(): Collection
    {
        return TestObservation::query()
            ->join('snapshots', 'snapshots.id', '=', 'test_observations.snapshot_id')
            ->where('snapshots.kind', 'version_boundary')
            ->select(['test_observations.*', 'snapshots.framework_version as major'])
            ->orderBy('snapshots.framework_version')
            ->orderBy('test_observations.file_path')
            ->orderBy('test_observations.identifier')
            ->get();
    }

    /**
     * Instrument B sample: one observation per authored test method — the earliest
     * snapshot the method appears in, i.e. the state closest to how it was written.
     * Only blamed methods (introduced_author_date set) qualify.
     *
     * @return Collection<int, TestObservation>
     */
    public static function onePerAuthoredMethod(): Collection
    {
        return TestObservation::query()
            ->leftJoin('snapshots', 'snapshots.id', '=', 'test_observations.snapshot_id')
            ->whereNotNull('test_observations.introduced_author_date')
            ->select(['test_observations.*', 'snapshots.framework_version as major'])
            ->orderByRaw('snapshots.framework_version IS NULL, snapshots.framework_version')
            ->orderBy('test_observations.file_path')
            ->orderBy('test_observations.identifier')
            ->get()
            ->unique(fn (TestObservation $o): string => "{$o->repository_id}|{$o->front_end}|{$o->file_path}|{$o->identifier}")
            ->values();
    }

    /**
     * Instrument A, per-repository summary: the median of $metric over version-boundary
     * observations per (repository, major) — one point of each repository's trend series.
     * Cells below OBSERVATION_FLOOR observations are dropped; the drop count is exposed
     * through $droppedCells for attrition reporting. $frontEnd optionally restricts to
     * `phpunit`/`pest` (paradigm splits).
     *
     * @param-out int $droppedCells
     *
     * @return Collection<int, array{repository_id: int, major: int, n: int, value: float}>
     */
    public static function repositoryMajorMedians(string $metric, ?string $frontEnd = null, ?int &$droppedCells = null): Collection
    {
        $observations = self::versionBoundaryObservations()
            ->when($frontEnd !== null, fn (Collection $all): Collection => $all
                ->filter(fn (TestObservation $o): bool => $o->front_end === $frontEnd));

        $droppedCells = 0;

        return $observations
            ->groupBy(fn (TestObservation $o): string => "{$o->repository_id}|{$o->major}")
            ->map(function (Collection $cell) use ($metric, &$droppedCells): ?array {
                if ($cell->count() < self::OBSERVATION_FLOOR) {
                    $droppedCells++;

                    return null;
                }

                /** @var TestObservation $first */
                $first = $cell->first();

                return [
                    'repository_id' => (int) $first->repository_id,
                    'major' => (int) $first->major,
                    'n' => $cell->count(),
                    'value' => (float) Average::median($cell->pluck($metric)->map(fn ($v): float => (float) $v)->all()),
                ];
            })
            ->filter()
            ->sortBy([['repository_id', 'asc'], ['major', 'asc']])
            ->values();
    }

    /**
     * Instrument B, per-repository paired windows: over one observation per authored
     * method, bucket by introduced_author_date against $cutoff, then take the
     * per-repository median of $metric per window. A repository contributes a pair only if
     * both windows hold at least OBSERVATION_FLOOR methods; repositories excluded by the
     * floor are exposed through $excluded for the attrition reporting the internal-validity
     * section commits to. $frontEnd optionally restricts to `phpunit`/`pest`.
     *
     * @param  Collection<int, array{repository_id: int, n_pre: int, n_post: int}>|null  $excluded
     *
     * @param-out Collection<int, array{repository_id: int, n_pre: int, n_post: int}> $excluded
     *
     * @return Collection<int, array{repository_id: int, pre: float, post: float, n_pre: int, n_post: int}>
     */
    public static function repositoryWindowMedians(string $metric, CarbonInterface $cutoff, ?string $frontEnd = null, ?Collection &$excluded = null): Collection
    {
        $methods = self::onePerAuthoredMethod()
            ->when($frontEnd !== null, fn (Collection $all): Collection => $all
                ->filter(fn (TestObservation $o): bool => $o->front_end === $frontEnd));

        $excluded = collect();

        return $methods
            ->groupBy('repository_id')
            ->map(function (Collection $repository) use ($metric, $cutoff, $excluded): ?array {
                [$pre, $post] = $repository->partition(
                    fn (TestObservation $o): bool => $o->introduced_author_date->lessThan($cutoff),
                );

                /** @var TestObservation $first */
                $first = $repository->first();
                if ($pre->count() < self::OBSERVATION_FLOOR || $post->count() < self::OBSERVATION_FLOOR) {
                    $excluded->push([
                        'repository_id' => (int) $first->repository_id,
                        'n_pre' => $pre->count(),
                        'n_post' => $post->count(),
                    ]);

                    return null;
                }

                return [
                    'repository_id' => (int) $first->repository_id,
                    'pre' => (float) Average::median($pre->pluck($metric)->map(fn ($v): float => (float) $v)->all()),
                    'post' => (float) Average::median($post->pluck($metric)->map(fn ($v): float => (float) $v)->all()),
                    'n_pre' => $pre->count(),
                    'n_post' => $post->count(),
                ];
            })
            ->filter()
            ->sortBy('repository_id')
            ->values();
    }

    /**
     * Instrument B, per-repository agent-trace pairs (Aa, H3b): the window-pair shape,
     * bucketed on agent_authored instead of the cutoff — untraced (false) vs traced (true).
     * Methods with agent_authored null (not yet blamed for agent traces) are outside the
     * sample: absent evidence, not evidence of absence. Same OBSERVATION_FLOOR per side;
     * floor-excluded repositories are exposed through $excluded.
     *
     * @param  Collection<int, array{repository_id: int, n_untraced: int, n_traced: int}>|null  $excluded
     *
     * @param-out Collection<int, array{repository_id: int, n_untraced: int, n_traced: int}> $excluded
     *
     * @return Collection<int, array{repository_id: int, untraced: float, traced: float, n_untraced: int, n_traced: int}>
     */
    public static function repositoryAgentPairs(string $metric, ?Collection &$excluded = null): Collection
    {
        $methods = self::onePerAuthoredMethod()
            ->filter(fn (TestObservation $o): bool => $o->agent_authored !== null);

        $excluded = collect();

        return $methods
            ->groupBy('repository_id')
            ->map(function (Collection $repository) use ($metric, $excluded): ?array {
                [$untraced, $traced] = $repository->partition(
                    fn (TestObservation $o): bool => $o->agent_authored === false,
                );

                /** @var TestObservation $first */
                $first = $repository->first();
                if ($untraced->count() < self::OBSERVATION_FLOOR || $traced->count() < self::OBSERVATION_FLOOR) {
                    $excluded->push([
                        'repository_id' => (int) $first->repository_id,
                        'n_untraced' => $untraced->count(),
                        'n_traced' => $traced->count(),
                    ]);

                    return null;
                }

                return [
                    'repository_id' => (int) $first->repository_id,
                    'untraced' => (float) Average::median($untraced->pluck($metric)->map(fn ($v): float => (float) $v)->all()),
                    'traced' => (float) Average::median($traced->pluck($metric)->map(fn ($v): float => (float) $v)->all()),
                    'n_untraced' => $untraced->count(),
                    'n_traced' => $traced->count(),
                ];
            })
            ->filter()
            ->sortBy('repository_id')
            ->values();
    }

    /**
     * Pf, Appendix B: the paradigm of each (repository, major) checkpoint over
     * version-boundary observations — 0 = PHPUnit only, 1 = mixed, 2 = Pest only,
     * measured on distinct routed test files. A checkpoint holding no PHPUnit or Pest
     * suite has NO Pf value (Appendix B's undefined clause) and yields no row here; the
     * count of such checkpoints is exposed through $undefinedCheckpoints so they can be
     * "reported as such". Raw file counts ride along for the descriptive table; the
     * declared — and therefore tested — variable is the categorical pf.
     *
     * @param-out int $undefinedCheckpoints
     *
     * @return Collection<int, array{repository_id: int, major: int, pf: int, pest_files: int, total_files: int}>
     */
    public static function paradigmByCheckpoint(?int &$undefinedCheckpoints = null): Collection
    {
        $checkpoints = self::versionBoundaryObservations()
            ->groupBy(fn (TestObservation $o): string => "{$o->repository_id}|{$o->major}")
            ->map(function (Collection $cell): array {
                /** @var TestObservation $first */
                $first = $cell->first();
                $pestFiles = $cell->where('front_end', 'pest')->pluck('file_path')->unique()->count();
                $totalFiles = $cell->pluck('file_path')->unique()->count();

                return [
                    'repository_id' => (int) $first->repository_id,
                    'major' => (int) $first->major,
                    'pf' => match (true) {
                        $pestFiles === 0 => 0,
                        $pestFiles === $totalFiles => 2,
                        default => 1,
                    },
                    'pest_files' => $pestFiles,
                    'total_files' => $totalFiles,
                ];
            })
            ->sortBy([['repository_id', 'asc'], ['major', 'asc']])
            ->values();

        $undefinedCheckpoints = Snapshot::query()->where('kind', 'version_boundary')->count()
            - $checkpoints->count();

        return $checkpoints;
    }
}
