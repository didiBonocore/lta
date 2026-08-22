<?php

namespace Tests\Fixtures\PhpUnit;

use App\Services\PaymentGateway;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * GOLD STANDARD — hand-computed for the facade-fake-excluded breadth:
 *   mocks = 2                          (container target=PaymentGateway; facade_fake target=Queue)
 *   mockBreadth = 2
 *   mockBreadthExcludingFacades = 1    (the Queue fake is a facade fake, the gateway mock is not)
 */
class FacadeFakeBreadthTest extends TestCase
{
    public function test_charges_with_the_queue_faked(): void
    {
        Queue::fake();

        $this->mock(PaymentGateway::class)
            ->shouldReceive('charge')
            ->andReturn(true);

        $this->assertTrue(app(PaymentGateway::class)->charge(100));
    }
}
