<?php

declare(strict_types=1);

namespace App\Livewire\Results;

use App\Analysis\Reporting\DatasetQueries;
use App\Analysis\Statistics\SimpleLinearRegression;
use Illuminate\Support\Collection;
use Livewire\Component;
use MathPHP\Statistics\Average;

/**
 * Read-only view of Instrument A: per-major distribution of one metric over the
 * version-boundary snapshot observations, with the same OLS trend the report emits.
 */
class VersionTrends extends Component
{
    public string $metric = 'assertion_count';

    public function render()
    {
        if (! in_array($this->metric, DatasetQueries::METRICS, true)) {
            $this->metric = 'assertion_count';
        }

        $observations = DatasetQueries::versionBoundaryObservations();

        $rows = $observations
            ->groupBy('major')
            ->sortKeys()
            ->map(function (Collection $group, int $major): array {
                $values = $group->pluck($this->metric)->map(fn ($v) => (float) $v)->all();

                return [
                    'major' => $major,
                    'n' => count($values),
                    'mean' => Average::mean($values),
                    'median' => Average::median($values),
                ];
            })
            ->values();

        $fit = SimpleLinearRegression::fit(
            $observations->map(fn ($o): array => [(float) $o->major, (float) $o->{$this->metric}])->values()->all(),
        );

        return view('livewire.results.version-trends', [
            'metrics' => DatasetQueries::METRICS,
            'rows' => $rows,
            'fit' => $fit,
            'maxMean' => max(1e-9, (float) $rows->max('mean')),
        ]);
    }
}
