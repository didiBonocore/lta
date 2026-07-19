<?php

declare(strict_types=1);

namespace App\Analysis\Ir\Enums;

/**
 * Mock kinds are kept distinct because they are categorically different practices and the
 * AI over-mocking signal (Hora & Robbes) is expected to concentrate in some and not others.
 */
enum MockKind: string
{
    case Container = 'container';        // $this->mock / partialMock / spy / instance
    case Mockery = 'mockery';            // Mockery::mock(...)
    case PhpUnitNative = 'phpunit_native'; // createMock / createStub / getMockBuilder
    case FacadeFake = 'facade_fake';     // Http::fake(), Queue::fake(), Event::fake(), ...
}
