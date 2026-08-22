<?php

declare(strict_types=1);

namespace App\Analysis\Statistics;

/**
 * Benjamini-Hochberg step-up false-discovery-rate control over the exploratory comparison
 * family. Sort the m p-values ascending; the largest k with p_(k) ≤ (k/m)·alpha rejects
 * every comparison up to k. Adjusted q-values are monotone-enforced
 * (q_(i) = min over j ≥ i of (m/j)·p_(j)) and capped at 1.0.
 *
 * Pre-registered primary comparisons (config('analyser.primary_metrics')) never enter this
 * adjustment — they are reported unadjusted, and everything else is treated as exploratory.
 */
final class BenjaminiHochberg
{
    /**
     * @param  array<string, float>  $pValues  keyed by comparison label
     * @return array<string, array{p: float, q: float, rejected: bool}> in input-key order
     */
    public static function adjust(array $pValues, float $alpha = 0.05): array
    {
        $m = count($pValues);
        if ($m === 0) {
            return [];
        }

        $sorted = $pValues;
        asort($sorted);
        $sortedKeys = array_keys($sorted);
        $sortedP = array_values($sorted);

        $largestK = 0;
        for ($i = 0; $i < $m; $i++) {
            if ($sortedP[$i] <= (($i + 1) / $m) * $alpha) {
                $largestK = $i + 1;
            }
        }

        $q = array_fill(0, $m, 0.0);
        $running = 1.0;
        for ($i = $m - 1; $i >= 0; $i--) {
            $running = min($running, ($m / ($i + 1)) * $sortedP[$i]);
            $q[$i] = min(1.0, $running);
        }

        $byKey = [];
        foreach ($sortedKeys as $i => $key) {
            $byKey[$key] = ['p' => $sortedP[$i], 'q' => $q[$i], 'rejected' => $i < $largestK];
        }

        // Preserve the caller's key order.
        $result = [];
        foreach (array_keys($pValues) as $key) {
            $result[$key] = $byKey[$key];
        }

        return $result;
    }
}
