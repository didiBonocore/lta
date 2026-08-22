<?php

declare(strict_types=1);

namespace App\Analysis\Statistics;

/**
 * Wilcoxon signed-rank test for paired samples — the primary test for H1, H3a and H3b, and
 * the aggregate-level test for the trend hypotheses (per-repository Theil-Sen slopes
 * against zero).
 *
 * Zero differences are dropped (Wilcoxon's original treatment) and their count returned so
 * it can be reported — for H1 the dropped zeros ARE a result (repositories that never adopt
 * Pest). Absolute differences are ranked with average-rank tie handling (shared Ranking
 * helper); W = min(W+, W−).
 *
 * p-value: for n ≤ 20 pairs, exact and two-sided by enumerating all 2^n sign assignments
 * and counting those with min(W+, W−) ≤ the observed W (2^20 ≈ 1M, fine — and the corpus
 * is small, so this is the branch that actually runs). For n > 20, normal approximation
 * with continuity and tie correction.
 *
 * Effect size: matched-pairs rank-biserial correlation r = (W+ − W−)/(W+ + W−), positive
 * when the first sample tends to exceed the second.
 */
final class WilcoxonSignedRank
{
    private const int EXACT_LIMIT = 20;

    /**
     * @param  list<int|float>  $x
     * @param  list<int|float>  $y  index-aligned with $x; differences are x_i − y_i
     * @return array{w: float, p: float, n_pairs: int, n_dropped: int, rank_biserial: float, exact: bool}
     */
    public static function test(array $x, array $y): array
    {
        if (count($x) !== count($y)) {
            throw new \InvalidArgumentException('Both samples must be index-aligned pairs.');
        }

        $differences = [];
        $dropped = 0;
        foreach ($x as $i => $value) {
            $difference = (float) $value - (float) $y[$i];
            if ($difference == 0.0) {
                $dropped++;

                continue;
            }
            $differences[] = $difference;
        }

        $n = count($differences);
        if ($n === 0) {
            return ['w' => 0.0, 'p' => 1.0, 'n_pairs' => 0, 'n_dropped' => $dropped, 'rank_biserial' => 0.0, 'exact' => true];
        }

        $ranks = Ranking::averageRanks(array_map(abs(...), $differences));

        $wPlus = 0.0;
        $wMinus = 0.0;
        foreach ($differences as $i => $difference) {
            if ($difference > 0) {
                $wPlus += $ranks[$i];
            } else {
                $wMinus += $ranks[$i];
            }
        }
        $w = min($wPlus, $wMinus);
        $rankBiserial = ($wPlus - $wMinus) / ($wPlus + $wMinus);

        $exact = $n <= self::EXACT_LIMIT;
        $p = $exact
            ? self::exactP($ranks, $w)
            : self::approximateP($differences, $ranks, $w, $n);

        return [
            'w' => $w,
            'p' => $p,
            'n_pairs' => $n,
            'n_dropped' => $dropped,
            'rank_biserial' => $rankBiserial,
            'exact' => $exact,
        ];
    }

    /**
     * Exact two-sided p: the proportion of the 2^n equiprobable sign assignments whose
     * min(W+, W−) is at least as extreme as the observed W. Well-defined under ties because
     * the enumeration uses the same average ranks as the observed statistic.
     *
     * @param  array<int, float>  $ranks
     */
    private static function exactP(array $ranks, float $w): float
    {
        $n = count($ranks);
        $total = array_sum($ranks);
        $assignments = 1 << $n;
        $extreme = 0;

        for ($mask = 0; $mask < $assignments; $mask++) {
            $assignedPlus = 0.0;
            for ($bit = 0; $bit < $n; $bit++) {
                if (($mask >> $bit) & 1) {
                    $assignedPlus += $ranks[$bit];
                }
            }
            if (min($assignedPlus, $total - $assignedPlus) <= $w + 1e-9) {
                $extreme++;
            }
        }

        return $extreme / $assignments;
    }

    /**
     * Normal approximation with continuity correction (toward the mean) and tie-corrected
     * variance: Var = n(n+1)(2n+1)/24 − Σ(t³−t)/48 over tie groups of |differences|.
     *
     * @param  list<float>  $differences
     * @param  array<int, float>  $ranks
     */
    private static function approximateP(array $differences, array $ranks, float $w, int $n): float
    {
        $mean = $n * ($n + 1) / 4;

        $tieTerm = 0;
        foreach (Ranking::tieGroupSizes(array_map(abs(...), $differences)) as $t) {
            $tieTerm += $t ** 3 - $t;
        }
        $variance = $n * ($n + 1) * (2 * $n + 1) / 24 - $tieTerm / 48;
        if ($variance <= 0) {
            return 1.0;
        }

        $continuity = $w < $mean ? 0.5 : ($w > $mean ? -0.5 : 0.0);
        $z = ($w - $mean + $continuity) / sqrt($variance);

        return StandardNormal::twoSidedP($z);
    }
}
