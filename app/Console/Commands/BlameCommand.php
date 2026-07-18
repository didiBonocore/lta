<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * Instrument B — attribute each test method to its introducing commit's author-date and
 * bucket pre/post-AI. This isolates tests *authored in* each era (flow), unlike a snapshot
 * which is contaminated by old sticky tests (state).
 */
class BlameCommand extends Command
{
    protected $signature = 'analyse:blame {full_name : owner/repo} {--cutoff=}';

    protected $description = 'Attribute each test method to its author-date and tag the AI window';

    public function handle(): int
    {
        // TODO(M3):
        //  - cutoff date is commit-author-date based (NOT framework version: Laravel 13 is
        //    AI-native). Default from config('analyser.ai_cutoff'); confirm exact date w/ supervisor.
        //  - for each observation, git blame / git log the introducing commit of the method
        //    definition line range; take author-date; set ai_window = date < cutoff ? 'pre' : 'post'.
        $this->warn('Not yet implemented — see ROADMAP.md M3.');

        return self::SUCCESS;
    }
}
