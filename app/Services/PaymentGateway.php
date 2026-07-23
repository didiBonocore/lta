<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Fixture dummy service class used as a target for static-analysis mock detection tests.
 */
class PaymentGateway
{
    public function charge(int|float $amount): bool
    {
        return true;
    }

    public function getRate(): float
    {
        return 1.0;
    }

    public function cancel(): bool
    {
        return true;
    }

    public function refund(): bool
    {
        return true;
    }
}
