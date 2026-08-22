<?php

declare(strict_types=1);

namespace App\Analysis\Statistics;

/**
 * Mann-Kendall trend test for monotonic trend in an ordered series (per-repository
 * major → median series, Instrument A).
 *
 *   S      = Σ_{i<j} sign(x_j − x_i)
 *   Var(S) = [ n(n−1)(2n+5) − Σ_t t(t−1)(2t+5) ] / 18   (t = size of each tie group in y)
 *   Z      = (S−1)/√Var(S) if S > 0;  (S+1)/√Var(S) if S < 0;  0 if S = 0
 *   tau_b  = S / √[ (n(n−1)/2 − T_x)(n(n−1)/2 − T_y) ]  with T = Σ t(t−1)/2 per tie group
 *
 * The series is passed ordered by a strictly increasing x (integer Laravel majors), so ties
 * can occur only in y and T_x = 0.
 *
 * Small-n constraint: the corpus criterion requires only three integer majors per
 * repository, so per-repository series are often n = 3–5, where the normal approximation
 * behind p is unreliable. At the per-repository level tau is a descriptive
 * direction-and-strength summary only; ReportCommand suppresses the per-repository p-value
 * below n = 10 (prints `—`). Inference happens at the aggregate level via the Wilcoxon
 * signed-rank test over per-repository Theil-Sen slopes.
 */
final class MannKendall
{
    /**
     * @param  list<int|float>  $series  y values ordered by strictly increasing x
     * @return array{s: int, var: float, z: float, p: float, tau: float, n: int} two-sided p
     */
    public static function test(array $series): array
    {
        $n = count($series);
        if ($n < 2) {
            return ['s' => 0, 'var' => 0.0, 'z' => 0.0, 'p' => 1.0, 'tau' => 0.0, 'n' => $n];
        }

        $s = 0;
        for ($i = 0; $i < $n; $i++) {
            for ($j = $i + 1; $j < $n; $j++) {
                $s += $series[$j] <=> $series[$i];
            }
        }

        $tieTerm = 0;
        $tiedPairsY = 0;
        foreach (Ranking::tieGroupSizes($series) as $t) {
            $tieTerm += $t * ($t - 1) * (2 * $t + 5);
            $tiedPairsY += $t * ($t - 1) / 2;
        }
        $var = ($n * ($n - 1) * (2 * $n + 5) - $tieTerm) / 18;

        $z = 0.0;
        if ($var > 0 && $s !== 0) {
            $z = $s > 0 ? ($s - 1) / sqrt($var) : ($s + 1) / sqrt($var);
        }

        $pairs = $n * ($n - 1) / 2;
        $denominator = sqrt(($pairs - 0) * ($pairs - $tiedPairsY)); // T_x = 0: x strictly increasing
        $tau = $denominator > 0 ? $s / $denominator : 0.0;

        return [
            's' => $s,
            'var' => (float) $var,
            'z' => $z,
            'p' => StandardNormal::twoSidedP($z),
            'tau' => $tau,
            'n' => $n,
        ];
    }
}
