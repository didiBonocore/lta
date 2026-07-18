<?php

namespace Tests\Fixtures\PhpUnit;

use App\Services\PaymentGateway;
use Tests\TestCase;

/**
 * GOLD STANDARD — hand-computed:
 *   type = unit         (no HTTP, no DB trait/factory/db-assert)
 *   assertions = 1      (assertTrue)
 *   mocks = 1           (kind=container, target=PaymentGateway, chainDepth=4)
 *   sizeStatements = 2
 */
class UnitGatewayTest extends TestCase
{
    public function test_charges_via_mocked_gateway(): void
    {
        $this->mock(PaymentGateway::class)
            ->shouldReceive('charge')
            ->once()
            ->andReturn(true);

        $this->assertTrue(app(PaymentGateway::class)->charge(500));
    }
}
