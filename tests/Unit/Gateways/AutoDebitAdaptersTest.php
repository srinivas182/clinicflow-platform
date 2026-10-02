<?php

use App\Domains\Billing\Enums\Gateway;
use App\Domains\Billing\Enums\GatewayMode;
use App\Domains\Billing\Gateways\CheckoutRequest;
use App\Domains\Billing\Gateways\PayFastGateway;
use App\Domains\Billing\Gateways\PeachGateway;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

function saveCardRequest(string $frequency = 'monthly'): CheckoutRequest
{
    return new CheckoutRequest(171350, 'tok_sub', 'Clinic Flow subscription CF-2026-000001', 'https://c.test/billing/done', 'https://c.test/billing/done?cancelled=1',
        'https://c.test/api/webhooks/platform/x', 'owner@sunrise.test', 'Sizwe Mthembu', saveCard: true, billingFrequency: $frequency);
}

it('marks which gateways can auto-debit and who charges', function (): void {
    expect(Gateway::Yoco->supportsAutoDebit())->toBeFalse()
        ->and(Gateway::Paystack->supportsAutoDebit())->toBeTrue()
        ->and(Gateway::PayFast->chargesMandateItself())->toBeTrue()
        ->and(Gateway::Peach->chargesMandateItself())->toBeFalse();
});

it('adds signed PayFast subscription fields for monthly and annual billing', function (): void {
    $gateway = new PayFastGateway(['merchant_id' => '10000100', 'merchant_key' => 'k', 'passphrase' => 'p'], GatewayMode::Test);
    $monthly = $gateway->startCheckout(saveCardRequest())->formFields;
    $annual = $gateway->startCheckout(saveCardRequest('annual'))->formFields;

    expect($monthly['subscription_type'])->toBe('1')
        ->and($monthly['recurring_amount'])->toBe('1713.50')
        ->and($monthly['frequency'])->toBe('3')
        ->and($monthly['cycles'])->toBe('0')
        ->and($annual['frequency'])->toBe('6')
        ->and($monthly['signature'])->toBe($gateway->signature(array_diff_key($monthly, ['signature' => true])));
});

it('signs PayFast API calls over sorted values with the passphrase', function (): void {
    $gateway = new PayFastGateway(['merchant_id' => '10000100', 'passphrase' => 'jt7NOE43FZPn'], GatewayMode::Test);
    $values = ['version' => 'v1', 'timestamp' => '2026-10-02T10:00:00+02:00', 'merchant-id' => '10000100'];

    expect($gateway->apiSignature($values))->toBe(md5(http_build_query([
        'merchant-id' => '10000100', 'passphrase' => 'jt7NOE43FZPn', 'timestamp' => '2026-10-02T10:00:00+02:00', 'version' => 'v1',
    ])));
});

it('asks Peach to save the card and charges the saved registration later', function (): void {
    Http::fake([
        'sandbox-dashboard.peachpayments.com/api/oauth/token' => Http::response(['access_token' => 'jwt']),
        'testsecure.peachpayments.com/v2/checkout' => Http::response(['checkoutId' => 'chk_1', 'redirectUrl' => 'https://testsecure.peachpayments.com/checkout/chk_1']),
        'testsecure.peachpayments.com/v2/checkout/chk_1/status*' => Http::response([
            'result' => ['code' => '000.100.110'], 'merchantTransactionId' => 'tok_sub', 'amount' => '1713.50',
            'registrationId' => 'reg_8a82', 'paymentBrand' => 'VISA', 'card' => ['last4Digits' => '1111', 'expiryMonth' => '05', 'expiryYear' => '2031'],
        ]),
        'eu-test.oppwa.com/v1/registrations/reg_8a82/payments' => Http::response(['id' => 'pay_77', 'result' => ['code' => '000.100.110', 'description' => 'Request successfully processed']]),
    ]);
    $gateway = new PeachGateway(['entity_id' => 'ent', 'merchant_id' => 'm', 'client_id' => 'c', 'client_secret' => 's', 'recurring_entity_id' => 'rec_ent', 'access_token' => 'oppwa_tok'], GatewayMode::Test);

    $gateway->startCheckout(saveCardRequest());
    Http::assertSent(fn (ClientRequest $r) => str_ends_with($r->url(), '/v2/checkout') && $r['createRegistration'] === true);

    $result = $gateway->handleWebhook(Request::create('/w', 'POST', ['checkoutId' => 'chk_1']));
    expect($result?->mandate?->token)->toBe('reg_8a82')
        ->and($result?->mandate?->last4)->toBe('1111')
        ->and($result?->mandate?->expiry)->toBe('05/2031');

    $charge = $gateway->chargeMandate('reg_8a82', '', 171350, 'tok_next-1');
    expect($charge->ok)->toBeTrue()->and($charge->gatewayReference)->toBe('pay_77');
    Http::assertSent(fn (ClientRequest $r) => str_contains($r->url(), 'registrations/reg_8a82/payments')
        && $r['entityId'] === 'rec_ent' && $r['amount'] === '1713.50' && $r['standingInstruction.source'] === 'MIT' && $r->hasHeader('Authorization', 'Bearer oppwa_tok'));
});

it('refuses a Peach auto-debit without the recurring credentials', function (): void {
    $gateway = new PeachGateway(['entity_id' => 'ent'], GatewayMode::Test);

    expect($gateway->chargeMandate('reg', '', 100, 'r')->ok)->toBeFalse();
});
