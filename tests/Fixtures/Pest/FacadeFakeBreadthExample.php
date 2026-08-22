<?php

use App\Services\PaymentGateway;
use Illuminate\Support\Facades\Queue;

// Twin of PhpUnit/FacadeFakeBreadthExample.php — same hand-computed metrics:
// mockBreadth = 2, mockBreadthExcludingFacades = 1.
it('charges with the queue faked', function () {
    Queue::fake();

    $this->mock(PaymentGateway::class)
        ->shouldReceive('charge')
        ->andReturn(true);

    expect(app(PaymentGateway::class)->charge(100))->toBeTrue();
});
