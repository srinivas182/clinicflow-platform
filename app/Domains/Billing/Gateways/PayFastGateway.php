<?php

declare(strict_types=1);

namespace App\Domains\Billing\Gateways;

use App\Domains\Billing\Contracts\PaymentGateway;
use App\Domains\Billing\Enums\GatewayMode;
use App\Domains\Billing\Support\GatewayResult;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

/**
 * PayFast: the payer's browser posts a signed form to PayFast; PayFast sends an
 * ITN (instant transaction notification) that we verify by signature and by
 * asking PayFast to validate it. Refunds are done in the PayFast dashboard.
 */
class PayFastGateway implements PaymentGateway
{
    /**
     * @param  array<string, string>  $credentials
     */
    public function __construct(private readonly array $credentials, private readonly GatewayMode $mode) {}

    public function name(): string
    {
        return 'payfast';
    }

    private function host(): string
    {
        return $this->mode === GatewayMode::Live ? 'https://www.payfast.co.za' : 'https://sandbox.payfast.co.za';
    }

    /**
     * MD5 signature over the fields in order, with the passphrase appended.
     *
     * @param  array<string, string>  $fields
     */
    public function signature(array $fields): string
    {
        $pairs = [];
        foreach ($fields as $key => $value) {
            if ($key !== 'signature' && $value !== '') {
                $pairs[] = $key.'='.urlencode(trim($value));
            }
        }
        $passphrase = $this->credentials['passphrase'] ?? '';
        if ($passphrase !== '') {
            $pairs[] = 'passphrase='.urlencode(trim($passphrase));
        }

        return md5(implode('&', $pairs));
    }

    public function startCheckout(CheckoutRequest $request): CheckoutStart
    {
        $names = explode(' ', trim($request->customerName), 2);
        $fields = [
            'merchant_id' => (string) ($this->credentials['merchant_id'] ?? ''),
            'merchant_key' => (string) ($this->credentials['merchant_key'] ?? ''),
            'return_url' => $request->returnUrl,
            'cancel_url' => $request->cancelUrl,
            'notify_url' => $request->notifyUrl,
            'name_first' => $names[0],
            'name_last' => $names[1] ?? '',
            'email_address' => $request->email,
            'm_payment_id' => $request->reference,
            'amount' => number_format($request->amountCents / 100, 2, '.', ''),
            'item_name' => mb_substr($request->description, 0, 100),
        ];
        $fields = array_filter($fields, fn (string $v) => $v !== '');
        $fields['signature'] = $this->signature($fields);

        return new CheckoutStart(formAction: $this->host().'/eng/process', formFields: $fields);
    }

    public function handleWebhook(Request $request): ?WebhookResult
    {
        /** @var array<string, string> $posted */
        $posted = array_map(fn ($v) => is_string($v) ? $v : '', $request->post());
        $signature = $posted['signature'] ?? '';

        if ($signature === '' || ! hash_equals($this->signature($posted), $signature)) {
            return null;
        }

        if (($posted['merchant_id'] ?? '') !== ($this->credentials['merchant_id'] ?? null)) {
            return null;
        }

        $body = http_build_query(array_diff_key($posted, ['signature' => true]));
        $validation = Http::withBody($body, 'application/x-www-form-urlencoded')->post($this->host().'/eng/query/validate');

        if (trim($validation->body()) !== 'VALID') {
            return null;
        }

        return new WebhookResult(
            reference: $posted['m_payment_id'] ?? '',
            paid: ($posted['payment_status'] ?? '') === 'COMPLETE',
            amountCents: isset($posted['amount_gross']) ? (int) round(((float) $posted['amount_gross']) * 100) : null,
            gatewayReference: $posted['pf_payment_id'] ?? null,
        );
    }

    public function refund(string $gatewayReference, int $amountCents): GatewayResult
    {
        return new GatewayResult(false, error: 'PayFast refunds are made in the PayFast dashboard, then recorded here.');
    }

    public function testConnection(): GatewayResult
    {
        $ok = preg_match('/^\d+$/', (string) ($this->credentials['merchant_id'] ?? '')) === 1 && ($this->credentials['merchant_key'] ?? '') !== '';

        return new GatewayResult($ok, error: $ok ? null : 'Enter the merchant ID (digits) and merchant key from your PayFast settings.');
    }
}
