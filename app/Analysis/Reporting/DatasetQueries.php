<?php

declare(strict_types=1);

namespace App\Analysis\Reporting;

use App\Models\TestObservation;
use Illuminate\Support\Collection;

/**
 * The two sampling rules every consumer of the dataset
 * must share, so a chart can never silently disagree with the paper's tables.
 */
final class DatasetQueries
{
    public const array METRICS = [
        'assertion_count',
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
            ->select('test_observations.*', 'snapshots.framework_version as major')
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
            ->select('test_observations.*', 'snapshots.framework_version as major')
            ->orderByRaw('snapshots.framework_version IS NULL, snapshots.framework_version')
            ->get()
            ->unique(fn (TestObservation $o): string => "{$o->repository_id}|{$o->front_end}|{$o->file_path}|{$o->identifier}")
            ->values();
    }
}
