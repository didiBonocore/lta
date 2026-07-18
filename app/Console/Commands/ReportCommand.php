<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Analysis\Statistics\EffectSize;
use App\Analysis\Statistics\MannWhitney;
use App\Models\TestObservation;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use MathPHP\Statistics\Average;
use MathPHP\Statistics\Descriptive;
use MathPHP\Statistics\Regression\Linear;

/**
 * Stage 6 — analysis over the emitted dataset, one compact table per research question:
 * SRQ1/2 (Instrument A): per-major descriptives + a least-squares trend on framework major,
 * over version-boundary snapshot observations (state).
 * SRQ3 (Instrument B): pre/post-AI comparison via Mann-Whitney U + Cliff's delta over one
 * observation per authored test method (flow), re-bucketed from introduced_author_date so
 * the sensitivity cutoff can be swapped in without re-running the blame pass.
 */
class ReportCommand extends Command
{
    protected $signature = 'analyse:report
        {--metric= : restrict to one metric}
        {--cutoff= : explicit AI-cutoff override (YYYY-MM-DD)}
        {--sensitivity : use the ai_cutoff_sensitivity date (ChatGPT launch) instead of the primary cutoff}';

    protected $description = 'Produce descriptive trends, version regression, and pre/post-AI comparison';

    private const array METRICS = [
        'assertion_count',
        'mock_breadth',
        'max_mock_chain_depth',
        'size_statements',
        'size_loc',
    ];

    public function handle(): int
    {
        $metrics = $this->metrics();
        if ($metrics === []) {
            $this->error('Unknown metric — choose from: '.implode(', ', self::METRICS));

            return self::FAILURE;
        }

        $this->reportVersionTrends($metrics);
        $this->reportAiWindows($metrics);

        return self::SUCCESS;
    }

    /** @param list<string> $metrics */
    private function reportVersionTrends(array $metrics): void
    {
        $observations = TestObservation::query()
            ->join('snapshots', 'snapshots.id', '=', 'test_observations.snapshot_id')
            ->where('snapshots.kind', 'version_boundary')
            ->select(array_merge(
                array_map(fn (string $m): string => "test_observations.{$m}", $metrics),
                ['snapshots.framework_version as major'],
            ))
            ->get();

        $this->components->info('Instrument A — per-major state (version-boundary snapshots)');

        if ($observations->isEmpty()) {
            $this->warn('No version-boundary observations — run analyse:snapshot + analyse:extract first.');

            return;
        }

        foreach ($metrics as $metric) {
            $byMajor = $observations->groupBy('major')->sortKeys();

            $this->line("• {$metric}");
            $this->table(
                ['Laravel major', 'n', 'mean', 'median', 'sd'],
                $byMajor->map(function (Collection $group, int $major) use ($metric): array {
                    $values = $group->pluck($metric)->map(fn ($v) => (float) $v)->all();

                    return [
                        $major,
                        count($values),
                        sprintf('%.2f', Average::mean($values)),
                        sprintf('%.2f', Average::median($values)),
                        sprintf('%.2f', count($values) > 1 ? Descriptive::standardDeviation($values) : 0.0),
                    ];
                })->values()->all(),
            );

            $points = $observations
                ->map(fn ($o): array => [(float) $o->major, (float) $o->{$metric}])
                ->values()
                ->all();
            if (count(array_unique(array_column($points, 0))) > 1) {
                $regression = new Linear($points);
                ['m' => $slope, 'b' => $intercept] = $regression->getParameters();
                $this->line(sprintf(
                    '  trend: %s = %.3f × major %+.3f   (r² = %.3f, n = %d)',
                    $metric,
                    $slope,
                    $intercept,
                    $regression->coefficientOfDetermination(),
                    count($points),
                ));
            }
        }
    }

    /** @param list<string> $metrics */
    private function reportAiWindows(array $metrics): void
    {
        $cutoff = $this->cutoff();
        $this->components->info("Instrument B — authored flow, pre/post-AI (cutoff {$cutoff->toDateString()})");

        $methods = $this->onePerAuthoredMethod();
        if ($methods->isEmpty()) {
            $this->warn('No blamed observations — run analyse:blame first.');

            return;
        }

        [$pre, $post] = $methods->partition(
            fn (TestObservation $o): bool => $o->introduced_author_date->lessThan($cutoff),
        );

        if ($pre->isEmpty() || $post->isEmpty()) {
            $this->warn(sprintf(
                'Insufficient data for a comparison at this cutoff (pre n=%d, post n=%d).',
                $pre->count(),
                $post->count(),
            ));

            return;
        }

        $rows = [];
        foreach ($metrics as $metric) {
            $preValues = $pre->pluck($metric)->map(fn ($v) => (float) $v)->values()->all();
            $postValues = $post->pluck($metric)->map(fn ($v) => (float) $v)->values()->all();

            $test = MannWhitney::test($preValues, $postValues);
            $delta = EffectSize::cliffsDelta($preValues, $postValues);

            $rows[] = [
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
            $rows,
        );
    }

    /**
     * One observation per authored test method — the earliest snapshot in which the method
     * appears, i.e. the state closest to how it was written (Instrument B measures flow;
     * using a late snapshot would measure subsequent maintenance instead).
     *
     * @return Collection<int, TestObservation>
     */
    private function onePerAuthoredMethod(): Collection
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

    private function cutoff(): Carbon
    {
        $explicit = $this->option('cutoff');
        if (is_string($explicit) && $explicit !== '') {
            return Carbon::parse($explicit);
        }

        return Carbon::parse((string) config(
            $this->option('sensitivity') ? 'analyser.ai_cutoff_sensitivity' : 'analyser.ai_cutoff',
        ));
    }

    /** @return list<string> */
    private function metrics(): array
    {
        $only = $this->option('metric');
        if (! is_string($only) || $only === '') {
            return self::METRICS;
        }

        return in_array($only, self::METRICS, true) ? [$only] : [];
    }
}
