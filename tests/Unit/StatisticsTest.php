<?php

declare(strict_types=1);

use App\Analysis\Statistics\BenjaminiHochberg;
use App\Analysis\Statistics\CohenKappa;
use App\Analysis\Statistics\EffectSize;
use App\Analysis\Statistics\MannKendall;
use App\Analysis\Statistics\MannWhitney;
use App\Analysis\Statistics\Ranking;
use App\Analysis\Statistics\SimpleLinearRegression;
use App\Analysis\Statistics\Spearman;
use App\Analysis\Statistics\TheilSen;
use App\Analysis\Statistics\WilcoxonSignedRank;

/**
 * Known-answer tests for the hand-implemented non-parametric pieces (the same validation
 * posture as the extraction fixtures: every statistic reproduces a hand-computed value).
 */
it('computes Cliff\'s delta on hand-computed pairs', function () {
    // All 6 cross pairs greater: (3,4,5) vs (1,2) => +1.
    expect(EffectSize::cliffsDelta([3, 4, 5], [1, 2]))->toBe(1.0)
        // Mirror image => -1.
        ->and(EffectSize::cliffsDelta([1, 2], [3, 4, 5]))->toBe(-1.0)
        // Identical distributions => 0.
        ->and(EffectSize::cliffsDelta([1, 2, 3], [1, 2, 3]))->toBe(0.0)
        // Hand-counted: greater=0, less=8, ties=1 over 9 pairs => -8/9.
        ->and(EffectSize::cliffsDelta([1, 1, 2], [2, 3, 3]))->toEqualWithDelta(-8 / 9, 1e-12);
});

it('interprets delta magnitudes on the Romano thresholds', function () {
    expect(EffectSize::interpret(0.1))->toBe('negligible')
        ->and(EffectSize::interpret(-0.2))->toBe('small')
        ->and(EffectSize::interpret(0.4))->toBe('medium')
        ->and(EffectSize::interpret(-0.9))->toBe('large');
});

it('computes Mann-Whitney U on fully separated groups (hand-computed U=0)', function () {
    $result = MannWhitney::test([1, 2, 3, 4, 5], [6, 7, 8, 9, 10]);

    // R1 = 15 => U1 = 0, U2 = 25 => U = 0; sigma = sqrt(25*11/12); z = -12.5/sigma.
    expect($result['u'])->toBe(0.0)
        ->and($result['z'])->toEqualWithDelta(-12.5 / sqrt(25 * 11 / 12), 1e-9)
        ->and($result['p'])->toBeLessThan(0.01);
});

it('computes Mann-Whitney U with ties (hand-computed U=0.5)', function () {
    // Combined [1,1,2,2,3,3] ranks: 1.5,1.5,3.5,3.5,5.5,5.5 => R1 = 6.5 => U1 = 0.5.
    $result = MannWhitney::test([1, 1, 2], [2, 3, 3]);

    expect($result['u'])->toBe(0.5);
});

it('reports no effect for identical groups and degenerate input', function () {
    $identical = MannWhitney::test([2, 2, 2], [2, 2, 2]);

    expect($identical['z'])->toBe(0.0)
        ->and($identical['p'])->toEqualWithDelta(1.0, 1e-6) // erf approximation error ~1.5e-7
        ->and(MannWhitney::test([], [1, 2])['p'])->toBe(1.0)
        ->and(EffectSize::cliffsDelta([], []))->toBe(0.0);
});

it('computes Cohen\'s kappa on hand-computed label sets', function () {
    // Observed agreement 8/10, balanced marginals => p_e = 0.5 => kappa = 0.6.
    $rater1 = ['a', 'a', 'a', 'a', 'a', 'b', 'b', 'b', 'b', 'b'];
    $rater2 = ['a', 'a', 'a', 'a', 'b', 'b', 'b', 'b', 'b', 'a'];

    expect(CohenKappa::kappa($rater1, $rater2))->toEqualWithDelta(0.6, 1e-12)
        // Perfect agreement => 1.
        ->and(CohenKappa::kappa(['u', 'f', 'i'], ['u', 'f', 'i']))->toBe(1.0)
        // Chance-level agreement => 0.
        ->and(CohenKappa::kappa(['a', 'b', 'a', 'b'], ['a', 'a', 'b', 'b']))->toEqualWithDelta(0.0, 1e-12);
});

it('interprets kappa on the Landis-Koch bands and rejects misaligned samples', function () {
    expect(CohenKappa::interpret(0.65))->toBe('substantial')
        ->and(CohenKappa::interpret(0.9))->toBe('almost perfect')
        ->and(CohenKappa::interpret(0.1))->toBe('slight')
        ->and(fn () => CohenKappa::kappa(['a'], []))->toThrow(InvalidArgumentException::class);
});

it('fits ordinary least squares on hand-computed points', function () {
    // Same dataset the report test seeds: slope 2, intercept -16, r² = 1 - 4/10 = 0.6.
    $fit = SimpleLinearRegression::fit([[9, 1], [9, 2], [9, 3], [10, 3], [10, 4], [10, 5]]);

    expect($fit['slope'])->toEqualWithDelta(2.0, 1e-12)
        ->and($fit['intercept'])->toEqualWithDelta(-16.0, 1e-12)
        ->and($fit['r2'])->toEqualWithDelta(0.6, 1e-12)
        ->and($fit['n'])->toBe(6);
});

it('degrades gracefully on degenerate regression input', function () {
    // Perfect fit => r² = 1.
    expect(SimpleLinearRegression::fit([[1, 2], [2, 4], [3, 6]])['r2'])->toEqualWithDelta(1.0, 1e-12)
        // A single x value has no slope to estimate.
        ->and(SimpleLinearRegression::fit([[5, 1], [5, 9]])['slope'])->toBe(0.0)
        ->and(SimpleLinearRegression::fit([])['n'])->toBe(0);
});

it('assigns average ranks to ties through the shared Ranking helper', function () {
    // [10, 20, 20, 30]: the tied 20s span ranks 2 and 3 => both get 2.5.
    expect(Ranking::averageRanks([10.0, 20.0, 20.0, 30.0]))->toBe([1.0, 2.5, 2.5, 4.0])
        ->and(Ranking::tieGroupSizes([10.0, 20.0, 20.0, 30.0]))->toBe([2]);
});

it('computes Mann-Kendall on a strictly increasing series (hand-computed S=6)', function () {
    // [1,2,3,4]: all 6 pairs concordant => S = 6; Var = 4·3·13/18 = 26/3;
    // z = (6−1)/√(26/3) = 1.698415; p = 2(1−Φ(1.698415)) = 0.08943; tau = 6/6 = 1.
    $result = MannKendall::test([1, 2, 3, 4]);

    expect($result['s'])->toBe(6)
        ->and($result['var'])->toEqualWithDelta(26 / 3, 1e-9)
        ->and($result['z'])->toEqualWithDelta(5 / sqrt(26 / 3), 1e-9)
        ->and($result['p'])->toEqualWithDelta(0.0894, 1e-3)
        ->and($result['tau'])->toBe(1.0)
        ->and($result['n'])->toBe(4);
});

it('computes tie-corrected Mann-Kendall (hand-computed S=5, tau_b=5/√30)', function () {
    // [1,2,2,3]: S = 5 (one tied pair contributes 0). Tie group t=2 in y:
    // Var = (156 − 2·1·9)/18 = 138/18; z = (5−1)/√(138/18) = 1.444630.
    // tau_b = 5/√((6−0)(6−1)) = 5/√30 = 0.912871.
    $result = MannKendall::test([1, 2, 2, 3]);

    expect($result['s'])->toBe(5)
        ->and($result['var'])->toEqualWithDelta(138 / 18, 1e-9)
        ->and($result['z'])->toEqualWithDelta(4 / sqrt(138 / 18), 1e-9)
        ->and($result['tau'])->toEqualWithDelta(5 / sqrt(30), 1e-9);
});

it('mirrors Mann-Kendall for a decreasing series and degrades on degenerate input', function () {
    // [4,3,2,1]: S = −6; z = (−6+1)/√(26/3); tau = −1.
    $decreasing = MannKendall::test([4, 3, 2, 1]);

    expect($decreasing['s'])->toBe(-6)
        ->and($decreasing['z'])->toEqualWithDelta(-5 / sqrt(26 / 3), 1e-9)
        ->and($decreasing['tau'])->toBe(-1.0)
        // All-tied series: Var = (66 − 3·2·11)/18 = 0 => z = 0, tau = 0.
        ->and(MannKendall::test([2, 2, 2]))->toBe(['s' => 0, 'var' => 0.0, 'z' => 0.0, 'p' => 1.0, 'tau' => 0.0, 'n' => 3])
        ->and(MannKendall::test([7])['p'])->toBe(1.0);
});

it('computes the Theil-Sen slope as the median of pairwise slopes', function () {
    // [[1,1],[2,2],[3,4]]: slopes {1, 1.5, 2} => median 1.5 over 3 pairs.
    expect(TheilSen::estimate([[1.0, 1.0], [2.0, 2.0], [3.0, 4.0]]))->toBe(['slope' => 1.5, 'n_pairs' => 3]);

    // Even pair count: [[0,0],[1,1],[2,3],[3,3]] slopes sorted [0,1,1,1,1.5,2] => (1+1)/2 = 1.
    expect(TheilSen::estimate([[0.0, 0.0], [1.0, 1.0], [2.0, 3.0], [3.0, 3.0]]))->toBe(['slope' => 1.0, 'n_pairs' => 6]);
});

it('skips vertical pairs in Theil-Sen and degrades on degenerate input', function () {
    // [[1,1],[1,2],[2,3]]: the x-tied pair is skipped; slopes {2, 1} => median 1.5 over 2 pairs.
    expect(TheilSen::estimate([[1.0, 1.0], [1.0, 2.0], [2.0, 3.0]]))->toBe(['slope' => 1.5, 'n_pairs' => 2])
        ->and(TheilSen::estimate([[1.0, 5.0]]))->toBe(['slope' => 0.0, 'n_pairs' => 0])
        ->and(TheilSen::estimate([]))->toBe(['slope' => 0.0, 'n_pairs' => 0]);
});

it('computes Spearman rho with average-rank ties (hand-computed rho=4.5/√22.5)', function () {
    // x=[1,2,3,4] ranks [1,2,3,4]; y=[1,3,3,4] ranks [1,2.5,2.5,4]. Means 2.5/2.5.
    // cov = 2.25+0+0+2.25 = 4.5; varX = 5; varY = 4.5 => rho = 4.5/√22.5 = 0.9486833.
    // p (Fisher/Fieller): z = atanh(0.9486833)·√(1/1.06) = 1.766 => p ≈ 0.0773.
    $result = Spearman::test([1, 2, 3, 4], [1, 3, 3, 4]);

    expect($result['rho'])->toEqualWithDelta(4.5 / sqrt(22.5), 1e-9)
        ->and($result['p'])->toEqualWithDelta(0.0773, 1e-3)
        ->and($result['n'])->toBe(4);
});

it('reports perfect monotone association as rho ±1 and degrades on degenerate input', function () {
    expect(Spearman::test([1, 2, 3, 4, 5], [10, 20, 30, 40, 50]))->toBe(['rho' => 1.0, 'p' => 0.0, 'n' => 5])
        ->and(Spearman::test([1, 2, 3, 4], [9, 7, 5, 3])['rho'])->toBe(-1.0)
        // A constant series has no rank variance => rho 0, p 1.
        ->and(Spearman::test([1, 2, 3], [5, 5, 5]))->toBe(['rho' => 0.0, 'p' => 1.0, 'n' => 3])
        ->and(fn () => Spearman::test([1], []))->toThrow(InvalidArgumentException::class);
});

it('computes the exact Wilcoxon signed-rank p by hand-enumerated sign assignments', function () {
    // x=[3,1,4,6], y=[1,2,1,1]: d=[2,−1,3,5]; |d| ranks [2,1,3,4]. W+ = 9, W− = 1, W = 1.
    // Rank-sum total 10; assignments with min(W+,W−) ≤ 1 are subset sums {0,1,9,10} =>
    // 4 of 16 => p = 0.25. Rank-biserial = (9−1)/10 = 0.8.
    $result = WilcoxonSignedRank::test([3, 1, 4, 6], [1, 2, 1, 1]);

    expect($result['w'])->toBe(1.0)
        ->and($result['p'])->toBe(0.25)
        ->and($result['n_pairs'])->toBe(4)
        ->and($result['n_dropped'])->toBe(0)
        ->and($result['rank_biserial'])->toEqualWithDelta(0.8, 1e-12)
        ->and($result['exact'])->toBeTrue();
});

it('drops zero differences per Wilcoxon\'s original treatment and reports the count', function () {
    // x=[1,2,3], y=[1,1,1]: d=[0,1,2] => one zero dropped; ranks [1,2], W+ = 3, W− = 0.
    // Assignments with min ≤ 0 are sums {0, 3} => 2 of 4 => p = 0.5; rank-biserial = 1.
    $result = WilcoxonSignedRank::test([1, 2, 3], [1, 1, 1]);

    expect($result['w'])->toBe(0.0)
        ->and($result['p'])->toBe(0.5)
        ->and($result['n_pairs'])->toBe(2)
        ->and($result['n_dropped'])->toBe(1)
        ->and($result['rank_biserial'])->toBe(1.0);

    // All differences zero => nothing to test.
    expect(WilcoxonSignedRank::test([1, 1], [1, 1]))
        ->toBe(['w' => 0.0, 'p' => 1.0, 'n_pairs' => 0, 'n_dropped' => 2, 'rank_biserial' => 0.0, 'exact' => true]);
});

it('handles tied absolute differences in the exact Wilcoxon branch', function () {
    // x=[2,0,3], y=[0,2,0]: d=[2,−2,3]; |d| ranks [1.5,1.5,3]. W+ = 4.5, W− = 1.5, W = 1.5.
    // Subset sums over {1.5,1.5,3}: 0,1.5,1.5,3,3,4.5,4.5,6; min(s,6−s) ≤ 1.5 for
    // {0,1.5,1.5,4.5,4.5,6} => 6 of 8 => p = 0.75. Rank-biserial = 3/6 = 0.5.
    $result = WilcoxonSignedRank::test([2, 0, 3], [0, 2, 0]);

    expect($result['w'])->toBe(1.5)
        ->and($result['p'])->toBe(0.75)
        ->and($result['rank_biserial'])->toEqualWithDelta(0.5, 1e-12);
});

it('switches to the tie-corrected normal approximation above 20 pairs', function () {
    // 25 strictly positive differences 1..25: W− = 0 => W = 0; mean = 25·26/4 = 162.5;
    // Var = 25·26·51/24 = 1381.25; z = (0 − 162.5 + 0.5)/√1381.25 = −4.358925.
    // Hand-computed tail (Mills-ratio asymptotic expansion): 1−Φ(4.358925) = 6.54e-6,
    // so two-sided p = 1.308e-5 (±3e-7 for the Abramowitz-Stegun erf's absolute error).
    $x = range(1, 25);
    $result = WilcoxonSignedRank::test($x, array_fill(0, 25, 0));

    expect($result['exact'])->toBeFalse()
        ->and($result['w'])->toBe(0.0)
        ->and($result['rank_biserial'])->toBe(1.0)
        ->and($result['p'])->toEqualWithDelta(1.308e-5, 5e-7);

    expect(fn () => WilcoxonSignedRank::test([1], []))->toThrow(InvalidArgumentException::class);
});

it('adjusts exploratory p-values by Benjamini-Hochberg with monotone q-values', function () {
    // Sorted p [0.01,0.02,0.03,0.04,0.20], m=5, alpha=0.05: thresholds k·0.01 => largest
    // k=4 rejects the first four. Raw (m/j)·p_j = [0.05,0.05,0.05,0.05,0.20]; already
    // monotone, so q = [0.05,0.05,0.05,0.05,0.20].
    $adjusted = BenjaminiHochberg::adjust([
        'h2a' => 0.01, 'h2b' => 0.02, 'x1' => 0.03, 'x2' => 0.04, 'x3' => 0.20,
    ]);

    expect(array_keys($adjusted))->toBe(['h2a', 'h2b', 'x1', 'x2', 'x3'])
        ->and($adjusted['h2a'])->toBe(['p' => 0.01, 'q' => 0.05, 'rejected' => true])
        ->and($adjusted['x2']['rejected'])->toBeTrue()
        ->and($adjusted['x3'])->toBe(['p' => 0.20, 'q' => 0.20, 'rejected' => false]);
});

it('applies the step-up rule and monotone enforcement in Benjamini-Hochberg', function () {
    // p = [0.02, 0.03, 0.031]: raw (m/j)·p_j = [0.06, 0.045, 0.031]; monotone-enforced
    // from the top => q = [0.031, 0.031, 0.031]. Largest k with p_(k) ≤ (k/3)·0.05 is
    // k=3 (0.031 ≤ 0.05), so ALL three reject — including 'a', whose own threshold
    // (0.0167) it fails. That is the step-up property.
    $adjusted = BenjaminiHochberg::adjust(['a' => 0.02, 'b' => 0.03, 'c' => 0.031]);

    expect($adjusted['a'])->toBe(['p' => 0.02, 'q' => 0.031, 'rejected' => true])
        ->and($adjusted['b']['rejected'])->toBeTrue()
        ->and($adjusted['c']['rejected'])->toBeTrue()
        ->and(BenjaminiHochberg::adjust([]))->toBe([])
        // Input key order is preserved even when it is not p-sorted.
        ->and(array_keys(BenjaminiHochberg::adjust(['late' => 0.9, 'early' => 0.001])))->toBe(['late', 'early']);
});
