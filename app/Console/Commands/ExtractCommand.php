<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * Stages 2-4 — for each snapshot: discover the suite, read each test file at the snapshot
 * sha (git show <sha>:path), route it to the owning FrontEnd, and persist TestObservation
 * rows from the resulting IR.
 */
class ExtractCommand extends Command
{
    protected $signature = 'analyse:extract {full_name : owner/repo} {--head : extract HEAD only (M1)}';

    protected $description = 'Parse test suites into the IR and write metric rows to the dataset';

    public function handle(): int
    {
        // TODO(M1/M2):
        //  - discover suite from phpunit.xml[.dist] / Pest.php / tests/ layout
        //  - for each test file: pick FrontEnd via handles(); $frontEnd->parse($path,$src)
        //  - flatten TestFileRecord->methods into TestObservation rows
        // The extraction core (FrontEnds + extractors) is already built and fixture-proven;
        // this command is the thin I/O wiring around it.
        $this->warn('Not yet implemented — see ROADMAP.md M1/M2.');

        return self::SUCCESS;
    }
}
