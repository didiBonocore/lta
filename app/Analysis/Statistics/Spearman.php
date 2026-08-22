<?php

declare(strict_types=1);

namespace App\Analysis\Statistics;

/**
 * Spearman's rank correlation: Pearson correlation over average ranks (shared Ranking
 * helper, so ties are handled identically to every other rank-based statistic here).
 *
 * The p-value uses the Fisher transformation with Fieller's variance correction for
 * Spearman: z = atanh(rho) · √((n−3)/1.06), two-sided. Small-n rule: the approximation is
 * unreliable on the short per-repository series (often n = 3–5), so ReportCommand
 * suppresses the per-repository p below n = 10 (prints `—`); rho remains a descriptive
 * direction-and-strength summary, and inference happens at the aggregate level.
 */
final class Spearman
{
    /**
     * @param  list<int|float>  $x
     * @param  list<int|float>  $y  index-aligned with $x
     * @return array{rho: float, p: float, n: int} two-sided p
     */
    public static function test(array $x, array $y): array
    {
        $n = count($x);
        if ($n === 0 || $n !== count($y)) {
            throw new \InvalidArgumentException('Both series must be non-empty and index-aligned.');
        }

        $rx = Ranking::averageRanks(array_map(floatval(...), $x));
        $ry = Ranking::averageRanks(array_map(floatval(...), $y));

        $meanX = array_sum($rx) / $n;
        $meanY = array_sum($ry) / $n;

        $covariance = 0.0;
        $varX = 0.0;
        $varY = 0.0;
        for ($i = 0; $i < $n; $i++) {
            $covariance += ($rx[$i] - $meanX) * ($ry[$i] - $meanY);
            $varX += ($rx[$i] - $meanX) ** 2;
            $varY += ($ry[$i] - $meanY) ** 2;
        }

        if ($varX == 0.0 || $varY == 0.0) {
            return ['rho' => 0.0, 'p' => 1.0, 'n' => $n];
        }

        $rho = $covariance / sqrt($varX * $varY);

        if ($n < 4) {
            return ['rho' => $rho, 'p' => 1.0, 'n' => $n];
        }
        if (abs($rho) >= 1.0) {
            return ['rho' => $rho, 'p' => 0.0, 'n' => $n];
        }

        $z = atanh($rho) * sqrt(($n - 3) / 1.06);

        return ['rho' => $rho, 'p' => StandardNormal::twoSidedP($z), 'n' => $n];
    }
}
