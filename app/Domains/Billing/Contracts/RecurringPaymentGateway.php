<?php

declare(strict_types=1);

namespace App\Domains\Billing\Contracts;

use App\Domains\Billing\Gateways\ChargeResult;
use App\Domains\Billing\Support\GatewayResult;

/**
 * Gateways that can keep charging a saved card (auto-debit).
 * Paystack and Peach are charged by Dr Business Flow; PayFast runs the
 * subscription itself and reports each charge by ITN.
 */
interface RecurringPaymentGateway
{
    public function chargeMandate(string $token, string $email, int $amountCents, string $reference): ChargeResult;

    public function revokeMandate(string $token, string $email): GatewayResult;
}
