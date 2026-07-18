<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * Stage 1 / Instrument A — reconstruct integer-major Laravel checkpoints from composer.json
 * history and record one representative commit per major.
 */
class SnapshotCommand extends Command
{
    protected $signature = 'analyse:snapshot {full_name : owner/repo}';

    protected $description = 'Mine composer.json history to build version-boundary snapshots (integer majors)';

    public function handle(): int
    {
        // TODO(M2):
        //  1. git log --follow --reverse --format='%H %aI' -- composer.json
        //  2. at each touching commit, read composer.json (git show <sha>:composer.json),
        //     parse require['laravel/framework'] (fallback illuminate/support) constraint,
        //     resolve to an integer major (e.g. "^11.0" -> 11).
        //  3. map commit ranges -> majors; pick ONE representative commit per major
        //     (last commit before the major changed, i.e. the mature state at that version).
        //  4. persist Snapshot rows (unique per repository+framework_version).
        // Decision locked: integer majors only; 5.x-splitting is a discussion sensitivity check.
        $this->warn('Not yet implemented — see ROADMAP.md M2.');

        return self::SUCCESS;
    }
}
