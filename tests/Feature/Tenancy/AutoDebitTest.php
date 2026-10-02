<?php

use App\Domains\Billing\Actions\CollectSubscriptionDebits;
use App\Domains\Billing\Enums\GatewayMode;
use App\Domains\Billing\Gateways\PayFastGateway;
use App\Domains\Billing\Models\BillingMandate;
use App\Domains\Billing\Models\DebitAttempt;
use App\Domains\Billing\Models\SubscriptionInvoice;
use App\Domains\Billing\Notifications\SubscriptionDebitNotice;
use App\Domains\Identity\Actions\AddStaffMember;
use App\Domains\Identity\Enums\StaffRole;
use App\Domains\Platform\Actions\IssueSubscriptionInvoices;
use App\Domains\Platform\Enums\ProviderType;
use App\Domains\Platform\Enums\SubscriptionStatus;
use App\Domains\Platform\Models\Package;
use App\Domains\Platform\Models\Provider;
use App\Domains\Platform\Models\Subscription;
use App\Models\User;
use Database\Seeders\PackageSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;

uses(DatabaseMigrations::class);

beforeEach(function (): void {
    if (config('database.default') !== 'mysql') {
        $this->markTestSkipped('Tenancy tests require MySQL.');
    }

    Notification::fake();
    $this->seed(PackageSeeder::class);
    $this->clinic = makeProvider('Sunrise Medical Centre', ProviderType::Clinic, 'sunrise.clinicflow.test');
    $this->owner = User::factory()->create(['email' => 'owner@sunrise.test']);
    $this->receptionist = User::factory()->create();
    app(AddStaffMember::class)->handle($this->clinic, $this->owner, StaffRole::Owner);
    app(AddStaffMember::class)->handle($this->clinic, $this->receptionist, StaffRole::Receptionist);
    $this->admin = User::factory()->create();
    $this->admin->forceFill(['is_platform_admin' => true])->save();

    $this->subscription = Subscription::create([
        'tenant_id' => $this->clinic->id, 'package_id' => Package::query()->where('code', 'clinic-starter')->value('id'),
        'status' => SubscriptionStatus::Trialing, 'trial_ends_at' => now()->addDays(2),
    ]);
    app(IssueSubscriptionInvoices::class)->handle();
    $this->invoice = SubscriptionInvoice::query()->sole();
});

afterEach(function (): void {
    tenancy()->end();
    Provider::query()->get()->each->delete();
});

function connectPlatform(object $test, string $gateway, array $credentials): void
{
    $test->actingAs($test->admin)->put("http://localhost/admin/payments/{$gateway}", ['mode' => 'test', 'enabled' => true, 'is_default' => true, 'credentials' => $credentials])
        ->assertSessionHasNoErrors();
}

function paystackChargeWebhook(object $test, string $reference, int $amount, string $secret = 'sk_test_platform'): void
{
    $body = ['event' => 'charge.success', 'data' => [
        'reference' => $reference, 'status' => 'success', 'amount' => $amount, 'customer' => ['email' => 'owner@sunrise.test'],
        'authorization' => ['authorization_code' => 'AUTH_secret123', 'reusable' => true, 'card_type' => 'visa ', 'last4' => '4081', 'exp_month' => '12', 'exp_year' => '2030'],
    ]];
    $raw = json_encode($body, JSON_THROW_ON_ERROR);
    $test->call('POST', 'http://localhost/api/webhooks/platform/paystack', [], [], [], [
        'CONTENT_TYPE' => 'application/json', 'HTTP_X_PAYSTACK_SIGNATURE' => hash_hmac('sha512', $raw, $secret),
    ], $raw);
}

function setUpPaystackMandate(object $test): BillingMandate
{
    connectPlatform($test, 'paystack', ['public_key' => 'pk_test_p', 'secret_key' => 'sk_test_platform']);
    $test->actingAs($test->owner)->post("http://sunrise.clinicflow.test/settings/subscription/invoices/{$test->invoice->number}/auto-pay", ['consent' => true]);
    Http::fake(['api.paystack.co/transaction/initialize' => Http::response(['status' => true, 'data' => ['authorization_url' => 'https://checkout.paystack.com/x']])]);
    $test->get("http://localhost/billing/pay/{$test->invoice->checkout_token}");
    paystackChargeWebhook($test, $test->invoice->checkout_token, $test->invoice->total_cents);

    return BillingMandate::query()->sole();
}

function nextInvoice(object $test): SubscriptionInvoice
{
    $periodEnd = $test->subscription->fresh()->current_period_ends_at;
    test()->travelTo($periodEnd->copy()->subDays(2));
    app(IssueSubscriptionInvoices::class)->handle();
    test()->travelTo($periodEnd->copy()->addDay()->setTime(6, 0));

    return SubscriptionInvoice::query()->where('status', 'open')->sole();
}

it('saves the card only with the owner\'s consent and pays the first invoice', function (): void {
    connectPlatform($this, 'paystack', ['public_key' => 'pk_test_p', 'secret_key' => 'sk_test_platform']);

    $this->actingAs($this->receptionist)->post("http://sunrise.clinicflow.test/settings/subscription/invoices/{$this->invoice->number}/auto-pay", ['consent' => true])->assertForbidden();
    $this->actingAs($this->owner)->post("http://sunrise.clinicflow.test/settings/subscription/invoices/{$this->invoice->number}/auto-pay", [])->assertSessionHasErrors('consent');
    $this->actingAs($this->owner)->post("http://sunrise.clinicflow.test/settings/subscription/invoices/{$this->invoice->number}/auto-pay", ['consent' => true])
        ->assertRedirect("http://localhost/billing/pay/{$this->invoice->checkout_token}");
    expect($this->invoice->fresh()?->save_card)->toBeTrue();

    Http::fake(['api.paystack.co/transaction/initialize' => Http::response(['status' => true, 'data' => ['authorization_url' => 'https://checkout.paystack.com/x']])]);
    $this->get("http://localhost/billing/pay/{$this->invoice->checkout_token}")->assertRedirect('https://checkout.paystack.com/x');
    Http::assertSent(fn (ClientRequest $r) => $r['channels'] === ['card'] && $r['metadata']['save_card'] === true);

    paystackChargeWebhook($this, $this->invoice->checkout_token, $this->invoice->total_cents);

    $mandate = BillingMandate::query()->sole();
    expect($this->invoice->fresh()?->status)->toBe('paid')
        ->and($mandate->status)->toBe('active')
        ->and($mandate->label())->toBe('visa ending 4081')
        ->and($mandate->card_expiry)->toBe('12/2030')
        ->and($mandate->consented_by)->toBe($this->owner->id)
        ->and($mandate->getRawOriginal('token'))->not->toContain('AUTH_secret123');
});

it('does not save a card when the owner paid without consent', function (): void {
    connectPlatform($this, 'paystack', ['public_key' => 'pk_test_p', 'secret_key' => 'sk_test_platform']);
    Http::fake(['api.paystack.co/transaction/initialize' => Http::response(['status' => true, 'data' => ['authorization_url' => 'https://checkout.paystack.com/x']])]);
    $this->get("http://localhost/billing/pay/{$this->invoice->checkout_token}");
    paystackChargeWebhook($this, $this->invoice->checkout_token, $this->invoice->total_cents);

    expect($this->invoice->fresh()?->status)->toBe('paid')->and(BillingMandate::query()->count())->toBe(0);
});

it('charges the saved Paystack card when the next invoice is due and emails the owner', function (): void {
    setUpPaystackMandate($this);
    $next = nextInvoice($this);

    Http::fake(['api.paystack.co/transaction/charge_authorization' => Http::response(['status' => true, 'data' => ['status' => 'success']])]);
    expect(app(CollectSubscriptionDebits::class)->handle())->toBe(['paid' => 1, 'failed' => 0]);

    Http::assertSent(fn (ClientRequest $r) => str_contains($r->url(), 'charge_authorization') && $r['authorization_code'] === 'AUTH_secret123' && $r['amount'] === $next->total_cents);
    expect($next->fresh()?->status)->toBe('paid');
    Notification::assertSentTo($this->owner, SubscriptionDebitNotice::class, fn ($n) => $n->outcome === 'paid');
});

it('retries a failed debit twice, two days apart, then stops', function (): void {
    setUpPaystackMandate($this);
    $next = nextInvoice($this);
    Http::fake(['api.paystack.co/transaction/charge_authorization' => Http::response(['status' => true, 'data' => ['status' => 'failed', 'gateway_response' => 'Insufficient Funds']])]);
    $collect = app(CollectSubscriptionDebits::class);

    expect($collect->handle()['failed'])->toBe(1)
        ->and($collect->handle()['failed'])->toBe(0);

    $this->travel(2)->days();
    expect($collect->handle()['failed'])->toBe(1);
    $this->travel(2)->days();
    expect($collect->handle()['failed'])->toBe(1);
    $this->travel(2)->days();
    expect($collect->handle()['failed'])->toBe(0);

    expect(DebitAttempt::query()->where('subscription_invoice_id', $next->id)->count())->toBe(3)
        ->and($next->fresh()?->status)->toBe('open');
    Notification::assertSentTo($this->owner, SubscriptionDebitNotice::class, fn ($n) => $n->outcome === 'failed' && $n->detail === 'Insufficient Funds');
    Notification::assertSentTo($this->owner, SubscriptionDebitNotice::class, fn ($n) => $n->outcome === 'stopped');
});

it('switches auto-debit off, removes the card at Paystack and never charges again', function (): void {
    setUpPaystackMandate($this);
    Http::fake(['api.paystack.co/customer/deactivate_authorization' => Http::response(['status' => true])]);

    $this->actingAs($this->owner)->post('http://sunrise.clinicflow.test/settings/subscription/auto-debit/stop')->assertSessionHas('success');
    Http::assertSent(fn (ClientRequest $r) => str_contains($r->url(), 'deactivate_authorization') && $r['authorization_code'] === 'AUTH_secret123');
    expect(BillingMandate::query()->sole()->status)->toBe('revoked');

    nextInvoice($this);
    expect(app(CollectSubscriptionDebits::class)->handle())->toBe(['paid' => 0, 'failed' => 0]);
    Http::assertNotSent(fn (ClientRequest $r) => str_contains($r->url(), 'charge_authorization'));
});

it('lets PayFast run the subscription and settles each charge from its ITN', function (): void {
    $credentials = ['merchant_id' => '10000100', 'merchant_key' => '46f0cd694581a', 'passphrase' => 'jt7NOE43FZPn'];
    connectPlatform($this, 'payfast', $credentials);
    $this->actingAs($this->owner)->post("http://sunrise.clinicflow.test/settings/subscription/invoices/{$this->invoice->number}/auto-pay", ['consent' => true]);

    $this->get("http://localhost/billing/pay/{$this->invoice->checkout_token}")
        ->assertOk()->assertSee('subscription_type')->assertSee('recurring_amount')->assertSee('sandbox.payfast.co.za/eng/process', false);

    $payfast = new PayFastGateway($credentials, GatewayMode::Test);
    $itn = function (string $pfId, int $cents) use ($payfast): array {
        $fields = ['m_payment_id' => $this->invoice->checkout_token, 'pf_payment_id' => $pfId, 'payment_status' => 'COMPLETE',
            'amount_gross' => number_format($cents / 100, 2, '.', ''), 'email_address' => 'owner@sunrise.test', 'merchant_id' => '10000100', 'token' => 'pf-sub-token-1'];

        return [...$fields, 'signature' => $payfast->signature($fields)];
    };
    Http::fake(['sandbox.payfast.co.za/eng/query/validate' => Http::response('VALID')]);

    $this->post('http://localhost/api/webhooks/platform/payfast', $itn('1001', $this->invoice->total_cents))->assertOk();
    expect($this->invoice->fresh()?->status)->toBe('paid')
        ->and(BillingMandate::query()->sole()->gateway->value)->toBe('payfast');

    $next = nextInvoice($this);
    expect(app(CollectSubscriptionDebits::class)->handle())->toBe(['paid' => 0, 'failed' => 0]);

    $this->post('http://localhost/api/webhooks/platform/payfast', $itn('1002', $next->total_cents))->assertOk();
    $this->post('http://localhost/api/webhooks/platform/payfast', $itn('1002', $next->total_cents))->assertOk();
    expect($next->fresh()?->status)->toBe('paid')
        ->and(DebitAttempt::query()->where('reference', 'payfast-1002')->count())->toBe(1);
});

it('refuses auto-debit when the platform gateway is Yoco', function (): void {
    connectPlatform($this, 'yoco', ['secret_key' => 'sk_test_y', 'webhook_secret' => 'whsec_'.base64_encode('s')]);

    $this->actingAs($this->owner)->post("http://sunrise.clinicflow.test/settings/subscription/invoices/{$this->invoice->number}/auto-pay", ['consent' => true])
        ->assertSessionHasErrors('consent');
    $this->actingAs($this->owner)->get('http://sunrise.clinicflow.test/settings/subscription')
        ->assertInertia(fn ($page) => $page->where('autoDebitAvailable', false)->where('platformGateway', 'Yoco'));
});

it('shows saved cards to the super admin without the token', function (): void {
    setUpPaystackMandate($this);

    $this->actingAs($this->admin)->get('http://localhost/admin/auto-debits')
        ->assertInertia(fn ($page) => $page->component('Admin/AutoDebits')->where('mandates.0.card', 'visa ending 4081'))
        ->assertDontSee('AUTH_secret123');
});
