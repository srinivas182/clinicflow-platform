<?php

declare(strict_types=1);

namespace App\Domains\Billing\Gateways;

use App\Domains\Billing\Contracts\PaymentGateway;
use App\Domains\Billing\Enums\GatewayMode;
use App\Domains\Billing\Support\GatewayResult;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Peach Payments Hosted Checkout V2: OAuth token, create a checkout, redirect.
 * Webhooks are re-confirmed by asking Peach for the checkout status, so a
 * forged notification can never mark a payment paid. Refunds are done in the
 * Peach dashboard.
 */
class PeachGateway implements PaymentGateway
{
    /**
     * @param  array<string, string>  $credentials
     */
    public function __construct(private readonly array $credentials, private readonly GatewayMode $mode) {}

    public function name(): string
    {
        return 'peach';
    }

    private function authHost(): string
    {
        return $this->mode === GatewayMode::Live ? 'https://dashboard.peachpayments.com' : 'https://sandbox-dashboard.peachpayments.com';
    }

    private function checkoutHost(): string
    {
        return $this->mode === GatewayMode::Live ? 'https://secure.peachpayments.com' : 'https://testsecure.peachpayments.com';
    }

    private function token(): string
    {
        $response = Http::acceptJson()->post($this->authHost().'/api/oauth/token', [
            'clientId' => $this->credentials['client_id'] ?? '',
            'clientSecret' => $this->credentials['client_secret'] ?? '',
            'merchantId' => $this->credentials['merchant_id'] ?? '',
        ]);

        $token = $response->json('access_token');
        if (! $response->successful() || ! is_string($token)) {
            throw new GatewayException('Peach Payments did not accept these credentials.');
        }

        return $token;
    }

    public function startCheckout(CheckoutRequest $request): CheckoutStart
    {
        $response = Http::withToken($this->token())->acceptJson()->withHeaders(['Referer' => $request->returnUrl])
            ->post($this->checkoutHost().'/v2/checkout', [
                'authentication' => ['entityId' => $this->credentials['entity_id'] ?? ''],
                'amount' => round($request->amountCents / 100, 2),
                'currency' => 'ZAR',
                'merchantTransactionId' => $request->reference,
                'nonce' => Str::uuid()->toString(),
                'shopperResultUrl' => $request->returnUrl,
                'notificationUrl' => $request->notifyUrl,
            ]);

        $url = $response->json('redirectUrl');
        if (! $response->successful() || ! is_string($url)) {
            throw new GatewayException('Peach Payments could not start the payment.');
        }

        return new CheckoutStart(redirectUrl: $url, gatewayReference: (string) $response->json('checkoutId'));
    }

    public function handleWebhook(Request $request): ?WebhookResult
    {
        $checkoutId = $request->input('checkoutId');
        if (! is_string($checkoutId) || $checkoutId === '') {
            return null;
        }

        $status = Http::withToken($this->token())->acceptJson()
            ->get($this->checkoutHost()."/v2/checkout/{$checkoutId}/status", ['authentication.entityId' => $this->credentials['entity_id'] ?? '']);

        if (! $status->successful()) {
            return null;
        }

        $code = (string) $status->json('result.code');
        $reference = $status->json('merchantTransactionId');

        return is_string($reference) ? new WebhookResult(
            reference: $reference,
            paid: preg_match('/^(000\.000\.|000\.100\.1|000\.[36])/', $code) === 1,
            amountCents: is_numeric($status->json('amount')) ? (int) round(((float) $status->json('amount')) * 100) : null,
            gatewayReference: $checkoutId,
        ) : null;
    }

    public function refund(string $gatewayReference, int $amountCents): GatewayResult
    {
        return new GatewayResult(false, error: 'Peach Payments refunds are made in the Peach dashboard, then recorded here.');
    }

    public function testConnection(): GatewayResult
    {
        try {
            $this->token();

            return new GatewayResult(true);
        } catch (GatewayException $e) {
            return new GatewayResult(false, error: $e->getMessage());
        }
    }
}
