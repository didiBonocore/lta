<?php

/**
 * Qualified parent name: extends Tests\TestCase must route to PHPUnit. The regex era dropped
 * this file because \w does not match a backslash. Body identical to
 * FullyQualifiedParentExample and AliasedParentExample — all three must produce identical
 * method records.
 *
 * The parent must remain written as a qualified name in the extends clause — do not let a
 * formatter hoist it into a use import, or the fixture stops covering its routing case.
 * Hand-computed:
 *   methods = 1
 *   testAssertionCount = 2
 *   mockAssertionCount = 0
 *   totalAssertionCount = 2
 *   mockAssertionRatio = 0.0
 */
class QualifiedParentTest extends Tests\TestCase
{
    public function test_totals_are_stable(): void
    {
        $this->assertSame(1, 1);
        $this->assertTrue(true);
    }
}
