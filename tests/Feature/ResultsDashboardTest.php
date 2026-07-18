<?php

declare(strict_types=1);

use App\Livewire\Results\AiComparison;
use App\Livewire\Results\VersionTrends;
use App\Models\Repository;
use App\Models\Snapshot;
use App\Models\TestObservation;
use Livewire\Livewire;

/**
 * Same hand-computed dataset as ReportCommandTest, so the dashboard provably shows the
 * paper's numbers: majors 9/10 with assertion counts [1,2,3]/[3,4,5], authored pre/post-AI.
 */
beforeEach(function () {
    $repository = Repository::create([
        'full_name' => 'acme/hand',
        'owner' => 'acme',
        'name' => 'hand',
        'url' => 'https://github.com/acme/hand.git',
    ]);

    foreach ([[9, 'aaa', [1, 2, 3], '2021-03-01'], [10, 'bbb', [3, 4, 5], '2023-03-01']] as [$major, $sha, $counts, $authoredAt]) {
        $snapshot = Snapshot::create([
            'repository_id' => $repository->id,
            'commit_sha' => $sha,
            'framework_version' => $major,
            'kind' => 'version_boundary',
        ]);
        foreach ($counts as $i => $count) {
            TestObservation::create([
                'snapshot_id' => $snapshot->id,
                'repository_id' => $repository->id,
                'file_path' => 'tests/ExampleTest.php',
                'identifier' => "test_{$major}_{$i}",
                'front_end' => 'phpunit',
                'test_type' => 'unit',
                'assertion_count' => $count,
                'introduced_commit_sha' => 'ccc',
                'introduced_author_date' => $authoredAt,
                'ai_window' => $authoredAt < '2022-06-21' ? 'pre' : 'post',
            ]);
        }
    }
});

it('serves the read-only results page with both components', function () {
    $this->get('/results')
        ->assertOk()
        ->assertSeeLivewire(VersionTrends::class)
        ->assertSeeLivewire(AiComparison::class);
});

it('shows per-major means and the trend for the selected metric', function () {
    Livewire::test(VersionTrends::class)
        ->assertSee('2.00')   // major 9 mean
        ->assertSee('4.00')   // major 10 mean
        ->assertSee('r² = 0.600')
        ->set('metric', 'size_loc')
        ->assertSee('size loc');
});

it('falls back to a known metric when tampered with', function () {
    Livewire::test(VersionTrends::class)
        ->set('metric', 'evil_column')
        ->assertSet('metric', 'assertion_count');
});

it('shows the pre/post comparison and switches cutoff anchors', function () {
    Livewire::test(AiComparison::class)
        ->assertSee('2022-06-21')
        ->assertSee('pre n=3, post n=3')
        ->assertSee('-0.889')
        ->assertSee('large')
        ->set('anchor', 'sensitivity')
        ->assertSee('2022-11-30');
});
