<?php

/**
 * Negative guard for Pest routing: $this->test('x') is a MethodCall, not a FuncCall, so it
 * must never trigger Pest routing — the file routes to PHPUnit via its parent class.
 * Hand-computed:
 *   methods = 1 (test_uses_helper)
 *   testAssertionCount = 1   ($this->test('x') is neither an assert* nor an expect() chain)
 *   mockAssertionCount = 0
 *   totalAssertionCount = 1
 *   mockAssertionRatio = 0.0
 */
class MethodCallHelperTest extends TestCase
{
    public function test_uses_helper(): void
    {
        $this->test('x');

        $this->assertTrue(true);
    }
}
