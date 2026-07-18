<?php

declare(strict_types=1);

namespace App\Livewire\Results;

use App\Analysis\Reporting\DatasetQueries;
use App\Analysis\Statistics\EffectSize;
use App\Analysis\Statistics\MannWhitney;
use App\Models\TestObservation;
use Illuminate\Support\Carbon;
use Livewire\Component;
use MathPHP\Statistics\Average;

/**
 * Read-only view of Instrument B: pre/post-AI comparison per metric over one observation
 * per authored method, re-bucketed from introduced_author_date so the anchor can be
 * switched between the primary cutoff (Copilot GA) and the sensitivity one (ChatGPT).
 */
class AiComparison extends Component
{
    public string $anchor = 'primary';

    public function render()
    {
        if (! in_array($this->anchor, ['primary', 'sensitivity'], true)) {
            $this->anchor = 'primary';
        }

        $cutoff = Carbon::parse((string) config(
            $this->anchor === 'sensitivity' ? 'analyser.ai_cutoff_sensitivity' : 'analyser.ai_cutoff',
        ));

        [$pre, $post] = DatasetQueries::onePerAuthoredMethod()->partition(
            fn (TestObservation $o): bool => $o->introduced_author_date->lessThan($cutoff),
        );

        $rows = [];
        if ($pre->isNotEmpty() && $post->isNotEmpty()) {
            foreach (DatasetQueries::METRICS as $metric) {
                $preValues = $pre->pluck($metric)->map(fn ($v) => (float) $v)->values()->all();
                $postValues = $post->pluck($metric)->map(fn ($v) => (float) $v)->values()->all();

                $test = MannWhitney::test($preValues, $postValues);
                $delta = EffectSize::cliffsDelta($preValues, $postValues);

                $rows[] = [
                    'metric' => $metric,
                    'medianPre' => Average::median($preValues),
                    'medianPost' => Average::median($postValues),
                    'p' => $test['p'],
                    'delta' => $delta,
                    'magnitude' => EffectSize::interpret($delta),
                ];
            }
        }

        return view('livewire.results.ai-comparison', [
            'cutoff' => $cutoff,
            'nPre' => $pre->count(),
            'nPost' => $post->count(),
            'rows' => $rows,
        ]);
    }
}
