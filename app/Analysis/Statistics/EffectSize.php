<?php

namespace App\Analysis\Statistics;

/**
 * Cliff's delta — a non-parametric effect size for the pre/post-AI comparison.
 *
 * delta = (#{a > b} - #{a < b}) / (n_a * n_b), over all cross pairs. Range [-1, 1];
 * magnitude is independent of the (skewed) distribution shape, which is exactly why it
 * suits assertion/mock count data. Hand-implemented and unit-tested on purpose: a
 * self-implemented, tested effect size is a stronger position on the page than an opaque
 * library call.
 *
 * Magnitude thresholds (Romano et al., 2006): |d|<0.147 negligible, <0.33 small,
 * <0.474 medium, else large.
 */
final class EffectSize
{
    /**
     * @param list<int|float> $a
     * @param list<int|float> $b
     */
    public static function cliffsDelta(array $a, array $b): float
    {
        $na = count($a);
        $nb = count($b);
        if ($na === 0 || $nb === 0) {
            return 0.0;
        }

        $greater = 0;
        $less = 0;
        foreach ($a as $x) {
            foreach ($b as $y) {
                if ($x > $y) {
                    $greater++;
                } elseif ($x < $y) {
                    $less++;
                }
            }
        }

        return ($greater - $less) / ($na * $nb);
    }

    public static function interpret(float $delta): string
    {
        $d = abs($delta);

        return match (true) {
            $d < 0.147 => 'negligible',
            $d < 0.33  => 'small',
            $d < 0.474 => 'medium',
            default    => 'large',
        };
    }
}
