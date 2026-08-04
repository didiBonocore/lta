<?php

/**
 * Pest call inside a braced namespace: the call has no class-like ancestor, so it must route
 * to Pest — position in the top-level statement list is deliberately not the criterion.
 * Hand-computed:
 *   methods = 1
 *   testAssertionCount = 1
 *   mockAssertionCount = 0
 *   totalAssertionCount = 1
 *   mockAssertionRatio = 0.0
 */

namespace Fixtures\Braced {
    test('adds inside a braced namespace', function () {
        expect(1 + 1)->toBe(2);
    });
}
