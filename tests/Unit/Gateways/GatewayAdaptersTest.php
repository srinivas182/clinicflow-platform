<?php

use App\Domains\Billing\Enums\GatewayMode;
use App\Domains\Billing\Gateways\CheckoutRequest;
use App\Domains\Billing\Gateways\PayFastGateway;
use App\Domains\Billing\Gateways\PaystackGateway;
use App\Domains\Billing\Gateways\PeachGateway;
use App\Domains\Billing\Gateways\YocoGateway;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

function checkoutRequest(): CheckoutRequest
{
    return new CheckoutRequest(52000, 'tok_abc', 'Invoice INV-2026-000001', 'https://s.test/pay/tok_abc/done', 'https://s.test/pay/tok_abc/done?cancelled=1', 'https://s.test/webhooks/payments/x', 'thandi@example.test', 'Thandi Mokoena');
}

function jsonRequest(array $body, array $headers = []): Request
{
    $request = Request::create('/webhook', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode($body, JSON_THROW_ON_ERROR));
    foreach ($headers as $k => $v) {
        $request->headers->set($k, $v);
    }

    return $request;
}

it('builds a signed PayFast form for the sandbox and live hosts', function (): void {
    $test = (new PayFastGateway(['merchant_id' => '10000100', 'merchant_key' => '46f0cd694581a', 'passphrase' => 'jt7NOE43FZPn'], GatewayMode::Test))->startCheckout(checkoutRequest());
    $live = (new PayFastGateway(['merchant_id' => '10000100', 'merchant_key' => '46f0cd694581a'], GatewayMode::Live))->startCheckout(checkoutRequest());

    expect($test->formAction)->toBe('https://sandbox.payfast.co.za/eng/process')
        ->and($live->formAction)->toBe('https://www.payfast.co.za/eng/process')
        ->and($test->formFields['amount'])->toBe('520.00')
        ->and($test->formFields['m_payment_id'])->toBe('tok_abc')
        ->and($test->formFields['signature'])->toMatch('/^[a-f0-9]{32}$/')
        ->and($test->formFields['signature'])->not->toBe($live->formFields['signature']);
});

it('accepts a PayFast ITN only with a valid signature and PayFast validation', function (): void {
    $gateway = new PayFastGateway(['merchant_id' => '10000100', 'merchant_key' => 'k', 'passphrase' => 'secret'], GatewayMode::Test);
    $fields = ['m_payment_id' => 'tok_abc', 'pf_payment_id' => '1089250', 'payment_status' => 'COMPLETE', 'amount_gross' => '520.00', 'merchant_id' => '10000100'];
    $fields['signature'] = $gateway->signature($fields);

    // First validation call says VALID, the next says INVALID (a tampered or replayed ITN).
    Http::fake(['sandbox.payfast.co.za/eng/query/validate' => Http::sequence()->push('VALID')->push('INVALID')]);
    $result = $gateway->handleWebhook(Request::create('/itn', 'POST', $fields));
    expect($result?->paid)->toBeTrue()->and($result?->amountCents)->toBe(52000)->and($result?->reference)->toBe('tok_abc');

    $tampered = [...$fields, 'amount_gross' => '1.00'];
    expect($gateway->handleWebhook(Request::create('/itn', 'POST', $tampered)))->toBeNull();

    expect($gateway->handleWebhook(Request::create('/itn', 'POST', $fields)))->toBeNull();
});

it('starts a Paystack checkout and verifies its HMAC-SHA512 webhook', function (): void {
    Http::fake(['api.paystack.co/transaction/initialize' => Http::response(['status' => true, 'data' => ['authorization_url' => 'https://checkout.paystack.com/abc']])]);
    $gateway = new PaystackGateway(['secret_key' => 'sk_test_123'], GatewayMode::Test);

    expect($gateway->startCheckout(checkoutRequest())->redirectUrl)->toBe('https://checkout.paystack.com/abc');
    Http::assertSent(fn (ClientRequest $r) => $r['amount'] === 52000 && $r['currency'] === 'ZAR' && $r['reference'] === 'tok_abc' && $r->hasHeader('Authorization', 'Bearer sk_test_123'));

    $body = ['event' => 'charge.success', 'data' => ['reference' => 'tok_abc', 'status' => 'success', 'amount' => 52000]];
    $signature = hash_hmac('sha512', json_encode($body, JSON_THROW_ON_ERROR), 'sk_test_123');

    expect($gateway->handleWebhook(jsonRequest($body, ['x-paystack-signature' => $signature]))?->paid)->toBeTrue()
        ->and($gateway->handleWebhook(jsonRequest($body, ['x-paystack-signature' => 'forged'])))->toBeNull();
});

it('rejects a Paystack key that does not match the mode', function (): void {
    expect((new PaystackGateway(['secret_key' => 'sk_test_123'], GatewayMode::Live))->testConnection()->ok)->toBeFalse();
});

it('creates a Peach checkout with an OAuth token and re-confirms webhooks with Peach', function (): void {
    Http::fake([
        'sandbox-dashboard.peachpayments.com/api/oauth/token' => Http::response(['access_token' => 'jwt123']),
        'testsecure.peachpayments.com/v2/checkout' => Http::response(['checkoutId' => 'chk_1', 'redirectUrl' => 'https://testsecure.peachpayments.com/checkout/chk_1']),
        'testsecure.peachpayments.com/v2/checkout/chk_1/status*' => Http::response(['result' => ['code' => '000.100.110'], 'merchantTransactionId' => 'tok_abc', 'amount' => '520.00']),
    ]);
    $gateway = new PeachGateway(['entity_id' => 'ent', 'merchant_id' => 'm', 'client_id' => 'c', 'client_secret' => 's'], GatewayMode::Test);

    $start = $gateway->startCheckout(checkoutRequest());
    $result = $gateway->handleWebhook(Request::create('/hook', 'POST', ['checkoutId' => 'chk_1']));

    expect($start->gatewayReference)->toBe('chk_1')
        ->and($result?->paid)->toBeTrue()
        ->and($result?->reference)->toBe('tok_abc')
        ->and($result?->amountCents)->toBe(52000);
});

it('creates a Yoco checkout and verifies Standard Webhooks signatures', function (): void {
    Http::fake(['payments.yoco.com/api/checkouts' => Http::response(['id' => 'ch_1', 'redirectUrl' => 'https://c.yoco.com/checkout/ch_1'])]);
    $secret = 'whsec_'.base64_encode('topsecretkey123');
    $gateway = new YocoGateway(['secret_key' => 'sk_test_x', 'webhook_secret' => $secret], GatewayMode::Test);

    expect($gateway->startCheckout(checkoutRequest())->redirectUrl)->toBe('https://c.yoco.com/checkout/ch_1');

    $body = ['type' => 'payment.succeeded', 'payload' => ['amount' => 52000, 'metadata' => ['reference' => 'tok_abc', 'checkoutId' => 'ch_1']]];
    $raw = json_encode($body, JSON_THROW_ON_ERROR);
    $ts = (string) time();
    $sig = base64_encode(hash_hmac('sha256', "msg_1.{$ts}.{$raw}", 'topsecretkey123', true));

    expect($gateway->handleWebhook(jsonRequest($body, ['webhook-id' => 'msg_1', 'webhook-timestamp' => $ts, 'webhook-signature' => "v1,{$sig}"]))?->reference)->toBe('tok_abc')
        ->and($gateway->handleWebhook(jsonRequest($body, ['webhook-id' => 'msg_1', 'webhook-timestamp' => $ts, 'webhook-signature' => 'v1,forged'])))->toBeNull()
        ->and($gateway->handleWebhook(jsonRequest($body, ['webhook-id' => 'msg_1', 'webhook-timestamp' => (string) (time() - 3600), 'webhook-signature' => "v1,{$sig}"])))->toBeNull();
});

it('only refunds Yoco payments made with live keys', function (): void {
    expect((new YocoGateway(['secret_key' => 'sk_test_x'], GatewayMode::Test))->refund('ch_1', 100)->ok)->toBeFalse();
});
