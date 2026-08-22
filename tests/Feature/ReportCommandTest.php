<?php

declare(strict_types=1);

use App\Models\Repository;
use App\Models\Snapshot;
use App\Models\TestObservation;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

function reportOutput(array $options = []): string
{
    test()->withoutMockingConsoleOutput();
    test()->artisan('analyse:report', $options + ['--metric' => 'test_assertion_count']);

    return Artisan::output();
}

function reportRepository(string $fullName): Repository
{
    [$owner, $name] = explode('/', $fullName);

    return Repository::create([
        'full_name' => $fullName,
        'owner' => $owner,
        'name' => $name,
        'url' => "https://github.com/{$fullName}.git",
    ]);
}

/**
 * Seed one (repository, major) checkpoint with constant-valued observations, blamed to a
 * fixed author date so Instrument B sees the same values.
 *
 * @param  array<string, int>  $filesPerFrontEnd  front_end => number of files/methods
 */
function reportCheckpoint(Repository $repository, int $major, int $value, array $filesPerFrontEnd, string $authoredAt): void
{
    $snapshot = Snapshot::create([
        'repository_id' => $repository->id,
        'commit_sha' => "sha-{$repository->id}-{$major}",
        'framework_version' => $major,
        'kind' => 'version_boundary',
    ]);

    foreach ($filesPerFrontEnd as $frontEnd => $files) {
        for ($i = 0; $i < $files; $i++) {
            TestObservation::create([
                'snapshot_id' => $snapshot->id,
                'repository_id' => $repository->id,
                'file_path' => "tests/{$frontEnd}/Example{$i}Test.php",
                'identifier' => "test_{$snapshot->id}_{$frontEnd}_{$i}",
                'front_end' => $frontEnd,
                'test_type' => 'unit',
                'test_assertion_count' => $value,
                'total_assertion_count' => $value,
                'introduced_commit_sha' => 'ccc',
                'introduced_author_date' => $authoredAt,
                'ai_window' => $authoredAt < '2022-06-21' ? 'pre' : 'post',
            ]);
        }
    }
}

describe('single-repository dataset', function () {
    /*
     * Hand-computed seed (n = 5 per group, above the observation floor):
     *   Laravel 9: test assertion counts [1,1,2,2,3], 3 unit + 2 feature, authored pre-AI (2021)
     *   Laravel 10: test assertion counts [3,3,4,4,5], all feature, authored post-AI (2023)
     * Known answers — means 1.80/3.80, medians 2.00/4.00, sd 0.84, IQR 1.50 (MathPHP
     * exclusive quartiles); descriptive OLS fit slope 2.000, intercept -16.200, r² 0.641
     * (SSres 5.6 / SStot 15.6); pooled Mann-Whitney U = 1.0 (R1 = 16); pooled Cliff's
     * delta = -23/25 = -0.920 (large); type split 60/40 vs 0/100. With a single
     * repository, every per-repository primary test refuses at the n=5 repository floor.
     */
    beforeEach(function () {
        $repository = reportRepository('acme/hand');

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

    it('prints per-major descriptives with IQR and the descriptive linear fit', function () {
        $output = reportOutput();

        expect($output)
            ->toContain('1.80')  // mean v9
            ->toContain('3.80')  // mean v10
            ->toContain('0.84')  // sample sd
            ->toContain('1.50')  // IQR
            ->toContain('descriptive linear fit (not a test of H2a): test_assertion_count = 2.000 × major -16.200   (r² = 0.641, n = 10)');
    });

    it('prints the pooled pre/post-AI comparison as secondary with hand-computed U and delta', function () {
        $output = reportOutput();

        expect($output)->toContain('cutoff 2022-06-21')
            ->toContain('secondary — pooled Mann-Whitney U')
            ->toContain('independence violated')
            ->toContain('1.0')      // U
            ->toContain('-0.920')   // pooled Cliff's delta
            ->toContain('large');
    });

    it('refuses every per-repository primary test below the repository floor', function () {
        $output = reportOutput();

        expect($output)->toContain('Refusing the trend test for test_assertion_count (all): only 1 repositories contribute series (floor 5)')
            ->toContain('Refusing the H1 paired test: only 1 repositories are observed both below and at/above major 10')
            ->toContain('Refusing the era comparison (all): only 1 repositories contribute pre/post pairs (floor 5)');
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

    it('refuses the pooled comparison with a warning when a group is under the n=5 floor', function () {
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
        $blocks = [
            'provenance', 'descriptives', 'descriptive_fit', 'trend', 'trend_repositories',
            'paradigm_by_major', 'ai_comparison', 'attrition', 'types_by_version',
            'types_by_window', 'multiplicity',
        ];

        foreach ($blocks as $block) {
            expect(file_exists("{$stem}_{$block}.csv"))->toBeTrue("missing {$block} export");

            $lines = array_values(array_filter(explode("\n", (string) file_get_contents("{$stem}_{$block}.csv"))));
            $width = count(str_getcsv($lines[0]));
            expect(count($lines))->toBeGreaterThan(1);
            foreach ($lines as $line) {
                expect(count(str_getcsv($line)))->toBe($width); // every row parses to the header width
            }
        }

        $fit = str_getcsv(explode("\n", (string) file_get_contents("{$stem}_descriptive_fit.csv"))[1]);
        expect($fit[0])->toBe('test_assertion_count')
            ->and((float) $fit[1])->toEqualWithDelta(2.0, 1e-6)
            ->and((float) $fit[3])->toEqualWithDelta(0.641, 1e-3);

        // Pooled (secondary) columns carry the hand-computed U and delta; the primary
        // paired columns are refused dashes with a single repository.
        $comparison = str_getcsv(explode("\n", (string) file_get_contents("{$stem}_ai_comparison.csv"))[1]);
        expect($comparison[0])->toBe('test_assertion_count')
            ->and($comparison[1])->toBe('all')
            ->and($comparison[2])->toBe('1')       // n_repositories
            ->and($comparison[6])->toBe('—')       // primary refused
            ->and($comparison[15])->toBe('1.0')    // pooled U
            ->and($comparison[18])->toBe('-0.920'); // pooled Cliff's delta

        $provenance = str_getcsv(explode("\n", (string) file_get_contents("{$stem}_provenance.csv"))[1]);
        expect($provenance[0])->toBe('v0.2.0-2-gabc1234') // tool_version
            ->and($provenance[1])->toBe('2022-06-21');    // ai_cutoff

        foreach ($blocks as $block) {
            File::delete("{$stem}_{$block}.csv");
        }
    });

    it('rejects an unknown metric', function () {
        /** @var TestCase $this */
        $this->artisan('analyse:report', ['--metric' => 'nope'])->assertFailed();
    });
});

describe('five-repository dataset', function () {
    /*
     * Hand-computed corpus above the repository floor. Five repositories, majors 9 and 10,
     * five constant-valued observations per checkpoint (test_assertion_count), major 9
     * authored 2021 (pre), major 10 authored 2023 (post):
     *
     *   repo   m9 → m10   slope   Pf m9 → m10
     *   r1     1  → 2       1      0 → 0   (phpunit only)
     *   r2     1  → 3       2      0 → 0   (phpunit only)
     *   r3     2  → 4       2      0 → 1   (m10: 3 phpunit + 2 pest)
     *   r4     2  → 5       3      0 → 2   (m10: pest only)
     *   r5     3  → 3       0      2 → 2   (pest only, never trends)
     *
     * Trend: slopes [1,2,2,3,0] => median 2, 4 positive, 0 negative; Wilcoxon vs zero
     * drops r5, |d| ranks [1,2.5,2.5,4] all positive => W = 0; exact p = P(min ≤ 0)
     * = 2/16 = 0.125; rank-biserial = 1. taus [1,1,1,1,0] => median 1. Cliff's delta
     * ends: first [1,1,2,2,3] vs last [2,3,4,5,3] => (1−20)/25 = −0.76 (large).
     * Spearman rhos [1,1,1,1,0] => median 1; aggregate Wilcoxon p = 0.125.
     *
     * Era (all): per-repository medians pre [1,1,2,2,3] (median 2.00) vs post
     * [2,3,4,5,3] (median 3.00); diffs pre−post drop r5 => W = 0, exact p = 0.125,
     * rank-biserial = −1; Cliff's delta over the median sets = −0.76 (large).
     *
     * H1: all 5 repositories span major 10. Mean Pf pre [0,0,0,0,2] vs post [0,0,1,2,2]:
     * 3 zero diffs dropped (never-adopters), ranks [1,2] both negative => W = 0, exact
     * p = P(min ≤ 0) = 2/4 = 0.5, rank-biserial = −1; Cliff's delta = (3−12)/25 = −0.36
     * (medium).
     */
    beforeEach(function () {
        $plan = [
            ['acme/r1', 1, 2, ['phpunit' => 5], ['phpunit' => 5]],
            ['acme/r2', 1, 3, ['phpunit' => 5], ['phpunit' => 5]],
            ['acme/r3', 2, 4, ['phpunit' => 5], ['phpunit' => 3, 'pest' => 2]],
            ['acme/r4', 2, 5, ['phpunit' => 5], ['pest' => 5]],
            ['acme/r5', 3, 3, ['pest' => 5], ['pest' => 5]],
        ];
        foreach ($plan as [$name, $nineValue, $tenValue, $nineFiles, $tenFiles]) {
            $repository = reportRepository($name);
            reportCheckpoint($repository, 9, $nineValue, $nineFiles, '2021-03-01');
            reportCheckpoint($repository, 10, $tenValue, $tenFiles, '2023-03-01');
        }
    });

    it('runs the primary trend test over per-repository Theil-Sen slopes', function () {
        Process::fake(['*describe*' => Process::result("v0.3.0\n")]);
        $base = base_path('storage/framework/testing/trend.csv');
        File::ensureDirectoryExists(dirname($base));

        reportOutput(['--export' => $base]);

        $trend = str_getcsv(explode("\n", (string) file_get_contents(base_path('storage/framework/testing/trend_trend.csv')))[1]);
        File::delete(base_path('storage/framework/testing/trend_trend.csv'));

        // header: metric, front_end, n_repositories, median_slope, median_tau,
        // n_positive_slopes, n_negative_slopes, wilcoxon_w, wilcoxon_p, wilcoxon_exact,
        // n_zero_slopes_dropped, rank_biserial, cliffs_delta_ends, magnitude,
        // spearman_median_rho, spearman_wilcoxon_p
        expect($trend)->toBe([
            'test_assertion_count', 'all', '5', '2.0000', '1.000', '4', '0',
            '0.0', '0.1250', 'yes', '1', '1.000', '-0.760', 'large', '1.000', '0.1250',
        ]);
    });

    it('runs the primary era test over per-repository window medians, split by paradigm', function () {
        Process::fake(['*describe*' => Process::result("v0.3.0\n")]);
        $base = base_path('storage/framework/testing/era.csv');
        File::ensureDirectoryExists(dirname($base));

        reportOutput(['--export' => $base]);

        $lines = array_values(array_filter(explode("\n", (string) file_get_contents(base_path('storage/framework/testing/era_ai_comparison.csv')))));
        File::delete(base_path('storage/framework/testing/era_ai_comparison.csv'));

        $all = str_getcsv($lines[1]);
        expect(array_slice($all, 0, 13))->toBe([
            'test_assertion_count', 'all', '5', '0', '2.00', '3.00',
            '0.0', '0.1250', 'yes', '1', '-1.000', '-0.760', 'large',
        ])->and($all[13])->toBe('25')  // pooled n pre
            ->and($all[14])->toBe('25'); // pooled n post

        // phpunit slice: only r1 and r2 hold >= 5 phpunit methods in both windows => the
        // paired test refuses; r3 (5 pre, 3 post) and r4 (5 pre, 0 post) are floor-excluded.
        $phpunit = str_getcsv($lines[2]);
        expect(array_slice($phpunit, 0, 4))->toBe(['test_assertion_count', 'phpunit', '2', '2'])
            ->and($phpunit[6])->toBe('—');

        // pest slice: only r5 pairs => refused as well.
        $pest = str_getcsv($lines[3]);
        expect(array_slice($pest, 0, 4))->toBe(['test_assertion_count', 'pest', '1', '2']);
    });

    it('reports H1 as descriptive Pf per major plus the boundary-spanning paired test', function () {
        Process::fake(['*describe*' => Process::result("v0.3.0\n")]);
        $base = base_path('storage/framework/testing/pf.csv');
        File::ensureDirectoryExists(dirname($base));

        $output = reportOutput(['--export' => $base]);

        $byMajor = array_values(array_filter(explode("\n", (string) file_get_contents(base_path('storage/framework/testing/pf_paradigm_by_major.csv')))));
        $test = str_getcsv(explode("\n", (string) file_get_contents(base_path('storage/framework/testing/pf_paradigm_test.csv')))[1]);
        File::delete(base_path('storage/framework/testing/pf_paradigm_by_major.csv'));
        File::delete(base_path('storage/framework/testing/pf_paradigm_test.csv'));

        // major, n_repositories, pf 0/1/2 counts, median pest file share.
        expect(str_getcsv($byMajor[1]))->toBe(['9', '5', '4', '0', '1', '0.000'])
            ->and(str_getcsv($byMajor[2]))->toBe(['10', '5', '2', '1', '2', '0.400']);

        // n_spanning, W, p, exact, zero-dropped, rank-biserial, Cliff's delta, magnitude.
        expect($test)->toBe(['5', '0.0', '0.5000', 'yes', '3', '-1.000', '-0.360', 'medium'])
            ->and($output)->toContain('3 zero difference(s) dropped (never-adopters)');
    });

    it('collects every produced p-value exactly once into the multiplicity block', function () {
        Process::fake(['*describe*' => Process::result("v0.3.0\n")]);
        $base = base_path('storage/framework/testing/multi.csv');
        File::ensureDirectoryExists(dirname($base));

        reportOutput(['--export' => $base]);

        $lines = array_values(array_filter(explode("\n", (string) file_get_contents(base_path('storage/framework/testing/multi_multiplicity.csv')))));
        File::delete(base_path('storage/framework/testing/multi_multiplicity.csv'));

        $rows = array_map(str_getcsv(...), array_slice($lines, 1));
        $labels = array_column($rows, 0);

        // Every produced p appears exactly once — no duplicates.
        expect($labels)->toBe(array_unique($labels));

        $byLabel = array_combine($labels, $rows);
        // H2b's pre-registered primary comparison passes through unadjusted: blank q.
        expect($byLabel['trend:test_assertion_count:all:wilcoxon_slopes'][1])->toBe('primary (H2b)')
            ->and($byLabel['trend:test_assertion_count:all:wilcoxon_slopes'][2])->toBe('0.1250')
            ->and($byLabel['trend:test_assertion_count:all:wilcoxon_slopes'][3])->toBe('')
            ->and($byLabel['h1:paradigm_pf:wilcoxon'][1])->toBe('primary (H1)')
            // The era Wilcoxon on this metric is exploratory (H3a's primary is mock_breadth)
            // and carries a Benjamini-Hochberg q.
            ->and($byLabel['era:test_assertion_count:all:wilcoxon'][1])->toBe('exploratory')
            ->and($byLabel['era:test_assertion_count:all:wilcoxon'][3])->not->toBe('');
    });
});

it('exits 0 with warnings, not exceptions, on an empty database', function () {
    test()->withoutMockingConsoleOutput();
    $exit = test()->artisan('analyse:report');

    expect($exit)->toBe(0)
        ->and(Artisan::output())->toContain('No version-boundary observations');
});
