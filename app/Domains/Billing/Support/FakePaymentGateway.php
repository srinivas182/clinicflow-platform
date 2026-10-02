<?php

declare(strict_types=1);

namespace App\Domains\Billing\Support;

use App\Domains\Billing\Contracts\PaymentGateway;
use Illuminate\Support\Str;

/**
 * Local and test gateway: pay links resolve immediately, refunds succeed.
 * Real adapters (Paystack, PayFast, Peach, Yoco) implement the same contract.
 */
class FakePaymentGateway implements PaymentGateway
{
    /** @var list<array{type: string, amount: int, reference: string}> */
    public array $calls = [];

    public function name(): string
    {
        return 'fake';
    }

    public function createPayLink(int $amountCents, string $reference, string $description): GatewayResult
    {
        $this->calls[] = ['type' => 'link', 'amount' => $amountCents, 'reference' => $reference];

        return new GatewayResult(true, 'fake_'.Str::lower(Str::random(10)), 'https://pay.example.test/'.$reference);
    }

    public function refund(string $gatewayReference, int $amountCents): GatewayResult
    {
        $this->calls[] = ['type' => 'refund', 'amount' => $amountCents, 'reference' => $gatewayReference];

        return new GatewayResult(true, 'refund_'.Str::lower(Str::random(10)));
    }
}
