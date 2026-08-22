<?php

declare(strict_types=1);

namespace App\Analysis\Statistics;

/**
 * Theil-Sen slope estimator: the median of all pairwise slopes (y_j − y_i)/(x_j − x_i)
 * over i < j with x_j ≠ x_i.
 *
 * This is the per-repository slope the design's two sentences jointly require: "trend
 * analysis uses per-repository slopes" and "non-parametric methods are used throughout".
 * Theil-Sen is the rank-based slope that satisfies both; a per-repository OLS slope would
 * satisfy neither. The pooled OLS fit (SimpleLinearRegression) remains descriptive only.
 */
final class TheilSen
{
    /**
     * @param  list<array{0: float, 1: float}>  $points  [x, y] pairs
     * @return array{slope: float, n_pairs: int}
     */
    public static function estimate(array $points): array
    {
        $slopes = [];
        $n = count($points);
        for ($i = 0; $i < $n; $i++) {
            for ($j = $i + 1; $j < $n; $j++) {
                if ($points[$j][0] !== $points[$i][0]) {
                    $slopes[] = ($points[$j][1] - $points[$i][1]) / ($points[$j][0] - $points[$i][0]);
                }
            }
        }

        if ($slopes === []) {
            return ['slope' => 0.0, 'n_pairs' => 0];
        }

        sort($slopes);
        $count = count($slopes);
        $middle = intdiv($count, 2);
        $median = $count % 2 === 1 ? $slopes[$middle] : ($slopes[$middle - 1] + $slopes[$middle]) / 2;

        return ['slope' => $median, 'n_pairs' => $count];
    }
}
