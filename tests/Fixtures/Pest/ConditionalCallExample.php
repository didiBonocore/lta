<?php

/**
 * Pest call inside a file-scope if block: still no class-like ancestor, so it must route to
 * Pest without any special-casing of conditionals.
 * Hand-computed:
 *   methods = 1
 *   testAssertionCount = 1
 *   mockAssertionCount = 0
 *   totalAssertionCount = 1
 *   mockAssertionRatio = 0.0
 */
if (PHP_VERSION_ID > 70000) {
    it('runs conditionally', function () {
        expect(true)->toBeTrue();
    });
}
