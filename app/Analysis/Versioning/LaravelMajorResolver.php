<?php

declare(strict_types=1);

namespace App\Analysis\Versioning;

/**
 * Resolves a composer version constraint on laravel/framework (fallback illuminate/support)
 * to an integer Laravel major — the checkpoint unit of Instrument A. Where a constraint
 * allows several majors ("^9.0|^10.0"), the highest is taken, matching what composer would
 * install. Branch-only constraints (dev-master) resolve to null and the commit is skipped.
 */
final class LaravelMajorResolver
{
    public function resolve(?string $constraint): ?int
    {
        if ($constraint === null || trim($constraint) === '') {
            return null;
        }

        $majors = [];
        foreach (preg_split('/\|\|?/', $constraint) ?: [] as $part) {
            $major = $this->majorOfPart(trim($part));
            if ($major !== null) {
                $majors[] = $major;
            }
        }

        return $majors === [] ? null : max($majors);
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

    private function majorOfPart(string $part): ?int
    {
        // First version-shaped token wins: in a range like ">=5.5 <6.0" the lower bound is
        // the version the application actually targets.
        if (preg_match('/v?(\d+)(?:\.[\dx*]+)*/i', $part, $matches) !== 1) {
            return null;
        }

        return (int) $matches[1];
    }
}
