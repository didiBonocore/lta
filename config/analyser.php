<?php

declare(strict_types=1);

return [
    // Pre/post-AI boundary for Instrument B (SRQ3). Anchored to GitHub Copilot's
    // general-availability date, when AI code generation entered developer editors at scale.
    'ai_cutoff' => '2022-06-21',
    // Robustness re-run only: ChatGPT public launch.
    'ai_cutoff_sensitivity' => '2022-11-30',
];
