<?php

/**
 * Twin of PhpUnit/ChainedExpectationExample.php — same hand-computed metrics, but the
 * three properties are expressed as ONE chained expectation (the asymmetric case that
 * the paradigm-invariant counting rule exists for).
 * Hand-computed:
 *   testAssertionCount = 3   (toBeArray, toHaveKey, not->toBeEmpty — one chain, three terminators)
 *   mockAssertionCount = 0
 *   totalAssertionCount = 3
 *   mockAssertionRatio = 0.0
 */
it('has a well-formed order payload', function () {
    $payload = ['id' => 7, 'total' => 100];

    expect($payload)->toBeArray()->toHaveKey('id')->not->toBeEmpty();
});
