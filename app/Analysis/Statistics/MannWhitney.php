<?php

declare(strict_types=1);

namespace App\Analysis\Statistics;

/**
 * Mann-Whitney U with tie-corrected normal approximation, for the pre/post-AI group
 * comparison. Non-parametric because assertion/mock counts are skewed count data where a
 * t-test's normality assumption fails.
 *
 * Reports U and a two-sided p-value. The normal approximation is appropriate for n >~ 20 in
 * each group; for smaller samples an exact test is preferable — recorded as a limitation.
 * Pair with EffectSize::cliffsDelta() so significance is always reported alongside magnitude.
 */
final class MannWhitney
{
    /**
     * @param  list<int|float>  $group1
     * @param  list<int|float>  $group2
     * @return array{u: float, z: float, p: float}
     */
    public static function test(array $group1, array $group2): array
    {
        $n1 = count($group1);
        $n2 = count($group2);
        if ($n1 === 0 || $n2 === 0) {
            return ['u' => 0.0, 'z' => 0.0, 'p' => 1.0];
        }

        $ranks = self::rank(array_merge($group1, $group2));
        $r1 = array_sum(array_slice($ranks, 0, $n1));

        $u1 = $r1 - ($n1 * ($n1 + 1)) / 2;
        $u2 = $n1 * $n2 - $u1;
        $u = min($u1, $u2);

        $meanU = ($n1 * $n2) / 2;
        $n = $n1 + $n2;

        // Tie-corrected standard deviation.
        $tieTerm = 0.0;
        foreach (self::tieGroupSizes(array_merge($group1, $group2)) as $t) {
            $tieTerm += ($t ** 3 - $t);
        }
        $sigma = sqrt(($n1 * $n2 / 12) * (($n + 1) - $tieTerm / ($n * ($n - 1))));

        $z = $sigma > 0 ? ($u - $meanU) / $sigma : 0.0;
        $p = 2 * (1 - self::phi(abs($z)));

        return ['u' => (float) $u, 'z' => $z, 'p' => max(0.0, min(1.0, $p))];
    }

    /**
     * Average-rank assignment with tie handling.
     *
     * @param  list<int|float>  $values
     * @return array<int, float> rank per input index
     */
    private static function rank(array $values): array
    {
        $indexed = [];
        foreach ($values as $i => $v) {
            $indexed[] = ['i' => $i, 'v' => $v];
        }
        usort($indexed, static fn ($a, $b) => $a['v'] <=> $b['v']);

        $ranks = array_fill(0, count($values), 0.0);
        $k = 0;
        $count = count($indexed);
        while ($k < $count) {
            $j = $k;
            while ($j + 1 < $count && $indexed[$j + 1]['v'] === $indexed[$k]['v']) {
                $j++;
            }
            $avgRank = (float) ((($k + 1) + ($j + 1)) / 2); // ranks are 1-based
            for ($m = $k; $m <= $j; $m++) {
                $ranks[$indexed[$m]['i']] = $avgRank;
            }
            $k = $j + 1;
        }

        return $ranks;
    }

    /**
     * @param  list<int|float>  $values
     * @return list<int> sizes of tie groups with size > 1
     */
    private static function tieGroupSizes(array $values): array
    {
        $counts = [];
        foreach ($values as $v) {
            $key = (string) $v;
            $counts[$key] = ($counts[$key] ?? 0) + 1;
        }

        return array_values(array_filter($counts, static fn (int $c): bool => $c > 1));
    }

    /** Standard normal CDF via the Abramowitz-Stegun erf approximation. */
    private static function phi(float $x): float
    {
        return 0.5 * (1 + self::erf($x / sqrt(2)));
    }

    private static function erf(float $x): float
    {
        $t = 1 / (1 + 0.3275911 * abs($x));
        $y = 1 - ((((1.061405429 * $t - 1.453152027) * $t + 1.421413741) * $t
            - 0.284496736) * $t + 0.254829592) * $t * exp(-$x * $x);

        return $x >= 0 ? $y : -$y;
    }
}
