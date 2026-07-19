<?php

declare(strict_types=1);

namespace App\Analysis\Statistics;

/**
 * Closed-form ordinary least squares for one predictor: slope = cov(x,y)/var(x).
 *
 * Hand-implemented for the same reason as MannWhitney/EffectSize — and out of necessity:
 * MathPHP's Regression\Linear materialises the n×n hat matrix (reg_P), which is O(n²)
 * memory and exhausts PHP at observation-level n (~7k points ≈ 48M array cells). The
 * closed form is O(n) and reproduces identical estimates; MathPHP remains in use for
 * descriptive statistics.
 */
final class SimpleLinearRegression
{
    /**
     * @param  list<array{0: float, 1: float}>  $points  [x, y] pairs
     * @return array{slope: float, intercept: float, r2: float, n: int}
     */
    public static function fit(array $points): array
    {
        $n = count($points);
        if ($n < 2) {
            return ['slope' => 0.0, 'intercept' => 0.0, 'r2' => 0.0, 'n' => $n];
        }

        $meanX = array_sum(array_column($points, 0)) / $n;
        $meanY = array_sum(array_column($points, 1)) / $n;

        $covXY = 0.0;
        $varX = 0.0;
        foreach ($points as [$x, $y]) {
            $covXY += ($x - $meanX) * ($y - $meanY);
            $varX += ($x - $meanX) ** 2;
        }

        if ($varX == 0.0) {
            return ['slope' => 0.0, 'intercept' => $meanY, 'r2' => 0.0, 'n' => $n];
        }

        $slope = $covXY / $varX;
        $intercept = $meanY - $slope * $meanX;

        $ssResidual = 0.0;
        $ssTotal = 0.0;
        foreach ($points as [$x, $y]) {
            $ssResidual += ($y - ($slope * $x + $intercept)) ** 2;
            $ssTotal += ($y - $meanY) ** 2;
        }

        return [
            'slope' => $slope,
            'intercept' => $intercept,
            'r2' => $ssTotal == 0.0 ? 1.0 : 1 - $ssResidual / $ssTotal,
            'n' => $n,
        ];
    }
}
