<?php

use App\Domains\Billing\Actions\RecordPayment;
use App\Domains\Billing\Actions\RefundPayment;
use App\Domains\Billing\Enums\InvoiceStatus;
use App\Domains\Billing\Enums\PaymentMethod;
use App\Domains\Billing\Models\GatewayConfig;
use App\Domains\Billing\Models\Invoice;
use App\Domains\Billing\Models\PlatformGatewayConfig;
use App\Domains\Billing\Models\SubscriptionInvoice;
use App\Domains\Identity\Actions\AddStaffMember;
use App\Domains\Identity\Enums\StaffRole;
use App\Domains\Patients\Actions\RegisterPatient;
use App\Domains\Patients\Enums\Channel;
use App\Domains\Patients\Enums\ConsentGivenBy;
use App\Domains\Patients\Enums\IdType;
use App\Domains\Patients\Support\RegistrationData;
use App\Domains\Platform\Actions\IssueSubscriptionInvoices;
use App\Domains\Platform\Enums\ProviderStatus;
use App\Domains\Platform\Enums\ProviderType;
use App\Domains\Platform\Enums\SubscriptionStatus;
use App\Domains\Platform\Models\Package;
use App\Domains\Platform\Models\Provider;
use App\Domains\Platform\Models\Subscription;
use App\Domains\Visits\Actions\CheckInPatient;
use App\Models\User;
use Database\Seeders\PackageSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

uses(DatabaseMigrations::class);

beforeEach(function (): void {
    if (config('database.default') !== 'mysql') {
        $this->markTestSkipped('Tenancy tests require MySQL.');
    }

    $this->clinic = makeProvider('Sunrise Medical Centre', ProviderType::Clinic, 'sunrise.clinicflow.test');
    $this->owner = User::factory()->create();
    $this->receptionist = User::factory()->create();
    app(AddStaffMember::class)->handle($this->clinic, $this->owner, StaffRole::Owner);
    app(AddStaffMember::class)->handle($this->clinic, $this->receptionist, StaffRole::Receptionist);
    $this->admin = User::factory()->create();
    $this->admin->forceFill(['is_platform_admin' => true])->save();
});

afterEach(function (): void {
    tenancy()->end();
    Provider::query()->get()->each->delete();
});

function paystackPayload(array $overrides = []): array
{
    return array_merge(['mode' => 'test', 'enabled' => true, 'is_default' => true, 'credentials' => ['public_key' => 'pk_test_1', 'secret_key' => 'sk_test_clinic']], $overrides);
}

it('lets only the owner connect a gateway and never sends secrets back', function (): void {
    $this->actingAs($this->receptionist)->put('http://sunrise.clinicflow.test/settings/payments/paystack', paystackPayload())->assertForbidden();
    $this->actingAs($this->owner)->put('http://sunrise.clinicflow.test/settings/payments/paystack', paystackPayload())->assertSessionHasNoErrors();

    $this->actingAs($this->owner)->get('http://sunrise.clinicflow.test/settings/payments')
        ->assertInertia(fn ($page) => $page->component('Settings/Payments')
            ->has('gateways', 4)
            ->where('gateways.1.gateway', 'paystack')
            ->where('gateways.1.enabled', true)
            ->where('gateways.1.fields.1.value', '')
            ->where('gateways.1.fields.1.isSet', true))
        ->assertDontSee('sk_test_clinic');

    $this->clinic->run(function (): void {
        $raw = DB::table('payment_gateway_configs')->value('credentials');
        expect($raw)->not->toContain('sk_test_clinic')
            ->and(GatewayConfig::query()->sole()->credentials['secret_key'])->toBe('sk_test_clinic');
    });

    // Blank secret on re-save keeps the stored secret.
    $this->actingAs($this->owner)->put('http://sunrise.clinicflow.test/settings/payments/paystack', paystackPayload(['credentials' => ['public_key' => 'pk_test_2', 'secret_key' => '']]));
    $this->clinic->run(fn () => expect(GatewayConfig::query()->sole()->credentials)->toBe(['public_key' => 'pk_test_2', 'secret_key' => 'sk_test_clinic']));
});

it('hides gateways the super admin does not offer', function (): void {
    $this->actingAs($this->admin)->put('http://localhost/admin/payments/peach', ['mode' => 'test', 'enabled' => false, 'offered_to_providers' => false, 'credentials' => []])->assertSessionHasNoErrors();

    $this->actingAs($this->owner)->get('http://sunrise.clinicflow.test/settings/payments')->assertInertia(fn ($page) => $page->has('gateways', 3));
    $this->actingAs($this->owner)->put('http://sunrise.clinicflow.test/settings/payments/peach', paystackPayload(['credentials' => []]))->assertForbidden();
});

it('takes a patient pay link through Paystack end to end', function (): void {
    $this->actingAs($this->owner)->put('http://sunrise.clinicflow.test/settings/payments/paystack', paystackPayload());

    tenancy()->initialize($this->clinic);
    $patient = app(RegisterPatient::class)->handle(new RegistrationData(
        firstNames: 'Thandi', surname: 'Mokoena', idType: IdType::SaId, idNumber: saId(), passportCountry: null, dateOfBirth: null,
        cell: '0825550147', noCell: false, email: 'thandi@example.test', preferredLanguage: 'en', preferredChannel: Channel::Sms, address: null,
        guardianName: null, guardianRelationship: null, guardianCell: null, popiaConsent: true, treatmentConsent: true,
        consentGivenBy: ConsentGivenBy::Patient, maturityConfirmed: false,
    ));
    $visit = app(CheckInPatient::class)->handle($patient);
    $invoice = Invoice::query()->where('visit_id', $visit->id)->sole();
    $payment = app(RecordPayment::class)->handle($invoice, PaymentMethod::PayLink, 52000);
    tenancy()->end();

    expect($payment->gateway)->toBe('paystack')->and($payment->gateway_mode)->toBe('test');

    Http::fake([
        'api.paystack.co/transaction/initialize' => Http::response(['data' => ['authorization_url' => 'https://checkout.paystack.com/xyz']]),
        'api.paystack.co/refund' => Http::response(['data' => ['id' => 777]]),
    ]);
    $this->get("http://sunrise.clinicflow.test/pay/{$payment->checkout_token}")->assertRedirect('https://checkout.paystack.com/xyz');

    $body = ['event' => 'charge.success', 'data' => ['reference' => $payment->checkout_token, 'status' => 'success', 'amount' => 52000]];
    $raw = json_encode($body, JSON_THROW_ON_ERROR);

    $this->call('POST', 'http://sunrise.clinicflow.test/webhooks/payments/paystack', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_PAYSTACK_SIGNATURE' => 'forged'], $raw)->assertSee('IGNORED');
    $this->clinic->run(fn () => expect($invoice->fresh()?->status)->toBe(InvoiceStatus::Open));

    $sig = hash_hmac('sha512', $raw, 'sk_test_clinic');
    $this->call('POST', 'http://sunrise.clinicflow.test/webhooks/payments/paystack', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_PAYSTACK_SIGNATURE' => $sig], $raw)->assertSee('OK');
    $this->call('POST', 'http://sunrise.clinicflow.test/webhooks/payments/paystack', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_PAYSTACK_SIGNATURE' => $sig], $raw)->assertOk();

    $this->clinic->run(function () use ($invoice, $payment): void {
        expect($invoice->fresh()?->status)->toBe(InvoiceStatus::Paid);
        app(RefundPayment::class)->handle($payment->fresh(), 52000, 'Charged twice');
        expect($invoice->fresh()?->paid_cents)->toBe(0);
    });
    Http::assertSent(fn ($r) => str_contains($r->url(), '/refund') && $r['amount'] === 52000);
});

it('refuses pay links when no gateway is connected and the fake gateway is off', function (): void {
    config(['clinicflow.payments.allow_fake' => false]);
    tenancy()->initialize($this->clinic);
    $patient = app(RegisterPatient::class)->handle(new RegistrationData(
        firstNames: 'Sipho', surname: 'Test', idType: IdType::SaId, idNumber: saId('850101'), passportCountry: null, dateOfBirth: null,
        cell: '0821112222', noCell: false, email: null, preferredLanguage: 'en', preferredChannel: Channel::Sms, address: null,
        guardianName: null, guardianRelationship: null, guardianCell: null, popiaConsent: true, treatmentConsent: true,
        consentGivenBy: ConsentGivenBy::Patient, maturityConfirmed: false,
    ));
    $invoice = Invoice::query()->where('visit_id', app(CheckInPatient::class)->handle($patient)->id)->sole();

    expect(fn () => app(RecordPayment::class)->handle($invoice, PaymentMethod::PayLink, 52000))->toThrow(ValidationException::class);
});

it('invoices subscriptions and activates them when the platform gateway confirms payment', function (): void {
    $this->seed(PackageSeeder::class);
    $subscription = Subscription::create([
        'tenant_id' => $this->clinic->id, 'package_id' => Package::query()->where('code', 'clinic-starter')->value('id'),
        'status' => SubscriptionStatus::Trialing, 'trial_ends_at' => now()->addDays(2),
    ]);
    $this->clinic->status = ProviderStatus::Trial;
    $this->clinic->save();

    expect(app(IssueSubscriptionInvoices::class)->handle())->toBe(1)
        ->and(app(IssueSubscriptionInvoices::class)->handle())->toBe(0);

    $invoice = SubscriptionInvoice::query()->sole();
    expect($invoice->amount_cents)->toBe(149000)->and($invoice->vat_cents)->toBe(22350)->and($invoice->total_cents)->toBe(171350);

    $secret = 'whsec_'.base64_encode('platform-secret');
    $this->actingAs($this->admin)->put('http://localhost/admin/payments/yoco', ['mode' => 'test', 'enabled' => true, 'is_default' => true, 'credentials' => ['secret_key' => 'sk_test_platform', 'webhook_secret' => $secret]])->assertSessionHasNoErrors();
    expect(PlatformGatewayConfig::query()->where('gateway', 'yoco')->value('enabled'))->toBeTrue();

    Http::fake(['payments.yoco.com/api/checkouts' => Http::response(['id' => 'ch_9', 'redirectUrl' => 'https://c.yoco.com/checkout/ch_9'])]);
    $this->get("http://localhost/billing/pay/{$invoice->checkout_token}")->assertRedirect('https://c.yoco.com/checkout/ch_9');

    $body = ['type' => 'payment.succeeded', 'payload' => ['amount' => 171350, 'metadata' => ['reference' => $invoice->checkout_token, 'checkoutId' => 'ch_9']]];
    $raw = json_encode($body, JSON_THROW_ON_ERROR);
    $ts = (string) time();
    $sig = base64_encode(hash_hmac('sha256', "m1.{$ts}.{$raw}", 'platform-secret', true));

    $this->call('POST', 'http://localhost/api/webhooks/platform/yoco', [], [], [], [
        'CONTENT_TYPE' => 'application/json', 'HTTP_WEBHOOK_ID' => 'm1', 'HTTP_WEBHOOK_TIMESTAMP' => $ts, 'HTTP_WEBHOOK_SIGNATURE' => "v1,{$sig}",
    ], $raw)->assertSee('OK');

    expect($invoice->fresh()?->status)->toBe('paid')
        ->and($subscription->fresh()?->status)->toBe(SubscriptionStatus::Active)
        ->and($subscription->fresh()?->current_period_ends_at?->isSameDay($invoice->period_end))->toBeTrue()
        ->and($this->clinic->fresh()?->status)->toBe(ProviderStatus::Active);
});
