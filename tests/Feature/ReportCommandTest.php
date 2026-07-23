<?php

declare(strict_types=1);

use App\Models\Repository;
use App\Models\Snapshot;
use App\Models\TestObservation;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

/**
 * Seeds a hand-computed dataset (n = 5 per group, above the statistical floor):
 *   Laravel 9: test assertion counts [1,1,2,2,3], 3 unit + 2 feature, authored pre-AI (2021)
 *   Laravel 10: test assertion counts [3,3,4,4,5], all feature, authored post-AI (2023)
 * Known answers — means 1.80/3.80, medians 2.00/4.00, sd 0.84, IQR 1.50 (MathPHP
 * exclusive quartiles); OLS slope 2.000, intercept -16.200, r² 0.641 (SSres 5.6 /
 * SStot 15.6); Mann-Whitney U = 1.0 (R1 = 16); Cliff's delta = -23/25 = -0.920 (large);
 * type split 60/40 vs 0/100.
 */
beforeEach(function () {
    $repository = Repository::create([
        'full_name' => 'acme/hand',
        'owner' => 'acme',
        'name' => 'hand',
        'url' => 'https://github.com/acme/hand.git',
    ]);

    $seed = [
        [9, 'aaa', [1, 1, 2, 2, 3], ['unit', 'unit', 'unit', 'feature', 'feature'], '2021-03-01'],
        [10, 'bbb', [3, 3, 4, 4, 5], ['feature', 'feature', 'feature', 'feature', 'feature'], '2023-03-01'],
    ];
    foreach ($seed as [$major, $sha, $counts, $types, $authoredAt]) {
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
                'test_type' => $types[$i],
                'test_assertion_count' => $count,
                'mock_assertion_count' => 0,
                'total_assertion_count' => $count,
                'mock_assertion_ratio' => 0.0,
                'introduced_commit_sha' => 'ccc',
                'introduced_author_date' => $authoredAt,
                'ai_window' => $authoredAt < '2022-06-21' ? 'pre' : 'post',
            ]);
        }
    }
});

function reportOutput(array $options = []): string
{
    test()->withoutMockingConsoleOutput();
    test()->artisan('analyse:report', $options + ['--metric' => 'test_assertion_count']);

    return Artisan::output();
}

it('prints per-major descriptives with IQR and the least-squares trend', function () {
    $output = reportOutput();

    expect($output)
        ->toContain('1.80')  // mean v9
        ->toContain('3.80')  // mean v10
        ->toContain('0.84')  // sample sd
        ->toContain('1.50')  // IQR
        ->toContain('test_assertion_count = 2.000 × major -16.200   (r² = 0.641, n = 10)');
});

it('prints the pre/post-AI comparison with hand-computed U and delta', function () {
    $output = reportOutput();

    expect($output)->toContain('cutoff 2022-06-21')
        ->toContain('1.0')      // U
        ->toContain('-0.920')   // Cliff's delta
        ->toContain('large')
        ->toContain('2.00')     // median pre
        ->toContain('4.00');    // median post
});

it('prints test-type distributions per version and per AI window as percentages', function () {
    $output = reportOutput();

    expect($output)->toContain('Test-type distribution')
        ->toContain('60.0')     // v9 / pre window: unit share
        ->toContain('40.0')     // v9 / pre window: feature share
        ->toContain('100.0');   // v10 / post window: feature share
});

it('heads the report with the git-resolved tool version and cutoff', function () {
    Process::preventStrayProcesses();
    Process::fake(['*describe*' => Process::result("v0.2.0-2-gabc1234\n")]);

    $output = reportOutput();

    expect($output)->toContain('lta v0.2.0-2-gabc1234')
        ->toContain('cutoff 2022-06-21');
});

it('re-buckets against an overridden cutoff without re-blaming', function () {
    $output = reportOutput(['--cutoff' => '2022-06-21']);

    expect($output)->toContain('cutoff 2022-06-21')
        ->toContain('-0.920'); // same split under both anchors in this dataset
});

it('refuses the comparison with a warning when a group is under the n=5 floor', function () {
    $output = reportOutput(['--cutoff' => '2031-01-01']);

    expect($output)->toContain('Refusing the pre/post comparison')
        ->toContain('n=5 floor (pre n=10, post n=0)')
        // Descriptives and distributions still print — only the tests refuse.
        ->toContain('test_assertion_count = 2.000 × major');
});

it('exports every block as a cleanly parseable CSV', function () {
    Process::preventStrayProcesses();
    Process::fake(['*describe*' => Process::result("v0.2.0-2-gabc1234\n")]);

    $base = base_path('storage/framework/testing/report.csv');
    File::ensureDirectoryExists(dirname($base));

    reportOutput(['--export' => $base]);

    $stem = base_path('storage/framework/testing/report');
    $blocks = ['provenance', 'descriptives', 'regression', 'ai_comparison', 'types_by_version', 'types_by_window'];

    foreach ($blocks as $block) {
        expect(file_exists("{$stem}_{$block}.csv"))->toBeTrue("missing {$block} export");

        $lines = array_values(array_filter(explode("\n", (string) file_get_contents("{$stem}_{$block}.csv"))));
        $width = count(str_getcsv($lines[0]));
        expect(count($lines))->toBeGreaterThan(1);
        foreach ($lines as $line) {
            expect(count(str_getcsv($line)))->toBe($width); // every row parses to the header width
        }
    }

    $regression = str_getcsv(explode("\n", (string) file_get_contents("{$stem}_regression.csv"))[1]);
    expect($regression[0])->toBe('test_assertion_count')
        ->and((float) $regression[1])->toEqualWithDelta(2.0, 1e-6)
        ->and((float) $regression[3])->toEqualWithDelta(0.641, 1e-3);

    $comparison = str_getcsv(explode("\n", (string) file_get_contents("{$stem}_ai_comparison.csv"))[1]);
    expect($comparison[5])->toBe('1.0')      // U
        ->and($comparison[8])->toBe('-0.920'); // Cliff's delta

    $provenance = str_getcsv(explode("\n", (string) file_get_contents("{$stem}_provenance.csv"))[1]);
    expect($provenance[0])->toBe('v0.2.0-2-gabc1234') // tool_version
        ->and($provenance[1])->toBe('2022-06-21');    // ai_cutoff

    foreach ($blocks as $block) {
        File::delete("{$stem}_{$block}.csv");
    }
});

it('rejects an unknown metric', function () {
    /** @var \Tests\TestCase $this */
    $this->artisan('analyse:report', ['--metric' => 'nope'])->assertFailed();
});
