<?php

declare(strict_types=1);

namespace App\Analysis\Statistics;

/**
 * Cohen's kappa for the classifier validation (Appendix C): the test-type classifier's
 * labels against a human gold standard on a seeded random sample, drawn by
 * analyse:sample-types and scored by analyse:score-types. kappa = (p_o - p_e) / (1 - p_e),
 * where p_o is observed agreement and p_e chance agreement from the raters' marginal
 * distributions. The human labels blind to classifier output — the sample export
 * deliberately omits test_type — so the statistic quantifies agreement with an
 * independent coding, not agreement with itself.
 */
final class CohenKappa
{
    /**
     * @param  list<string>  $rater1  labels, index-aligned with $rater2
     * @param  list<string>  $rater2
     */
    public static function kappa(array $rater1, array $rater2): float
    {
        $n = count($rater1);
        if ($n === 0 || $n !== count($rater2)) {
            throw new \InvalidArgumentException('Both raters must label the same non-empty sample.');
        }

        $agreement = 0;
        $marginal1 = [];
        $marginal2 = [];
        foreach ($rater1 as $i => $label1) {
            $label2 = $rater2[$i];
            if ($label1 === $label2) {
                $agreement++;
            }
            $marginal1[$label1] = ($marginal1[$label1] ?? 0) + 1;
            $marginal2[$label2] = ($marginal2[$label2] ?? 0) + 1;
        }

        $observed = $agreement / $n;

        $expected = 0.0;
        foreach ($marginal1 as $label => $count1) {
            $expected += ($count1 / $n) * (($marginal2[$label] ?? 0) / $n);
        }

        if ($expected >= 1.0) {
            return 1.0; // both raters used a single identical label throughout
        }

        return ($observed - $expected) / (1 - $expected);
    }

    /** Landis & Koch (1977) agreement bands, for reporting alongside the raw kappa. */
    public static function interpret(float $kappa): string
    {
        return match (true) {
            $kappa < 0.0 => 'poor',
            $kappa <= 0.20 => 'slight',
            $kappa <= 0.40 => 'fair',
            $kappa <= 0.60 => 'moderate',
            $kappa <= 0.80 => 'substantial',
            default => 'almost perfect',
        };
    }
}
