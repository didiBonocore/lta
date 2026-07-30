<?php

namespace Tests\Fixtures\PhpUnit;

use Tests\TestCase;

/**
 * GOLD STANDARD — hand-computed for paradigm-invariant assertion counting:
 *   testAssertionCount = 3   (assertIsArray, assertArrayHasKey, assertNotEmpty)
 *   mockAssertionCount = 0
 *   totalAssertionCount = 3
 *   mockAssertionRatio = 0.0
 *   Asymmetric pair: the Pest twin verifies the same three properties of the same
 *   subject as ONE chained expectation; both must yield identical counts.
 */
class ChainedExpectationExampleTest extends TestCase
{
    public function test_order_payload_is_well_formed(): void
    {
        $payload = ['id' => 7, 'total' => 100];

        $this->assertIsArray($payload);
        $this->assertArrayHasKey('id', $payload);
        $this->assertNotEmpty($payload);
    }
}
