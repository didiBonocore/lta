<?php

declare(strict_types=1);

namespace App\Analysis\Statistics;

/**
 * Standard normal CDF via the Abramowitz-Stegun erf approximation (7.1.26, max absolute
 * error ~1.5e-7). Shared by every test that reports a p-value through a normal
 * approximation, so all of them agree on the same tail arithmetic.
 */
final class StandardNormal
{
    public static function cdf(float $x): float
    {
        return 0.5 * (1 + self::erf($x / sqrt(2)));
    }

    /** Two-sided p-value for a z statistic, clamped into [0, 1]. */
    public static function twoSidedP(float $z): float
    {
        if ($z === 0.0) {
            return 1.0; // exact — the erf polynomial is off by ~1e-9 at the origin
        }

        $p = 2 * (1 - self::cdf(abs($z)));

        return max(0.0, min(1.0, $p));
    }

    private static function erf(float $x): float
    {
        $t = 1 / (1 + 0.3275911 * abs($x));
        $y = 1 - ((((1.061405429 * $t - 1.453152027) * $t + 1.421413741) * $t
            - 0.284496736) * $t + 0.254829592) * $t * exp(-$x * $x);

        return $x >= 0 ? $y : -$y;
    }
}
