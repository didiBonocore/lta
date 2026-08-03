<?php

/**
 * Closure-form grouping expectations: each grouping call counts once and its closures are
 * not descended into, matching the property form (expect($c)->each->toBeInt() ≡
 * expect($c)->each(fn ($e) => $e->toBeInt())).
 * Hand-computed:
 *   testAssertionCount = 5
 *     expect($ids)->each(fn ($e) => $e->toBeInt())                    → 1  (grouping call, closure not descended)
 *     expect($total)->when($isPaid, fn ($e) => $e->toBeInt())         → 1  (grouping call)
 *     expect($total)->unless($isPaid, fn ($e) => $e->toBeInt())       → 1  (grouping call)
 *     expect($ids)->each(fn ($e) => $e->toBeInt())->toHaveCount(3)    → 2  (grouping call + terminator)
 *   mockAssertionCount = 0
 *   totalAssertionCount = 5
 *   mockAssertionRatio = 0.0
 */
it('groups sub-expectations with closure-form each, when and unless', function () {
    $ids = [1, 2, 3];
    $total = 150;
    $isPaid = true;

    expect($ids)->each(fn ($e) => $e->toBeInt());
    expect($total)->when($isPaid, fn ($e) => $e->toBeInt());
    expect($total)->unless($isPaid, fn ($e) => $e->toBeInt());
    expect($ids)->each(fn ($e) => $e->toBeInt())->toHaveCount(3);
});

/**
 * False-positive guards: each() and when() are ordinary Laravel collection and conditionable
 * methods; on a chain not rooted at expect() they must not be counted.
 * Hand-computed:
 *   testAssertionCount = 0
 *     $collection->each(fn ($x) => $x->save())   → 0  (no expect() root)
 *     $this->when($flagged, fn () => null)       → 0  (no expect() root)
 *   mockAssertionCount = 0
 *   totalAssertionCount = 0
 *   mockAssertionRatio = 0.0
 */
it('ignores each() and when() on non-expectation subjects', function () {
    $collection = User::all();
    $flagged = false;

    $collection->each(fn ($x) => $x->save());
    $this->when($flagged, fn () => null);
});
