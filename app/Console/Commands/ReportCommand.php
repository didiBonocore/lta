<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Analysis\Reporting\DatasetQueries;
use App\Analysis\Reporting\ToolVersion;
use App\Analysis\Statistics\BenjaminiHochberg;
use App\Analysis\Statistics\EffectSize;
use App\Analysis\Statistics\MannKendall;
use App\Analysis\Statistics\MannWhitney;
use App\Analysis\Statistics\SimpleLinearRegression;
use App\Analysis\Statistics\Spearman;
use App\Analysis\Statistics\TheilSen;
use App\Analysis\Statistics\WilcoxonSignedRank;
use App\Models\Repository;
use App\Models\TestObservation;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use MathPHP\Statistics\Average;
use MathPHP\Statistics\Descriptive;

/**
 * Stage 6 — analysis over the emitted dataset, implementing the declared statistical
 * design: comparisons use a per-repository summary statistic per group, non-parametric
 * methods throughout, and inference at the aggregate level.
 *
 * (a) per-major descriptives (n, mean, median, sd, IQR) over version-boundary observations
 *     (Instrument A — state), with the pooled OLS fit retained as a descriptive linear fit
 *     only (it is not a test of H2a/H2b);
 * (b) trend (H2a, H2b): per-repository Theil-Sen slopes and Mann-Kendall tau over each
 *     repository's (major → median) series; the primary test is the Wilcoxon signed-rank
 *     of the per-repository slopes against zero, with per-repository Spearman rho
 *     aggregated the same way as the secondary. Per-repository p-values are suppressed
 *     below n = 10 (printed as `—`) — inference happens at the aggregate level;
 * (c) paradigm (H1): Appendix B's categorical Pf per checkpoint, descriptive per major,
 *     and a Wilcoxon signed-rank pairing mean Pf below vs at/above Laravel 10 for
 *     repositories spanning that boundary;
 * (d) era comparison (H3a): Wilcoxon signed-rank over per-repository pre/post window
 *     medians (Instrument B — flow, re-bucketed from introduced_author_date so `--cutoff=`
 *     sensitivity runs need no re-blame), split by paradigm (all/phpunit/pest), with the
 *     pooled Mann-Whitney U retained as secondary (pooled, independence violated) and the
 *     blame-scope attrition reported alongside;
 * (e) test-type distribution per version and per AI window as percentage tables;
 * (f) multiplicity: every p-value produced in a run is collected; pre-registered primary
 *     comparisons (config('analyser.primary_metrics')) pass through unadjusted and the
 *     exploratory remainder is Benjamini-Hochberg adjusted.
 *
 * `--export=path.csv` writes each block to its own CSV (path suffixed per block) so
 * dissertation figures are generated from command output, not screenshots.
 * Statistical tests refuse (warning, not crash) below the observation and repository floors.
 */
class ReportCommand extends Command
{
    /** Minimum observations per pooled group for the secondary tests; below this we refuse, not crash. */
    private const int GROUP_FLOOR = 5;

    /** Minimum repositories contributing pairs/series for the primary paired tests. */
    private const int REPOSITORY_FLOOR = 5;

    /** Per-repository p-values are suppressed (printed as `—`) below this series length. */
    private const int SERIES_P_FLOOR = 10;

    /** H1 boundary: Laravel major at which the Pest-adoption window opens. */
    private const int H1_MAJOR_BOUNDARY = 10;

    private const float ALPHA = 0.05;

    protected $signature = 'analyse:report
        {--metric= : restrict to one metric}
        {--cutoff= : override the configured ai_cutoff (sensitivity runs)}
        {--export= : base .csv path; each block is written to <base>_<block>.csv}';

    protected $description = 'Produce descriptive trends, per-repository trend and era tests, paradigm (H1) and multiplicity blocks';

    /** @var array<string, array{header: list<string>, rows: list<list<string|int|float>>}> */
    private array $csvBlocks = [];

    /** @var array<string, float> every p-value produced in this run, keyed by comparison label */
    private array $pValues = [];

    public function handle(): int
    {
        $metrics = $this->metrics();
        if ($metrics === []) {
            $this->error('Unknown metric — choose from: '.implode(', ', DatasetQueries::METRICS));

            return self::FAILURE;
        }

        $this->stampProvenance();

        $this->reportVersionBlocks($metrics);
        $this->reportTrends($metrics);
        $this->reportParadigm();
        $this->reportAiComparison($metrics, $this->cutoff());
        $this->reportTypeDistributions();
        $this->reportMultiplicity();

        $exportBase = $this->option('export');
        if (is_string($exportBase) && $exportBase !== '') {
            $this->exportCsv($exportBase);
        }

        return self::SUCCESS;
    }

    /**
     * Head the report — and its CSV export — with the exact tool revision and active
     * cutoff, so every emitted figure is traceable to the code that produced it.
     */
    private function stampProvenance(): void
    {
        $version = ToolVersion::resolve();

        $this->components->info("lta {$version} — cutoff {$this->cutoff()->toDateString()}");

        $this->csvBlocks['provenance'] = [
            'header' => ['tool_version', 'ai_cutoff'],
            'rows' => [[$version, $this->cutoff()->toDateString()]],
        ];
    }

    /**
     * Blocks (a) descriptives and the pooled OLS fit — descriptive only, not a test.
     *
     * @param  list<string>  $metrics
     */
    private function reportVersionBlocks(array $metrics): void
    {
        $observations = DatasetQueries::versionBoundaryObservations();

        $this->components->info('Instrument A — per-major state (version-boundary snapshots)');

        if ($observations->isEmpty()) {
            $this->warn('No version-boundary observations — run analyse:snapshot + analyse:extract first.');

            return;
        }

        $descriptives = ['header' => ['metric', 'framework_version', 'n', 'mean', 'median', 'sd', 'iqr'], 'rows' => []];
        $descriptiveFit = ['header' => ['metric', 'slope', 'intercept', 'r2', 'n'], 'rows' => []];

        foreach ($metrics as $metric) {
            $tableRows = [];
            foreach ($observations->groupBy('major')->sortKeys() as $major => $group) {
                $values = $group->pluck($metric)->map(fn ($v) => (float) $v)->all();
                $row = [
                    $metric,
                    $major,
                    count($values),
                    sprintf('%.2f', Average::mean($values)),
                    sprintf('%.2f', Average::median($values)),
                    sprintf('%.2f', count($values) > 1 ? Descriptive::standardDeviation($values) : 0.0),
                    sprintf('%.2f', count($values) > 1 ? Descriptive::interquartileRange($values) : 0.0),
                ];
                $descriptives['rows'][] = $row;
                $tableRows[] = array_slice($row, 1);
            }

            $this->line("• {$metric}");
            $this->table(['Laravel major', 'n', 'mean', 'median', 'sd', 'IQR'], $tableRows);

            $points = array_values($observations
                ->map(fn ($o): array => [(float) $o->major, (float) $o->{$metric}])
                ->all());
            if (count(array_unique(array_column($points, 0))) > 1) {
                $fit = SimpleLinearRegression::fit($points);
                $this->line(sprintf(
                    '  descriptive linear fit (not a test of H2a): %s = %.3f × major %+.3f   (r² = %.3f, n = %d)',
                    $metric,
                    $fit['slope'],
                    $fit['intercept'],
                    $fit['r2'],
                    $fit['n'],
                ));
                $descriptiveFit['rows'][] = [
                    $metric,
                    sprintf('%.6f', $fit['slope']),
                    sprintf('%.6f', $fit['intercept']),
                    sprintf('%.6f', $fit['r2']),
                    $fit['n'],
                ];
            }
        }

        $this->csvBlocks['descriptives'] = $descriptives;
        $this->csvBlocks['descriptive_fit'] = $descriptiveFit;
    }

    /**
     * Block (b) — trend (H2a, H2b). Primary: Wilcoxon signed-rank of per-repository
     * Theil-Sen slopes against zero. Secondary: per-repository Spearman rho aggregated the
     * same way. Cliff's delta accompanies the comparison between the per-repository medians
     * at each repository's lowest vs highest observed major (the two ends of the trend);
     * negative delta = increase toward the higher major, matching the era block's
     * pre-vs-post sign convention.
     *
     * @param  list<string>  $metrics
     */
    private function reportTrends(array $metrics): void
    {
        $this->components->info('Trend (H2a, H2b) — per-repository Theil-Sen + Mann-Kendall; Wilcoxon signed-rank aggregate');

        $trend = ['header' => [
            'metric', 'front_end', 'n_repositories', 'median_slope', 'median_tau',
            'n_positive_slopes', 'n_negative_slopes', 'wilcoxon_w', 'wilcoxon_p', 'wilcoxon_exact',
            'n_zero_slopes_dropped', 'rank_biserial', 'cliffs_delta_ends', 'magnitude',
            'spearman_median_rho', 'spearman_wilcoxon_p',
        ], 'rows' => []];
        $perRepository = ['header' => [
            'metric', 'front_end', 'repository_id', 'n_majors',
            'theil_sen_slope', 'mann_kendall_tau', 'mann_kendall_p', 'spearman_rho',
        ], 'rows' => []];

        $anySeries = false;
        foreach ($metrics as $metric) {
            foreach ($this->trendFrontEnds($metric) as $frontEnd) {
                $anySeries = $this->trendBlock($metric, $frontEnd, $trend, $perRepository) || $anySeries;
            }
        }

        if (! $anySeries) {
            $this->warn('No per-repository trend series — run analyse:snapshot + analyse:extract first.');

            return;
        }

        $this->table($trend['header'], $trend['rows']);
        $this->csvBlocks['trend'] = $trend;
        $this->csvBlocks['trend_repositories'] = $perRepository;
    }

    /**
     * Paradigm slices for the trend block. H2b's metric is additionally reported split by
     * paradigm — the check that its result is not an artefact of paradigm mix shifting
     * across majors. Everything else runs unsplit.
     *
     * @return list<string|null> null = all front ends
     */
    private function trendFrontEnds(string $metric): array
    {
        return [null];
    }

    /**
     * One (metric, front-end slice) trend row; returns whether any series existed.
     *
     * @param  array{header: list<string>, rows: list<list<string|int|float>>}  $trend
     * @param  array{header: list<string>, rows: list<list<string|int|float>>}  $perRepository
     */
    private function trendBlock(string $metric, ?string $frontEnd, array &$trend, array &$perRepository): bool
    {
        $label = $frontEnd ?? 'all';
        $series = DatasetQueries::repositoryMajorMedians($metric, $frontEnd);
        if ($series->isEmpty()) {
            return false;
        }

        $slopes = [];
        $taus = [];
        $rhos = [];
        $firstValues = [];
        $lastValues = [];

        foreach ($series->groupBy('repository_id') as $repositoryId => $points) {
            if ($points->count() < 2) {
                continue; // a single checkpoint has no trend
            }

            $majors = array_values($points->pluck('major')->map(fn ($m): float => (float) $m)->all());
            $values = array_values($points->pluck('value')->map(fn ($v): float => (float) $v)->all());

            $theilSen = TheilSen::estimate(array_map(null, $majors, $values));
            $mannKendall = MannKendall::test($values);
            $spearman = Spearman::test($majors, $values);

            $slopes[] = $theilSen['slope'];
            $taus[] = $mannKendall['tau'];
            $rhos[] = $spearman['rho'];
            $firstValues[] = $values[0];
            $lastValues[] = $values[count($values) - 1];

            // Per-repository p-values are unreliable on short series (often n = 3–5):
            // suppressed below n = 10, descriptive tau/rho stay.
            $suppressed = $mannKendall['n'] < self::SERIES_P_FLOOR;
            if (! $suppressed) {
                $this->registerP("trend_repo:{$metric}:{$label}:{$repositoryId}:mann_kendall", $mannKendall['p']);
            }
            $perRepository['rows'][] = [
                $metric,
                $label,
                $repositoryId,
                $mannKendall['n'],
                sprintf('%.4f', $theilSen['slope']),
                sprintf('%.3f', $mannKendall['tau']),
                $suppressed ? '—' : sprintf('%.4f', $mannKendall['p']),
                sprintf('%.3f', $spearman['rho']),
            ];
        }

        if ($slopes === []) {
            return true;
        }

        if (count($slopes) < self::REPOSITORY_FLOOR) {
            $this->warn(sprintf(
                'Refusing the trend test for %s (%s): only %d repositories contribute series (floor %d).',
                $metric,
                $label,
                count($slopes),
                self::REPOSITORY_FLOOR,
            ));
            $trend['rows'][] = [
                $metric, $label, count($slopes),
                sprintf('%.4f', Average::median($slopes)),
                sprintf('%.3f', Average::median($taus)),
                count(array_filter($slopes, fn (float $s): bool => $s > 0)),
                count(array_filter($slopes, fn (float $s): bool => $s < 0)),
                '—', '—', '—', '—', '—', '—', '—',
                sprintf('%.3f', Average::median($rhos)),
                '—',
            ];

            return true;
        }

        $zeros = array_fill(0, count($slopes), 0.0);
        $wilcoxon = WilcoxonSignedRank::test($slopes, $zeros);
        $this->registerP("trend:{$metric}:{$label}:wilcoxon_slopes", $wilcoxon['p']);

        // Cliff's delta between the two ends of the trend (lowest vs highest observed
        // major per repository) — pre-vs-post sign convention: negative = increase.
        $delta = EffectSize::cliffsDelta($firstValues, $lastValues);

        $spearmanAggregate = WilcoxonSignedRank::test($rhos, array_fill(0, count($rhos), 0.0));
        $this->registerP("trend:{$metric}:{$label}:spearman_wilcoxon", $spearmanAggregate['p']);

        $trend['rows'][] = [
            $metric,
            $label,
            count($slopes),
            sprintf('%.4f', Average::median($slopes)),
            sprintf('%.3f', Average::median($taus)),
            count(array_filter($slopes, fn (float $s): bool => $s > 0)),
            count(array_filter($slopes, fn (float $s): bool => $s < 0)),
            sprintf('%.1f', $wilcoxon['w']),
            sprintf('%.4f', $wilcoxon['p']),
            $wilcoxon['exact'] ? 'yes' : 'no',
            $wilcoxon['n_dropped'],
            sprintf('%.3f', $wilcoxon['rank_biserial']),
            sprintf('%.3f', $delta),
            EffectSize::interpret($delta),
            sprintf('%.3f', Average::median($rhos)),
            sprintf('%.4f', $spearmanAggregate['p']),
        ];

        return true;
    }

    /**
     * Block (c) — paradigm (H1). Descriptive Pf distribution per major, undefined
     * checkpoints reported as such, and the boundary-spanning Wilcoxon signed-rank test:
     * mean Pf across pre-10 majors paired against mean Pf across 10+ majors. Zero
     * differences (repositories that never adopt Pest) are exactly what the dropped-zeros
     * count reports.
     */
    private function reportParadigm(): void
    {
        $this->components->info('Paradigm (H1) — Pf per checkpoint (0 = PHPUnit only, 1 = mixed, 2 = Pest only)');

        $checkpoints = DatasetQueries::paradigmByCheckpoint($undefined);
        if ($checkpoints->isEmpty()) {
            $this->warn('No checkpoints hold a routed suite — Pf is undefined everywhere.');

            return;
        }

        $byMajor = ['header' => ['framework_version', 'n_repositories', 'pf_phpunit_only', 'pf_mixed', 'pf_pest_only', 'median_pest_file_share'], 'rows' => []];
        foreach ($checkpoints->groupBy('major')->sortKeys() as $major => $group) {
            $byPf = $group->countBy('pf');
            $shares = $group->map(fn (array $c): float => $c['pest_files'] / $c['total_files'])->all();
            $byMajor['rows'][] = [
                $major,
                $group->count(),
                $byPf[0] ?? 0,
                $byPf[1] ?? 0,
                $byPf[2] ?? 0,
                sprintf('%.3f', Average::median($shares)),
            ];
        }

        $this->table($byMajor['header'], $byMajor['rows']);
        $this->csvBlocks['paradigm_by_major'] = $byMajor;

        $this->line(sprintf(
            '  %d checkpoint(s) hold no PHPUnit or Pest suite — Pf undefined there; excluded from the pairing and reported as such.',
            (int) $undefined,
        ));

        // Pair repositories observed on both sides of the Laravel 10 boundary.
        $pre = [];
        $post = [];
        foreach ($checkpoints->groupBy('repository_id') as $repository) {
            [$below, $above] = $repository->partition(fn (array $c): bool => $c['major'] < self::H1_MAJOR_BOUNDARY);
            if ($below->isEmpty() || $above->isEmpty()) {
                continue;
            }
            $pre[] = (float) Average::mean($below->pluck('pf')->all());
            $post[] = (float) Average::mean($above->pluck('pf')->all());
        }

        $spanning = count($pre);
        if ($spanning < self::REPOSITORY_FLOOR) {
            $this->warn(sprintf(
                'Refusing the H1 paired test: only %d repositories are observed both below and at/above major %d (floor %d) — the descriptive table above stands alone.',
                $spanning,
                self::H1_MAJOR_BOUNDARY,
                self::REPOSITORY_FLOOR,
            ));

            return;
        }

        $wilcoxon = WilcoxonSignedRank::test($pre, $post);
        $this->registerP('h1:paradigm_pf:wilcoxon', $wilcoxon['p']);
        $delta = EffectSize::cliffsDelta($pre, $post);

        $this->line(sprintf(
            '  H1 — mean Pf pre-%d vs %d+ (n = %d spanning repositories): W = %.1f, p = %.4f (%s), '
            .'%d zero difference(s) dropped (never-adopters), rank-biserial = %.3f, Cliff\'s δ = %.3f (%s).',
            self::H1_MAJOR_BOUNDARY,
            self::H1_MAJOR_BOUNDARY,
            $spanning,
            $wilcoxon['w'],
            $wilcoxon['p'],
            $wilcoxon['exact'] ? 'exact' : 'approx',
            $wilcoxon['n_dropped'],
            $wilcoxon['rank_biserial'],
            $delta,
            EffectSize::interpret($delta),
        ));

        $this->csvBlocks['paradigm_test'] = [
            'header' => ['n_spanning_repositories', 'wilcoxon_w', 'wilcoxon_p', 'wilcoxon_exact', 'n_zero_dropped', 'rank_biserial', 'cliffs_delta', 'magnitude'],
            'rows' => [[
                $spanning,
                sprintf('%.1f', $wilcoxon['w']),
                sprintf('%.4f', $wilcoxon['p']),
                $wilcoxon['exact'] ? 'yes' : 'no',
                $wilcoxon['n_dropped'],
                sprintf('%.3f', $wilcoxon['rank_biserial']),
                sprintf('%.3f', $delta),
                EffectSize::interpret($delta),
            ]],
        ];
    }

    /**
     * Block (d) — era comparison (H3a). Primary: Wilcoxon signed-rank over per-repository
     * pre/post window medians (differences pre − post; negative rank-biserial/delta =
     * increase post). Secondary: the pooled Mann-Whitney U — pooled, independence violated.
     * Split by paradigm (all/phpunit/pest) per the internal-validity commitment, with the
     * blame-scope attrition reported alongside.
     *
     * @param  list<string>  $metrics
     */
    private function reportAiComparison(array $metrics, Carbon $cutoff, string $blockName = 'ai_comparison', ?string $boundary = null): void
    {
        $context = $boundary === null ? '' : " [boundary {$boundary}]";
        $this->components->info("Instrument B — era comparison (H3a), per-repository paired windows (cutoff {$cutoff->toDateString()}){$context}");

        $methods = DatasetQueries::onePerAuthoredMethod();
        if ($methods->isEmpty()) {
            $this->warn('No blamed observations — run analyse:blame first.');

            return;
        }

        $boundaryColumns = $boundary === null ? [] : ['boundary'];
        $header = [...$boundaryColumns,
            'metric', 'front_end', 'n_repositories', 'n_excluded_floor', 'median_pre', 'median_post',
            'wilcoxon_w', 'wilcoxon_p', 'wilcoxon_exact', 'n_zero_dropped', 'rank_biserial',
            'cliffs_delta_medians', 'magnitude',
            'pooled_n_pre', 'pooled_n_post', 'pooled_u', 'pooled_z', 'pooled_p', 'pooled_cliffs_delta', 'pooled_magnitude',
        ];
        $blockRows = $this->csvBlocks[$blockName]['rows'] ?? [];

        $primaryRows = [];
        $secondaryRows = [];

        foreach ([null, 'phpunit', 'pest'] as $frontEnd) {
            $label = $frontEnd ?? 'all';
            $slice = $methods->when($frontEnd !== null, fn (Collection $all): Collection => $all
                ->filter(fn (TestObservation $o): bool => $o->front_end === $frontEnd));

            // The window structure (pair counts, floors) is metric-independent, so the
            // refusal warnings fire once per slice, not once per metric.
            [$pre, $post] = $slice->partition(
                fn (TestObservation $o): bool => $o->introduced_author_date->lessThan($cutoff),
            );
            $probe = DatasetQueries::repositoryWindowMedians($metrics[0], $cutoff, $frontEnd);
            if ($probe->count() < self::REPOSITORY_FLOOR) {
                $this->warn(sprintf(
                    'Refusing the era comparison (%s): only %d repositories contribute pre/post pairs (floor %d).',
                    $label,
                    $probe->count(),
                    self::REPOSITORY_FLOOR,
                ));
            }
            $pooledRefused = $pre->count() < self::GROUP_FLOOR || $post->count() < self::GROUP_FLOOR;
            if ($pooledRefused) {
                $this->warn(sprintf(
                    'Refusing the pre/post comparison: group below the n=%d floor (pre n=%d, post n=%d).',
                    self::GROUP_FLOOR,
                    $pre->count(),
                    $post->count(),
                ));
            }

            foreach ($metrics as $metric) {
                $row = $this->eraRow($metric, $frontEnd, $label, $pre, $post, $pooledRefused, $cutoff, $boundary);
                $blockRows[] = $boundary === null ? $row : [$boundary, ...$row];

                $primaryRows[] = array_slice($row, 0, 13);
                $secondaryRows[] = [$row[0], $row[1], ...array_slice($row, 13)];
            }
        }

        $this->csvBlocks[$blockName] = ['header' => $header, 'rows' => $blockRows];

        $this->line('  primary — Wilcoxon signed-rank over per-repository window medians (pre − post):');
        $this->table(array_slice($header, count($boundaryColumns), 13), $primaryRows);
        $this->line('  secondary — pooled Mann-Whitney U + Cliff\'s δ (pooled, independence violated):');
        $this->table(['metric', 'front_end', ...array_slice($header, count($boundaryColumns) + 13)], $secondaryRows);

        if ($boundary === null) {
            $this->reportAttrition();
        }
    }

    /**
     * One (metric, front-end slice) era row: primary paired columns then secondary pooled
     * columns. Refused cells print `—`; the refusal warnings themselves are emitted once
     * per slice by the caller.
     *
     * @param  Collection<int, TestObservation>  $pre
     * @param  Collection<int, TestObservation>  $post
     * @return list<string|int|float>
     */
    private function eraRow(string $metric, ?string $frontEnd, string $label, Collection $pre, Collection $post, bool $pooledRefused, Carbon $cutoff, ?string $boundary): array
    {
        $prefix = $boundary === null ? '' : "sensitivity:{$boundary}:";

        // Primary — per-repository paired windows.
        $pairs = DatasetQueries::repositoryWindowMedians($metric, $cutoff, $frontEnd, $excluded);
        $primary = ['—', '—', '—', '—', '—', '—', '—', '—', '—'];
        if ($pairs->count() >= self::REPOSITORY_FLOOR) {
            $preMedians = array_values($pairs->pluck('pre')->map(fn ($v): float => (float) $v)->all());
            $postMedians = array_values($pairs->pluck('post')->map(fn ($v): float => (float) $v)->all());
            $wilcoxon = WilcoxonSignedRank::test($preMedians, $postMedians);
            $this->registerP("{$prefix}era:{$metric}:{$label}:wilcoxon", $wilcoxon['p']);
            $delta = EffectSize::cliffsDelta($preMedians, $postMedians);

            $primary = [
                sprintf('%.2f', Average::median($preMedians)),
                sprintf('%.2f', Average::median($postMedians)),
                sprintf('%.1f', $wilcoxon['w']),
                sprintf('%.4f', $wilcoxon['p']),
                $wilcoxon['exact'] ? 'yes' : 'no',
                $wilcoxon['n_dropped'],
                sprintf('%.3f', $wilcoxon['rank_biserial']),
                sprintf('%.3f', $delta),
                EffectSize::interpret($delta),
            ];
        }

        // Secondary — pooled, independence violated.
        $secondary = ['—', '—', '—', '—', '—'];
        if (! $pooledRefused) {
            $preValues = array_values($pre->pluck($metric)->map(fn ($v) => (float) $v)->all());
            $postValues = array_values($post->pluck($metric)->map(fn ($v) => (float) $v)->all());

            $test = MannWhitney::test($preValues, $postValues);
            $this->registerP("{$prefix}era:{$metric}:{$label}:mann_whitney_pooled", $test['p']);
            $pooledDelta = EffectSize::cliffsDelta($preValues, $postValues);

            $secondary = [
                sprintf('%.1f', $test['u']),
                sprintf('%.3f', $test['z']),
                sprintf('%.4f', $test['p']),
                sprintf('%.3f', $pooledDelta),
                EffectSize::interpret($pooledDelta),
            ];
        }

        return [
            $metric,
            $label,
            $pairs->count(),
            $excluded->count(),
            ...$primary,
            $pre->count(),
            $post->count(),
            ...$secondary,
        ];
    }

    /**
     * Attrition, as the internal-validity section commits to: the share of methods in the
     * blame scope (each repository's newest extracted snapshot) left unattributed, plus a
     * pointer at the per-row paired-floor exclusions.
     */
    private function reportAttrition(): void
    {
        $block = ['header' => ['repository', 'blame_scope_methods', 'unattributed', 'unattributed_pct'], 'rows' => []];
        $totalScope = 0;
        $totalNull = 0;

        foreach (Repository::orderBy('full_name')->get() as $repository) {
            // Same scope query as VerifyCommand's Instrument B coverage check.
            $newest = $repository->snapshots()
                ->has('observations')
                ->orderByRaw("kind = 'head' desc")
                ->orderByDesc('framework_version')
                ->first();
            if ($newest === null) {
                continue;
            }

            $scope = $newest->observations()->count();
            $nulls = $newest->observations()->whereNull('ai_window')->count();
            $totalScope += $scope;
            $totalNull += $nulls;

            $block['rows'][] = [
                (string) $repository->full_name,
                $scope,
                $nulls,
                sprintf('%.1f', $scope > 0 ? $nulls / $scope * 100 : 0),
            ];
        }

        if ($totalScope === 0) {
            return;
        }

        $block['rows'][] = ['(all)', $totalScope, $totalNull, sprintf('%.1f', $totalNull / $totalScope * 100)];
        $this->line(sprintf(
            '  attrition: %.1f%% of methods in the blame scope have no attribution (%d of %d); '
            .'paired-floor exclusions are reported per row above (n_excluded_floor).',
            $totalNull / $totalScope * 100,
            $totalNull,
            $totalScope,
        ));
        $this->csvBlocks['attrition'] = $block;
    }

    /**
     * Block (e) — test-type distribution per version and per AI window, as percentages.
     */
    private function reportTypeDistributions(): void
    {
        $this->components->info('Test-type distribution (%)');

        $byVersion = DatasetQueries::versionBoundaryObservations()->groupBy('major')->sortKeys();
        if ($byVersion->isNotEmpty()) {
            $this->renderDistribution('types_by_version', 'framework_version', $byVersion->all());
        }

        $byWindow = DatasetQueries::onePerAuthoredMethod()
            ->filter(fn (TestObservation $o): bool => $o->ai_window !== null)
            ->groupBy('ai_window')
            ->sortKeysDesc(); // pre before post
        if ($byWindow->isNotEmpty()) {
            $this->renderDistribution('types_by_window', 'ai_window', $byWindow->all());
        }
    }

    /**
     * @param  array<int|string, Collection<int, TestObservation>>  $groups
     */
    private function renderDistribution(string $blockName, string $keyLabel, array $groups): void
    {
        $types = ['unit', 'feature', 'integration', 'unknown'];
        $block = ['header' => [$keyLabel, 'n', ...$types], 'rows' => []];

        foreach ($groups as $key => $group) {
            $byType = $group->countBy('test_type');
            $n = $group->count();
            $block['rows'][] = [
                $key,
                $n,
                ...array_map(fn (string $t): string => sprintf('%.1f', ($byType[$t] ?? 0) / $n * 100), $types),
            ];
        }

        $this->table($block['header'], $block['rows']);
        $this->csvBlocks[$blockName] = $block;
    }

    /**
     * Block (f) — multiplicity. Every p-value produced in the run appears exactly once:
     * the pre-registered primary comparisons pass through unadjusted (q blank), everything
     * else is a family and Benjamini-Hochberg adjusted.
     */
    private function reportMultiplicity(): void
    {
        if ($this->pValues === []) {
            return;
        }

        $this->components->info('Multiplicity — pre-registered primary vs Benjamini-Hochberg-adjusted exploratory');

        $primaryLabels = $this->primaryLabels();
        $primary = array_intersect_key($this->pValues, $primaryLabels);
        $exploratory = array_diff_key($this->pValues, $primaryLabels);
        $adjusted = BenjaminiHochberg::adjust($exploratory, self::ALPHA);

        $block = ['header' => ['comparison', 'family', 'p', 'q', 'rejected'], 'rows' => []];
        foreach ($primary as $label => $p) {
            $block['rows'][] = [
                $label,
                "primary ({$primaryLabels[$label]})",
                sprintf('%.4f', $p),
                '',
                $p <= self::ALPHA ? 'yes' : 'no',
            ];
        }
        foreach ($adjusted as $label => $result) {
            $block['rows'][] = [
                $label,
                'exploratory',
                sprintf('%.4f', $result['p']),
                sprintf('%.4f', $result['q']),
                $result['rejected'] ? 'yes' : 'no',
            ];
        }

        $this->table($block['header'], $block['rows']);
        $this->csvBlocks['multiplicity'] = $block;
    }

    private function registerP(string $label, float $p): void
    {
        $this->pValues[$label] = $p;
    }

    /**
     * The comparison labels the pre-registered primary metrics resolve to, mapped to their
     * hypothesis. Everything not listed here is exploratory.
     *
     * @return array<string, string> label => hypothesis
     */
    private function primaryLabels(): array
    {
        /** @var array<string, string> $primary */
        $primary = (array) config('analyser.primary_metrics', []);

        $labelFor = [
            'H1' => fn (string $m): string => "h1:{$m}:wilcoxon",
            'H2a' => fn (string $m): string => "trend:{$m}:all:wilcoxon_slopes",
            'H2b' => fn (string $m): string => "trend:{$m}:all:wilcoxon_slopes",
            'H3a' => fn (string $m): string => "era:{$m}:all:wilcoxon",
            'H3b' => fn (string $m): string => "agent:{$m}:wilcoxon",
        ];

        $labels = [];
        foreach ($labelFor as $hypothesis => $label) {
            if (isset($primary[$hypothesis])) {
                $labels[$label($primary[$hypothesis])] = $hypothesis;
            }
        }

        return $labels;
    }

    private function exportCsv(string $base): void
    {
        $stem = preg_replace('/\.csv$/i', '', $base) ?? $base;

        foreach ($this->csvBlocks as $name => $block) {
            $path = "{$stem}_{$name}.csv";
            $handle = fopen($path, 'w');
            if ($handle === false) {
                $this->warn("Could not open {$path} for writing — block '{$name}' not exported.");

                continue;
            }
            fputcsv($handle, $block['header']);
            foreach ($block['rows'] as $row) {
                fputcsv($handle, $row);
            }
            fclose($handle);
            $this->line("  exported: {$path}");
        }
    }

    private function cutoff(): Carbon
    {
        $explicit = $this->option('cutoff');
        if (is_string($explicit) && $explicit !== '') {
            return Carbon::parse($explicit);
        }

        return Carbon::parse((string) config('analyser.ai_cutoff'));
    }

    /** @return list<string> */
    private function metrics(): array
    {
        $only = $this->option('metric');
        if (! is_string($only) || $only === '') {
            return DatasetQueries::METRICS;
        }

        return in_array($only, DatasetQueries::METRICS, true) ? [$only] : [];
    }
}
