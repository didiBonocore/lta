<?php

/**
 * Negation and modifier expectation forms, mirroring the counting-rule case table.
 * Hand-computed:
 *   testAssertionCount = 4
 *     expect($name)->not->toBeEmpty()                  → 1  (not is a modifier, toBeEmpty terminates)
 *     expect($count)->toBe(1)->and($total)->toBe(150)  → 2  (and() is a modifier, not a terminator)
 *     expect($ids)->each->toBeInt()                    → 1  (higher-order each is a modifier)
 *   mockAssertionCount = 0
 *   totalAssertionCount = 4
 *   mockAssertionRatio = 0.0
 */
it('verifies negated, conjoined and higher-order expectations', function () {
    $name = 'gateway';
    $count = 1;
    $total = 150;
    $ids = [1, 2, 3];

    expect($name)->not->toBeEmpty();
    expect($count)->toBe(1)->and($total)->toBe(150);
    expect($ids)->each->toBeInt();
});
