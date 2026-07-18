<?php

declare(strict_types=1);

use App\Analysis\Statistics\CohenKappa;
use App\Analysis\Statistics\EffectSize;
use App\Analysis\Statistics\MannWhitney;
use App\Analysis\Statistics\SimpleLinearRegression;

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
