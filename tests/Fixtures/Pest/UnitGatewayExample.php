<?php

use App\Services\PaymentGateway;

// Twin of PhpUnit/UnitGatewayExample.php — same hand-computed metrics.
it('charges via a mocked gateway', function () {
    $this->mock(PaymentGateway::class)
        ->shouldReceive('charge')
        ->once()
        ->andReturn(true);

    expect(app(PaymentGateway::class)->charge(500))->toBeTrue();
});
