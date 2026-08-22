<?php

declare(strict_types=1);

return [
    // Pre/post-AI boundary for Instrument B (SRQ3). Commit-author-date based — never
    // framework-version based. Set to GitHub Copilot General Availability launch (2022-06-21).
    // Override via ANALYSER_AI_CUTOFF or `--cutoff=` on analyse:blame / analyse:report.
    'ai_cutoff' => env('ANALYSER_AI_CUTOFF', '2022-06-21'),

    // Pre-registered primary metric per hypothesis (Section V-E). Everything else is
    // exploratory and Benjamini-Hochberg adjusted. In config so the pre-registration is
    // version-controlled rather than asserted in prose.
    'primary_metrics' => [
        'H1' => 'paradigm_pf',
        'H2a' => 'mock_breadth',
        'H2b' => 'test_assertion_count',
        'H3a' => 'mock_breadth',
        'H3b' => 'mock_assertion_ratio',
    ],

    // Laravel facade-fake verification methods classified as interaction verification (mock assertions).
    'facade_mock_assertions' => [
        'Event' => ['assertDispatched', 'assertNotDispatched', 'assertNothingDispatched'],
        'Queue' => ['assertPushed', 'assertNotPushed', 'assertPushedOn'],
        'Mail' => ['assertSent', 'assertQueued', 'assertNotSent', 'assertNothingSent'],
        'Notification' => ['assertSentTo', 'assertNotSentTo', 'assertNothingSent'],
        'Bus' => ['assertDispatched', 'assertNotDispatched', 'assertChained', 'assertBatched'],
        'Http' => ['assertSent', 'assertNotSent', 'assertSentCount', 'assertNothingSent'],
        'Storage' => ['assertExists', 'assertMissing'],
        'Process' => ['assertRan', 'assertDidntRun'],
        'Sleep' => ['assertSlept', 'assertSleptTimes', 'assertSleptWith', 'assertSleptWithAny'],
    ],
];
