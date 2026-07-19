<?php

declare(strict_types=1);

use App\Models\Repository;
use App\Models\Snapshot;
use App\Models\TestObservation;
use Illuminate\Support\Facades\Artisan;

/**
 * Seeds a hand-computed dataset:
 *   Laravel 9 snapshot: assertion counts [1,2,3] (authored pre-AI, 2021)
 *   Laravel 10 snapshot: assertion counts [3,4,5] (authored post-AI, 2023)
 * Known answers — per-major means 2.00 / 4.00; trend slope 2.000, r² 0.600;
 * Mann-Whitney U = 0.5; Cliff's delta = -8/9 = -0.889 (large).
 */
beforeEach(function () {
    $repository = Repository::create([
        'full_name' => 'acme/hand',
        'owner' => 'acme',
        'name' => 'hand',
        'url' => 'https://github.com/acme/hand.git',
    ]);

    $nine = Snapshot::create([
        'repository_id' => $repository->id,
        'commit_sha' => 'aaa',
        'framework_version' => 9,
        'kind' => 'version_boundary',
    ]);
    $ten = Snapshot::create([
        'repository_id' => $repository->id,
        'commit_sha' => 'bbb',
        'framework_version' => 10,
        'kind' => 'version_boundary',
    ]);

    $seed = function (Snapshot $snapshot, array $assertionCounts, string $authoredAt) use ($repository): void {
        foreach ($assertionCounts as $i => $count) {
            TestObservation::create([
                'snapshot_id' => $snapshot->id,
                'repository_id' => $repository->id,
                'file_path' => 'tests/ExampleTest.php',
                'identifier' => "test_{$snapshot->framework_version}_{$i}",
                'front_end' => 'phpunit',
                'test_type' => 'unit',
                'assertion_count' => $count,
                'introduced_commit_sha' => 'ccc',
                'introduced_author_date' => $authoredAt,
                'ai_window' => $authoredAt < '2022-06-21' ? 'pre' : 'post',
            ]);
        }
    };
    $seed($nine, [1, 2, 3], '2021-03-01');
    $seed($ten, [3, 4, 5], '2023-03-01');
});

it('reports per-major descriptives with the least-squares trend on framework major', function () {
    $this->artisan('analyse:report', ['--metric' => 'assertion_count'])
        ->expectsOutputToContain('assertion_count = 2.000 × major -16.000   (r² = 0.600, n = 6)')
        ->assertSuccessful();
});

it('reports the pre/post-AI comparison with hand-computed U and delta', function () {
    // Table cells: n=3/3, medians 2.00/4.00, U=0.5, delta=-0.889 large.
    $this->withoutMockingConsoleOutput();
    $this->artisan('analyse:report', ['--metric' => 'assertion_count']);
    $output = Artisan::output();

    expect($output)->toContain('cutoff 2022-11-30')
        ->toContain('0.5')
        ->toContain('-0.889')
        ->toContain('large')
        ->toContain('2.00')
        ->toContain('4.00');
});

it('re-buckets against an overridden cutoff without re-blaming', function () {
    $this->withoutMockingConsoleOutput();
    $this->artisan('analyse:report', ['--metric' => 'assertion_count', '--cutoff' => '2022-06-21']);
    $output = Artisan::output();

    expect($output)->toContain('cutoff 2022-06-21')
        ->toContain('-0.889'); // same split under both anchors in this dataset
});

it('declines the comparison when a cutoff empties one group', function () {
    $this->withoutMockingConsoleOutput();
    $this->artisan('analyse:report', ['--metric' => 'assertion_count', '--cutoff' => '2031-01-01']);
    $output = Artisan::output();

    expect($output)->toContain('Insufficient data')
        ->toContain('pre n=6, post n=0');
});

it('rejects an unknown metric', function () {
    $this->artisan('analyse:report', ['--metric' => 'nope'])->assertFailed();
});
