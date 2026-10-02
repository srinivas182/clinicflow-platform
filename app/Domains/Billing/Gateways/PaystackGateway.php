<?php

declare(strict_types=1);

namespace App\Domains\Billing\Gateways;

use App\Domains\Billing\Contracts\PaymentGateway;
use App\Domains\Billing\Enums\GatewayMode;
use App\Domains\Billing\Support\GatewayResult;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

/**
 * Paystack: initialise a transaction, redirect to its authorisation URL, and
 * verify webhooks with the HMAC-SHA512 signature of the raw body.
 */
class PaystackGateway implements PaymentGateway
{
    private const API = 'https://api.paystack.co';

    /**
     * @param  array<string, string>  $credentials
     */
    public function __construct(private readonly array $credentials, private readonly GatewayMode $mode) {}

    public function name(): string
    {
        return 'paystack';
    }

    private function secret(): string
    {
        return (string) ($this->credentials['secret_key'] ?? '');
    }

    public function startCheckout(CheckoutRequest $request): CheckoutStart
    {
        $response = Http::withToken($this->secret())->acceptJson()->post(self::API.'/transaction/initialize', [
            'email' => $request->email,
            'amount' => $request->amountCents,
            'currency' => 'ZAR',
            'reference' => $request->reference,
            'callback_url' => $request->returnUrl,
            'metadata' => ['description' => $request->description],
        ]);

        $url = $response->json('data.authorization_url');
        if (! $response->successful() || ! is_string($url)) {
            throw new GatewayException('Paystack could not start the payment: '.($response->json('message') ?? 'unknown error'));
        }

        return new CheckoutStart(redirectUrl: $url, gatewayReference: $request->reference);
    }

    public function handleWebhook(Request $request): ?WebhookResult
    {
        $signature = (string) $request->header('x-paystack-signature');
        $expected = hash_hmac('sha512', $request->getContent(), $this->secret());

        if ($signature === '' || ! hash_equals($expected, $signature)) {
            return null;
        }

        if ($request->input('event') !== 'charge.success') {
            return null;
        }

        $reference = $request->input('data.reference');

        return is_string($reference) ? new WebhookResult(
            reference: $reference,
            paid: $request->input('data.status') === 'success',
            amountCents: is_numeric($request->input('data.amount')) ? (int) $request->input('data.amount') : null,
            gatewayReference: $reference,
        ) : null;
    }

    public function refund(string $gatewayReference, int $amountCents): GatewayResult
    {
        $response = Http::withToken($this->secret())->acceptJson()->post(self::API.'/refund', [
            'transaction' => $gatewayReference,
            'amount' => $amountCents,
        ]);

        return $response->successful()
            ? new GatewayResult(true, (string) ($response->json('data.id') ?? ''))
            : new GatewayResult(false, error: (string) ($response->json('message') ?? 'Paystack refused the refund.'));
    }

    public function testConnection(): GatewayResult
    {
        $prefix = $this->mode === GatewayMode::Live ? 'sk_live_' : 'sk_test_';
        if (! str_starts_with($this->secret(), $prefix)) {
            return new GatewayResult(false, error: "The secret key for {$this->mode->value} mode starts with {$prefix}.");
        }

        $response = Http::withToken($this->secret())->acceptJson()->get(self::API.'/balance');

        return $response->successful() ? new GatewayResult(true) : new GatewayResult(false, error: 'Paystack did not accept this secret key.');
    }
}
