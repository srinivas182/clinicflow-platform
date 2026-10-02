<?php

declare(strict_types=1);

namespace App\Domains\Billing\Gateways;

use App\Domains\Billing\Contracts\PaymentGateway;
use App\Domains\Billing\Enums\GatewayMode;
use App\Domains\Billing\Support\GatewayResult;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

/**
 * Yoco Checkout API: create a checkout and redirect. Webhooks use the Standard
 * Webhooks signature (webhook-id, webhook-timestamp, webhook-signature).
 * Refunds go through the API (live keys only).
 */
class YocoGateway implements PaymentGateway
{
    private const API = 'https://payments.yoco.com/api';

    /**
     * @param  array<string, string>  $credentials
     */
    public function __construct(private readonly array $credentials, private readonly GatewayMode $mode) {}

    public function name(): string
    {
        return 'yoco';
    }

    private function secret(): string
    {
        return (string) ($this->credentials['secret_key'] ?? '');
    }

    public function startCheckout(CheckoutRequest $request): CheckoutStart
    {
        $response = Http::withToken($this->secret())->acceptJson()->post(self::API.'/checkouts', [
            'amount' => $request->amountCents,
            'currency' => 'ZAR',
            'successUrl' => $request->returnUrl,
            'cancelUrl' => $request->cancelUrl,
            'failureUrl' => $request->cancelUrl,
            'metadata' => ['reference' => $request->reference],
        ]);

        $url = $response->json('redirectUrl');
        if (! $response->successful() || ! is_string($url)) {
            throw new GatewayException('Yoco could not start the payment.');
        }

        return new CheckoutStart(redirectUrl: $url, gatewayReference: (string) $response->json('id'));
    }

    public function verifySignature(Request $request): bool
    {
        $id = (string) $request->header('webhook-id');
        $timestamp = (string) $request->header('webhook-timestamp');
        $header = (string) $request->header('webhook-signature');
        $secret = (string) ($this->credentials['webhook_secret'] ?? '');

        if ($id === '' || $timestamp === '' || $header === '' || $secret === '' || abs(time() - (int) $timestamp) > 300) {
            return false;
        }

        $key = base64_decode(str_starts_with($secret, 'whsec_') ? substr($secret, 6) : $secret, true);
        if ($key === false) {
            return false;
        }

        $expected = base64_encode(hash_hmac('sha256', "{$id}.{$timestamp}.{$request->getContent()}", $key, true));

        foreach (explode(' ', $header) as $part) {
            [$version, $signature] = array_pad(explode(',', $part, 2), 2, '');
            if ($version === 'v1' && hash_equals($expected, $signature)) {
                return true;
            }
        }

        return false;
    }

    public function handleWebhook(Request $request): ?WebhookResult
    {
        if (! $this->verifySignature($request) || $request->input('type') !== 'payment.succeeded') {
            return null;
        }

        $reference = $request->input('payload.metadata.reference');

        return is_string($reference) ? new WebhookResult(
            reference: $reference,
            paid: true,
            amountCents: is_numeric($request->input('payload.amount')) ? (int) $request->input('payload.amount') : null,
            gatewayReference: (string) $request->input('payload.metadata.checkoutId'),
        ) : null;
    }

    public function refund(string $gatewayReference, int $amountCents): GatewayResult
    {
        if ($this->mode !== GatewayMode::Live) {
            return new GatewayResult(false, error: 'Yoco only refunds payments made with live keys.');
        }

        $response = Http::withToken($this->secret())->acceptJson()
            ->withHeaders(['Idempotency-Key' => hash('sha256', $gatewayReference.$amountCents)])
            ->post(self::API."/checkouts/{$gatewayReference}/refund", ['amount' => $amountCents]);

        return $response->successful()
            ? new GatewayResult(true, (string) ($response->json('refundId') ?? $response->json('id') ?? ''))
            : new GatewayResult(false, error: 'Yoco refused the refund.');
    }

    public function testConnection(): GatewayResult
    {
        $prefix = $this->mode === GatewayMode::Live ? 'sk_live_' : 'sk_test_';

        return str_starts_with($this->secret(), $prefix) && ($this->credentials['webhook_secret'] ?? '') !== ''
            ? new GatewayResult(true)
            : new GatewayResult(false, error: "Enter a {$this->mode->value} secret key (starts with {$prefix}) and the webhook secret.");
    }
}
