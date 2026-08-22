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

    // Sensitivity series for the era boundary (Section V-D). A result that does not survive
    // the series is reported as unstable.
    'ai_cutoff_series' => [
        'pest_1.0' => '2021-01-01',  // Pest 1.0, Jan 2021 — ref [7]
        'copilot_ga' => '2022-06-21',  // ref [26]
        'laravel_10' => '2023-02-14',  // confirmed by the author against ref [5]
        'autonomous_agents' => '2025-01-01',  // "adopted in 2025" per Section V-D and ref [10]
    ],

    // Recognised coding-agent identities, matched case-insensitively as substrings against the
    // commit author, committer and co-authorship trailers. Pattern set follows Hora & Robbes
    // (MSR 2026), who report 100% precision on manual inspection of 500 agent commits.
    'agent_patterns' => [
        'claude', 'cursor', 'copilot', 'aider', 'openhands', 'devin',
        'jules', 'cline', 'junie', 'gemini', 'coderabbit', 'windsurf',
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
