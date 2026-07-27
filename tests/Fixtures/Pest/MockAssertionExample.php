<?php

use App\Services\PaymentGateway;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;

/**
 * Pest closure equivalent of MockAssertionExampleTest.
 * Hand-computed:
 *   testAssertionCount = 1   (expect(true)->toBeTrue())
 *   mockAssertionCount = 5   (shouldReceive->once, shouldNotReceive, spy shouldHaveReceived, Event::assertDispatched, Http::assertSent)
 *   totalAssertionCount = 6
 */
it('tests mock assertions and stubs in pest closure', function () {
    // Stub setup — count 0
    $mock = Mockery::mock(PaymentGateway::class);
    $mock->shouldReceive('getRate')->andReturn(1.5);

    // Mock assertion 1: shouldReceive with once()
    $mock->shouldReceive('charge')->once()->andReturn(true);

    // Mock assertion 2: shouldNotReceive
    $mock->shouldNotReceive('cancel');

    // Mock assertion 3: spy shouldHaveReceived
    $spy = Mockery::spy(PaymentGateway::class);
    $spy->shouldHaveReceived('refund');

    // Mock assertion 4 & 5: Facade fake assertions
    Event::assertDispatched('UserLoggedIn');
    Http::assertSent(fn () => true);

    // Test assertion 1: Pest expectation
    expect(true)->toBeTrue();
});
