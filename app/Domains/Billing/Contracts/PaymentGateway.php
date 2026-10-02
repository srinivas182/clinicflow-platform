<?php

declare(strict_types=1);

namespace App\Domains\Billing\Contracts;

use App\Domains\Billing\Support\GatewayResult;

/**
 * A provider's own merchant account (e.g. Paystack, PayFast, Peach, Yoco).
 * Each provider connects its own credentials; money never passes through
 * Clinic Flow (ADR 0009).
 */
interface PaymentGateway
{
    public function name(): string;

    /**
     * Create a pay link the patient opens on their phone.
     */
    public function createPayLink(int $amountCents, string $reference, string $description): GatewayResult;

    public function refund(string $gatewayReference, int $amountCents): GatewayResult;
}
