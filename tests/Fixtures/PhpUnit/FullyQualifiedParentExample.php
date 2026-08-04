<?php

/**
 * Fully-qualified parent name: extends \PHPUnit\Framework\TestCase must route to PHPUnit.
 * The regex era dropped this file because \w does not match a backslash. Body identical to
 * QualifiedParentExample and AliasedParentExample.
 *
 * The parent must remain written fully qualified in the extends clause — do not let a
 * formatter hoist it into a use import, or the fixture stops covering its routing case.
 * Hand-computed:
 *   methods = 1
 *   testAssertionCount = 2
 *   mockAssertionCount = 0
 *   totalAssertionCount = 2
 *   mockAssertionRatio = 0.0
 */
class FullyQualifiedParentTest extends \PHPUnit\Framework\TestCase
{
    public function test_totals_are_stable(): void
    {
        $this->assertSame(1, 1);
        $this->assertTrue(true);
    }
}
