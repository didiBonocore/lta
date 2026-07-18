<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * Stage 6 — analysis over the emitted dataset: descriptives, regression on framework
 * version (SRQ1/2), and Mann-Whitney U + Cliff's delta on pre/post-AI groups (SRQ3).
 */
class ReportCommand extends Command
{
    protected $signature = 'analyse:report {--metric=assertion_count}';

    protected $description = 'Produce descriptive trends, version regression, and pre/post-AI comparison';

    public function handle(): int
    {
        // TODO(M4): pull metric vectors from TestObservation grouped by framework_version and
        // by ai_window; feed MathPHP (descriptives/regression) + App\Analysis\Statistics
        // (MannWhitney, EffectSize). Emit a compact table for the Results chapter.
        $this->warn('Not yet implemented — see ROADMAP.md M4.');

        return self::SUCCESS;
    }
}
