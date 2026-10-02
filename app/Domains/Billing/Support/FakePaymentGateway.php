<?php

declare(strict_types=1);

namespace App\Domains\Billing\Support;

use App\Domains\Billing\Contracts\PaymentGateway;
use App\Domains\Billing\Gateways\CheckoutRequest;
use App\Domains\Billing\Gateways\CheckoutStart;
use App\Domains\Billing\Gateways\WebhookResult;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Local and test gateway used only when no real gateway is connected and
 * `clinicflow.payments.allow_fake` is on (never in production).
 */
class FakePaymentGateway implements PaymentGateway
{
    /** @var list<array{type: string, amount: int, reference: string}> */
    public array $calls = [];

    public function name(): string
    {
        return 'fake';
    }

    public function startCheckout(CheckoutRequest $request): CheckoutStart
    {
        $this->calls[] = ['type' => 'checkout', 'amount' => $request->amountCents, 'reference' => $request->reference];

        return new CheckoutStart(redirectUrl: 'https://pay.example.test/'.$request->reference, gatewayReference: 'fake_'.Str::lower(Str::random(10)));
    }

    public function handleWebhook(Request $request): ?WebhookResult
    {
        $reference = $request->input('reference');

        return is_string($reference) ? new WebhookResult($reference, $request->input('status') === 'paid', is_numeric($request->input('amount')) ? (int) $request->input('amount') : null) : null;
    }

    public function refund(string $gatewayReference, int $amountCents): GatewayResult
    {
        $this->calls[] = ['type' => 'refund', 'amount' => $amountCents, 'reference' => $gatewayReference];

        return new GatewayResult(true, 'refund_'.Str::lower(Str::random(10)));
    }

    public function testConnection(): GatewayResult
    {
        return new GatewayResult(true);
    }
}
