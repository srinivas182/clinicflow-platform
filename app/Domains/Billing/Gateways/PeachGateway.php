<?php

declare(strict_types=1);

namespace App\Domains\Billing\Gateways;

use App\Domains\Billing\Contracts\PaymentGateway;
use App\Domains\Billing\Contracts\RecurringPaymentGateway;
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
class PeachGateway implements PaymentGateway, RecurringPaymentGateway
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
                ...($request->saveCard ? ['createRegistration' => true] : []),
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
            mandate: is_string($status->json('registrationId')) ? new MandateDetails(
                token: $status->json('registrationId'),
                brand: is_string($status->json('paymentBrand')) ? $status->json('paymentBrand') : null,
                last4: is_string($status->json('card.last4Digits')) ? $status->json('card.last4Digits') : null,
                expiry: is_string($status->json('card.expiryMonth')) && is_string($status->json('card.expiryYear'))
                    ? $status->json('card.expiryMonth').'/'.$status->json('card.expiryYear') : null,
            ) : null,
        ) : null;
    }

    private function recurringHost(): string
    {
        return $this->mode === GatewayMode::Live ? 'https://eu-prod.oppwa.com' : 'https://eu-test.oppwa.com';
    }

    /**
     * Merchant-initiated charge on a saved card (Peach recurring API).
     */
    public function chargeMandate(string $token, string $email, int $amountCents, string $reference): ChargeResult
    {
        $accessToken = (string) ($this->credentials['access_token'] ?? '');
        $entity = (string) ($this->credentials['recurring_entity_id'] ?? '');
        if ($accessToken === '' || $entity === '') {
            return new ChargeResult(false, error: 'Peach auto-debit needs the recurring entity ID and access token.');
        }

        $response = Http::withToken($accessToken)->asForm()->post($this->recurringHost()."/v1/registrations/{$token}/payments", [
            'entityId' => $entity,
            'amount' => number_format($amountCents / 100, 2, '.', ''),
            'currency' => 'ZAR',
            'paymentType' => 'DB',
            'merchantTransactionId' => $reference,
            'standingInstruction.mode' => 'REPEATED',
            'standingInstruction.type' => 'UNSCHEDULED',
            'standingInstruction.source' => 'MIT',
        ]);

        $code = (string) $response->json('result.code');
        if (preg_match('/^(000\.000\.|000\.100\.1|000\.[36])/', $code) === 1) {
            return new ChargeResult(true, is_string($response->json('id')) ? $response->json('id') : $reference);
        }

        $message = $response->json('result.description');

        return new ChargeResult(false, $reference, is_string($message) ? $message : 'The card was declined.');
    }

    public function revokeMandate(string $token, string $email): GatewayResult
    {
        $accessToken = (string) ($this->credentials['access_token'] ?? '');
        $response = Http::withToken($accessToken)->delete($this->recurringHost()."/v1/registrations/{$token}?entityId=".urlencode((string) ($this->credentials['recurring_entity_id'] ?? '')));

        return $response->successful() ? new GatewayResult(true) : new GatewayResult(false, error: 'Peach did not confirm the card was removed.');
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
