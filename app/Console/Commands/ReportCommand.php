<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Analysis\Reporting\DatasetQueries;
use App\Analysis\Reporting\ToolVersion;
use App\Analysis\Statistics\EffectSize;
use App\Analysis\Statistics\MannWhitney;
use App\Analysis\Statistics\SimpleLinearRegression;
use App\Models\TestObservation;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use MathPHP\Statistics\Average;
use MathPHP\Statistics\Descriptive;

/**
 * Stage 6 — analysis over the emitted dataset, one block per research question:
 * (a) per-major descriptives (n, mean, median, sd, IQR) and (b) an OLS trend on integer
 * major, over version-boundary observations (Instrument A — state);
 * (c) the pre/post-AI comparison via Mann-Whitney U + Cliff's delta over one observation
 * per authored method (Instrument B — flow), re-bucketed from introduced_author_date so
 * `--cutoff=` sensitivity runs need no re-blame;
 * (d) test-type distribution per version and per AI window as percentage tables (H1).
 * `--export=path.csv` writes each block to its own CSV (path suffixed per block) so
 * dissertation figures are generated from command output, not screenshots.
 * Statistical tests refuse (warning, not crash) below n=5 per group.
 */
class ReportCommand extends Command
{
    /** Minimum group size for the non-parametric tests; below this we refuse, not crash. */
    private const int GROUP_FLOOR = 5;

    protected $signature = 'analyse:report
        {--metric= : restrict to one metric}
        {--cutoff= : override the configured ai_cutoff (sensitivity runs)}
        {--export= : base .csv path; each block is written to <base>_<block>.csv}';

    protected $description = 'Produce descriptive trends, version regression, and pre/post-AI comparison';

    /** @var array<string, array{header: list<string>, rows: list<list<string|int|float>>}> */
    private array $csvBlocks = [];

    public function handle(): int
    {
        $metrics = $this->metrics();
        if ($metrics === []) {
            $this->error('Unknown metric — choose from: '.implode(', ', DatasetQueries::METRICS));

            return self::FAILURE;
        }

        $this->stampProvenance();

        $this->reportVersionBlocks($metrics);
        $this->reportAiComparison($metrics);
        $this->reportTypeDistributions();

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
     * Blocks (a) descriptives and (b) regression, per metric.
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
        $regression = ['header' => ['metric', 'slope', 'intercept', 'r2', 'n'], 'rows' => []];

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
                    '  trend: %s = %.3f × major %+.3f   (r² = %.3f, n = %d)',
                    $metric,
                    $fit['slope'],
                    $fit['intercept'],
                    $fit['r2'],
                    $fit['n'],
                ));
                $regression['rows'][] = [
                    $metric,
                    sprintf('%.6f', $fit['slope']),
                    sprintf('%.6f', $fit['intercept']),
                    sprintf('%.6f', $fit['r2']),
                    $fit['n'],
                ];
            }
        }

        $this->csvBlocks['descriptives'] = $descriptives;
        $this->csvBlocks['regression'] = $regression;
    }

    /**
     * Block (c) — Mann-Whitney U + Cliff's delta per metric, pre vs post AI window.
     *
     * @param  list<string>  $metrics
     */
    private function reportAiComparison(array $metrics): void
    {
        $cutoff = $this->cutoff();
        $this->components->info("Instrument B — authored flow, pre/post-AI (cutoff {$cutoff->toDateString()})");

        $methods = DatasetQueries::onePerAuthoredMethod();
        if ($methods->isEmpty()) {
            $this->warn('No blamed observations — run analyse:blame first.');

            return;
        }

        [$pre, $post] = $methods->partition(
            fn (TestObservation $o): bool => $o->introduced_author_date->lessThan($cutoff),
        );

        if ($pre->count() < self::GROUP_FLOOR || $post->count() < self::GROUP_FLOOR) {
            $this->warn(sprintf(
                'Refusing the pre/post comparison: group below the n=%d floor (pre n=%d, post n=%d).',
                self::GROUP_FLOOR,
                $pre->count(),
                $post->count(),
            ));

            return;
        }

        $block = ['header' => ['metric', 'n_pre', 'n_post', 'median_pre', 'median_post', 'u', 'z', 'p', 'cliffs_delta', 'magnitude'], 'rows' => []];
        foreach ($metrics as $metric) {
            $preValues = array_values($pre->pluck($metric)->map(fn ($v) => (float) $v)->all());
            $postValues = array_values($post->pluck($metric)->map(fn ($v) => (float) $v)->all());

            $test = MannWhitney::test($preValues, $postValues);
            $delta = EffectSize::cliffsDelta($preValues, $postValues);

            $block['rows'][] = [
                $metric,
                count($preValues),
                count($postValues),
                sprintf('%.2f', Average::median($preValues)),
                sprintf('%.2f', Average::median($postValues)),
                sprintf('%.1f', $test['u']),
                sprintf('%.3f', $test['z']),
                sprintf('%.4f', $test['p']),
                sprintf('%.3f', $delta),
                EffectSize::interpret($delta),
            ];
        }

        $this->table(
            ['metric', 'n pre', 'n post', 'median pre', 'median post', 'U', 'z', 'p', "Cliff's δ", 'magnitude'],
            $block['rows'],
        );
        $this->csvBlocks['ai_comparison'] = $block;
    }

    /**
     * Block (d) — test-type distribution per version and per AI window, as percentages (H1).
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
