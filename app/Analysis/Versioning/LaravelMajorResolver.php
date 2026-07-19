<?php

declare(strict_types=1);

namespace App\Analysis\Versioning;

/**
 * Resolves a composer version constraint on laravel/framework (fallback illuminate/support)
 * to an integer Laravel major — the checkpoint unit of Instrument A.
 *
 * Decision locked in the phase spec: where a constraint admits several majors — ranges
 * (">=9 <11") and multi-constraints ("^10|^11") — the MINIMUM major wins: it is the
 * earliest version the application still claims compatibility with, so a transition-era
 * commit is attributed to the era it was still supporting. Branch-only constraints
 * (dev-master, "*") resolve to null and the commit is skipped (and logged by the caller).
 */
final class LaravelMajorResolver
{
    public function resolve(?string $constraint): ?int
    {
        if ($constraint === null || trim($constraint) === '') {
            return null;
        }

        preg_match_all('/\bv?(\d+)(?:\.[\dx*]+)*/i', $constraint, $matches);
        $majors = array_map(intval(...), $matches[1]);

        return $majors === [] ? null : min($majors);
    }

    /**
     * @param  array<string, mixed>  $composer  decoded composer.json
     */
    public function resolveFromComposer(array $composer): ?int
    {
        $require = $composer['require'] ?? [];
        if (! is_array($require)) {
            return null;
        }

        $constraint = $require['laravel/framework'] ?? $require['illuminate/support'] ?? null;

        return $this->resolve(is_string($constraint) ? $constraint : null);
    }
}
