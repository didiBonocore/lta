<?php

namespace Tests\Fixtures\PhpUnit;

use App\Services\PaymentGateway;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * GOLD STANDARD — hand-computed for mock assertion classification:
 *   testAssertionCount = 1   ($this->assertTrue)
 *   mockAssertionCount = 5   (shouldReceive->once, shouldNotReceive, expects->once, Event::assertDispatched, Http::assertSent)
 *   totalAssertionCount = 6
 *   mockAssertionRatio = 5/6 = 0.8333333333...
 *   Stubs counted: shouldReceive->andReturn (0), expects($this->any()) (0).
 */
class MockAssertionExampleTest extends TestCase
{
    public function test_mock_assertions_and_stubs(): void
    {
        // Stub setup — count 0
        $this->mock(PaymentGateway::class)
            ->shouldReceive('getRate')
            ->andReturn(1.5);

        // Mock assertion 1: shouldReceive with count constraint
        $this->mock(PaymentGateway::class)
            ->shouldReceive('charge')
            ->once()
            ->andReturn(true);

        // Mock assertion 2: shouldNotReceive
        $this->mock(PaymentGateway::class)
            ->shouldNotReceive('cancel');

        // Stub setup — count 0
        $phpunitStub = $this->createMock(PaymentGateway::class);
        $phpunitStub->expects($this->any())
            ->method('getRate')
            ->willReturn(1.5);

        // Mock assertion 3: PHPUnit expects with once()
        $phpunitMock = $this->createMock(PaymentGateway::class);
        $phpunitMock->expects($this->once())
            ->method('charge')
            ->willReturn(true);

        // Mock assertion 4: Facade fake assertion
        Event::assertDispatched('UserLoggedIn');

        // Mock assertion 5: Facade fake assertion
        Http::assertSent(fn () => true);

        // Test assertion 1: State verification
        $this->assertTrue(true);
    }
}
