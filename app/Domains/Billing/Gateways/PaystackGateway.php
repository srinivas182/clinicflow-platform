<?php

declare(strict_types=1);

namespace App\Domains\Billing\Gateways;

use App\Domains\Billing\Contracts\PaymentGateway;
use App\Domains\Billing\Contracts\RecurringPaymentGateway;
use App\Domains\Billing\Enums\GatewayMode;
use App\Domains\Billing\Support\GatewayResult;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

/**
 * Paystack: initialise a transaction, redirect to its authorisation URL, and
 * verify webhooks with the HMAC-SHA512 signature of the raw body.
 */
class PaystackGateway implements PaymentGateway, RecurringPaymentGateway
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
            'metadata' => ['description' => $request->description, 'save_card' => $request->saveCard],
            ...($request->saveCard ? ['channels' => ['card']] : []),
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
            mandate: $this->mandateFrom($request),
        ) : null;
    }

    private function mandateFrom(Request $request): ?MandateDetails
    {
        $code = $request->input('data.authorization.authorization_code');
        if (! is_string($code) || $request->input('data.authorization.reusable') !== true) {
            return null;
        }

        $month = (string) $request->input('data.authorization.exp_month');
        $year = (string) $request->input('data.authorization.exp_year');

        return new MandateDetails(
            token: $code,
            email: is_string($request->input('data.customer.email')) ? $request->input('data.customer.email') : null,
            brand: is_string($request->input('data.authorization.card_type')) ? trim($request->input('data.authorization.card_type')) : null,
            last4: is_string($request->input('data.authorization.last4')) ? $request->input('data.authorization.last4') : null,
            expiry: $month !== '' && $year !== '' ? str_pad($month, 2, '0', STR_PAD_LEFT).'/'.$year : null,
        );
    }

    public function chargeMandate(string $token, string $email, int $amountCents, string $reference): ChargeResult
    {
        $response = Http::withToken($this->secret())->acceptJson()->post(self::API.'/transaction/charge_authorization', [
            'authorization_code' => $token,
            'email' => $email,
            'amount' => $amountCents,
            'currency' => 'ZAR',
            'reference' => $reference,
        ]);

        if ($response->successful() && $response->json('data.status') === 'success') {
            return new ChargeResult(true, $reference);
        }

        $message = $response->json('data.gateway_response') ?? $response->json('message') ?? 'The card was declined.';

        return new ChargeResult(false, $reference, is_string($message) ? $message : 'The card was declined.');
    }

    public function revokeMandate(string $token, string $email): GatewayResult
    {
        $response = Http::withToken($this->secret())->acceptJson()->post(self::API.'/customer/deactivate_authorization', ['authorization_code' => $token]);

        return $response->successful() ? new GatewayResult(true) : new GatewayResult(false, error: 'Paystack did not confirm the card was removed.');
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
