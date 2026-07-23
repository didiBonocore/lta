<?php

declare(strict_types=1);

namespace App\Analysis\Reporting;

use Illuminate\Support\Facades\Process;

/**
 * Provenance stamp for report output: the exact tool revision that produced a dataset,
 * resolved from `git describe` so a run at the release tag reads `v0.1.0`, a run past it
 * reads `v0.1.0-<n>-g<sha>`, and an uncommitted tree is flagged `-dirty`. Falls back to
 * the baked release when git metadata is unavailable (e.g. a source archive with no .git).
 */
final class ToolVersion
{
    /** Baked release floor — the value reported when `git describe` cannot resolve. */
    public const string RELEASE = 'v0.2.0';

    public static function resolve(): string
    {
        $result = Process::path(base_path())->run(['git', 'describe', '--tags', '--always', '--dirty']);
        $described = trim($result->output());

        return $result->successful() && $described !== ''
            ? $described
            : self::RELEASE;
    }
}
