<?php

declare(strict_types=1);

use App\Analysis\Reporting\DatasetQueries;
use App\Models\Repository;
use App\Models\Snapshot;
use App\Models\TestObservation;
use Illuminate\Support\Carbon;

/**
 * Known-answer tests for the per-repository aggregation layer, hand-computed over a seeded
 * dataset (the same posture as the statistics tests).
 */
function seedRepository(string $fullName): Repository
{
    [$owner, $name] = explode('/', $fullName);

    return Repository::create([
        'full_name' => $fullName,
        'owner' => $owner,
        'name' => $name,
        'url' => "https://github.com/{$fullName}.git",
    ]);
}

function seedSnapshot(Repository $repository, int $major): Snapshot
{
    return Snapshot::create([
        'repository_id' => $repository->id,
        'commit_sha' => "sha-{$repository->id}-{$major}",
        'framework_version' => $major,
        'kind' => 'version_boundary',
    ]);
}

/** @param list<int|float> $values */
function seedObservations(Repository $repository, Snapshot $snapshot, array $values, string $frontEnd = 'phpunit', ?string $authoredAt = null): void
{
    foreach ($values as $i => $value) {
        TestObservation::create([
            'snapshot_id' => $snapshot->id,
            'repository_id' => $repository->id,
            'file_path' => "tests/{$frontEnd}Example{$i}Test.php",
            'identifier' => "test_{$snapshot->id}_{$frontEnd}_{$i}",
            'front_end' => $frontEnd,
            'test_type' => 'unit',
            'test_assertion_count' => (int) $value,
            'total_assertion_count' => (int) $value,
            'introduced_commit_sha' => $authoredAt === null ? null : 'abc',
            'introduced_author_date' => $authoredAt,
            'ai_window' => $authoredAt === null ? null : ($authoredAt < '2022-06-21' ? 'pre' : 'post'),
        ]);
    }
}

it('computes per-repository per-major medians and drops cells under the floor', function () {
    $repository = seedRepository('acme/alpha');
    // major 9: [1,2,3,4,5] => median 3; major 10: [2,2,4,6,6,8] => median 5;
    // major 11: only 4 observations => dropped.
    seedObservations($repository, seedSnapshot($repository, 9), [1, 2, 3, 4, 5]);
    seedObservations($repository, seedSnapshot($repository, 10), [2, 2, 4, 6, 6, 8]);
    seedObservations($repository, seedSnapshot($repository, 11), [9, 9, 9, 9]);

    $series = DatasetQueries::repositoryMajorMedians('test_assertion_count', null, $dropped);

    expect($series->all())->toBe([
        ['repository_id' => $repository->id, 'major' => 9, 'n' => 5, 'value' => 3.0],
        ['repository_id' => $repository->id, 'major' => 10, 'n' => 6, 'value' => 5.0],
    ])->and($dropped)->toBe(1);
});

it('restricts the per-major series by front end, re-applying the floor per slice', function () {
    $repository = seedRepository('acme/alpha');
    $nine = seedSnapshot($repository, 9);
    // major 9 holds 5 phpunit ([1,1,3,5,5] => median 3) and 3 pest observations; the pest
    // slice falls under the floor and is dropped.
    seedObservations($repository, $nine, [1, 1, 3, 5, 5], 'phpunit');
    seedObservations($repository, $nine, [7, 8, 9], 'pest');

    expect(DatasetQueries::repositoryMajorMedians('test_assertion_count', 'phpunit')->all())->toBe([
        ['repository_id' => $repository->id, 'major' => 9, 'n' => 5, 'value' => 3.0],
    ]);

    $pest = DatasetQueries::repositoryMajorMedians('test_assertion_count', 'pest', $dropped);
    expect($pest->isEmpty())->toBeTrue()->and($dropped)->toBe(1);
});

it('pairs per-repository window medians and exposes floor-excluded repositories', function () {
    $cutoff = Carbon::parse('2022-06-21');

    // alpha: 5 pre [1,1,2,3,5] => median 2; 5 post [2,4,4,6,8] => median 4.
    $alpha = seedRepository('acme/alpha');
    $snapshot = seedSnapshot($alpha, 10);
    seedObservations($alpha, $snapshot, [1, 1, 2, 3, 5], 'phpunit', '2021-01-01');
    seedObservations($alpha, $snapshot, [2, 4, 4, 6, 8], 'pest', '2023-01-01');

    // beta: 5 pre but only 4 post => excluded with its counts.
    $beta = seedRepository('acme/beta');
    $betaSnapshot = seedSnapshot($beta, 10);
    seedObservations($beta, $betaSnapshot, [1, 2, 3, 4, 5], 'phpunit', '2021-01-01');
    seedObservations($beta, $betaSnapshot, [1, 2, 3, 4], 'pest', '2023-01-01');

    $pairs = DatasetQueries::repositoryWindowMedians('test_assertion_count', $cutoff, null, $excluded);

    expect($pairs->all())->toBe([
        ['repository_id' => $alpha->id, 'pre' => 2.0, 'post' => 4.0, 'n_pre' => 5, 'n_post' => 5],
    ])->and($excluded->all())->toBe([
        ['repository_id' => $beta->id, 'n_pre' => 5, 'n_post' => 4],
    ]);

    // The pest slice of alpha has no pre-window methods at all => excluded there too.
    $pestPairs = DatasetQueries::repositoryWindowMedians('test_assertion_count', $cutoff, 'pest', $pestExcluded);
    expect($pestPairs->isEmpty())->toBeTrue()
        ->and($pestExcluded->first())->toBe(['repository_id' => $alpha->id, 'n_pre' => 0, 'n_post' => 5]);
});

it('reduces each checkpoint to Appendix B\'s categorical Pf and counts undefined checkpoints', function () {
    // alpha major 9: phpunit only => Pf 0. alpha major 10: 2 pest files of 4 => Pf 1 (mixed).
    $alpha = seedRepository('acme/alpha');
    seedObservations($alpha, seedSnapshot($alpha, 9), [1, 2, 3]);
    $ten = seedSnapshot($alpha, 10);
    seedObservations($alpha, $ten, [1, 2], 'phpunit');
    seedObservations($alpha, $ten, [1, 2], 'pest');

    // beta major 9: pest only => Pf 2. beta major 10: no routed files => Pf undefined
    // (no row — the pre-migration checkpoints "reported as such").
    $beta = seedRepository('acme/beta');
    seedObservations($beta, seedSnapshot($beta, 9), [1], 'pest');
    seedSnapshot($beta, 10);

    $checkpoints = DatasetQueries::paradigmByCheckpoint($undefined);

    expect($checkpoints->all())->toBe([
        ['repository_id' => $alpha->id, 'major' => 9, 'pf' => 0, 'pest_files' => 0, 'total_files' => 3],
        ['repository_id' => $alpha->id, 'major' => 10, 'pf' => 1, 'pest_files' => 2, 'total_files' => 4],
        ['repository_id' => $beta->id, 'major' => 9, 'pf' => 2, 'pest_files' => 1, 'total_files' => 1],
    ])->and($undefined)->toBe(1);
});
