<?php

declare(strict_types=1);

return [
    // Pre/post-AI boundary for Instrument B (SRQ3). Commit-author-date based — never
    // framework-version based. The exact date is pending supervisor confirmation; this is
    // the default, and `--cutoff=` on analyse:blame / analyse:report is the override for
    // sensitivity runs.
    'ai_cutoff' => env('ANALYSER_AI_CUTOFF', '2022-11-30'),
];
