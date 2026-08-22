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

        $ranks = Ranking::averageRanks(array_merge($group1, $group2));
        $r1 = array_sum(array_slice($ranks, 0, $n1));

        $u1 = $r1 - ($n1 * ($n1 + 1)) / 2;
        $u2 = $n1 * $n2 - $u1;
        $u = min($u1, $u2);

        $meanU = ($n1 * $n2) / 2;
        $n = $n1 + $n2;

        // Tie-corrected standard deviation.
        $tieTerm = 0.0;
        foreach (Ranking::tieGroupSizes(array_merge($group1, $group2)) as $t) {
            $tieTerm += ($t ** 3 - $t);
        }
        $sigma = sqrt(($n1 * $n2 / 12) * (($n + 1) - $tieTerm / ($n * ($n - 1))));

        $z = $sigma > 0 ? ($u - $meanU) / $sigma : 0.0;

        return ['u' => (float) $u, 'z' => $z, 'p' => StandardNormal::twoSidedP($z)];
    }
}
