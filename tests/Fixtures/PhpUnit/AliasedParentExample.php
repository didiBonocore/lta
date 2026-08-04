<?php

/**
 * Aliased parent name: the alias must be resolved to Tests\TestCase BEFORE the /TestCase$/
 * match, so this routes to PHPUnit even though the extends clause as written says "Base".
 * Body identical to QualifiedParentExample and FullyQualifiedParentExample.
 * Hand-computed:
 *   methods = 1
 *   testAssertionCount = 2
 *   mockAssertionCount = 0
 *   totalAssertionCount = 2
 *   mockAssertionRatio = 0.0
 */

use Tests\TestCase as Base;

class AliasedParentTest extends Base
{
    public function test_totals_are_stable(): void
    {
        $this->assertSame(1, 1);
        $this->assertTrue(true);
    }
}
