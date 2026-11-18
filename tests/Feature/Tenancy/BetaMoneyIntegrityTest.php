<?php

use App\Domains\Billing\Models\SubscriptionInvoice;
use App\Domains\Identity\Actions\AddStaffMember;
use App\Domains\Identity\Enums\StaffRole;
use App\Domains\Pharmacy\Delivery\CourierAccount;
use App\Domains\Pharmacy\Delivery\CourierPartner;
use App\Domains\Platform\Enums\ProviderType;
use App\Domains\Platform\Enums\SubscriptionStatus;
use App\Domains\Platform\Models\Package;
use App\Domains\Platform\Models\Provider;
use App\Domains\Platform\Models\Subscription;
use App\Domains\Wallet\Actions\StartTopup;
use App\Domains\Wallet\Actions\WalletLedger;
use App\Domains\Wallet\Models\Wallet;
use App\Domains\Wallet\Models\WalletTopup;
use App\Domains\Wallet\Notifications\AutoTopupPackChanged;
use App\Models\User;
use Database\Seeders\PackageSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Notification;

/*
 * Beta testing T1b: money and data integrity (tracker rows 40, 57, 261).
 * Free packages (28) are in SignupAndVerificationTest, group payments (89) in
 * BranchesGroupsDomainsCalendarTest, wording and reviewer checks (281, 199) in TranslationReviewTest,
 * bot protection keys (191) in BotProtectionTest.
 */

uses(DatabaseMigrations::class);

beforeEach(function (): void {
    if (config('database.default') !== 'mysql') {
        $this->markTestSkipped('Tenancy tests require MySQL.');
    }
    Notification::fake();
    $this->seed(PackageSeeder::class);
    $this->clinic = makeProvider('Sunrise Medical Centre', ProviderType::Clinic, 'sunrise.clinicflow.test');
    $this->owner = User::factory()->create(['email' => 'owner@sunrise.test']);
    app(AddStaffMember::class)->handle($this->clinic, $this->owner, StaffRole::Owner);
});

afterEach(function (): void {
    tenancy()->end();
    Provider::query()->get()->each->delete();
});

it('shows bank-transfer instructions instead of an error when online payment is not set up (beta #40)', function (): void {
    config(['clinicflow.payments.eft' => ['bank' => 'Example Bank', 'account_name' => 'Dr Business Flow', 'account_number' => '62000000000', 'branch_code' => '250655', 'email' => 'billing@example.test']]);
    $sub = Subscription::create(['tenant_id' => $this->clinic->id, 'package_id' => Package::query()->where('code', 'clinic-starter')->value('id'), 'status' => SubscriptionStatus::Trialing, 'trial_ends_at' => now()->addDays(2)]);
    $invoice = SubscriptionInvoice::create(['number' => 'CF-2026-000101', 'tenant_id' => $this->clinic->id, 'subscription_id' => $sub->id, 'period_start' => now(), 'period_end' => now()->addMonth(),
        'amount_cents' => 149000, 'messaging_units' => 0, 'messaging_overage_cents' => 0, 'vat_cents' => 22350, 'total_cents' => 171350, 'status' => 'open', 'checkout_token' => 'tok-eft-1', 'due_at' => now()->addWeek()]);

    $this->get('http://localhost/billing/pay/tok-eft-1')->assertOk()
        ->assertSee('Pay by EFT')->assertSee('CF-2026-000101')->assertSee('R1 713.50')->assertSee('62000000000');

    $wallet = Wallet::for($this->clinic->id);
    $topup = app(StartTopup::class)->create($wallet, 50000);
    $this->get("http://localhost/billing/topup/{$topup->checkout_token}")->assertOk()->assertSee('Nothing was charged');
    expect($invoice->fresh()?->status)->toBe('open');
});

it('keeps the saved courier key when the key box is left blank (beta #261)', function (): void {
    CourierPartner::create(['driver' => 'tcg', 'enabled' => true]);
    $put = fn (array $data) => $this->actingAs($this->owner)->put('http://sunrise.clinicflow.test/settings/couriers/tcg', $data + ['enabled' => true]);
    $put(['account_ref' => 'TCG-778', 'api_key' => 'tcg-key-123'])->assertSessionHasNoErrors();
    $put(['account_ref' => 'TCG-779', 'api_key' => ''])->assertSessionHasNoErrors();

    $this->clinic->run(function (): void {
        $account = CourierAccount::query()->where('driver', 'tcg')->sole();
        expect($account->account_ref)->toBe('TCG-779')->and($account->credentials['api_key'] ?? null)->toBe('tcg-key-123');
    });
});

it('tops up every other practice when one fails, and replaces a removed pack with the closest (beta #57)', function (): void {
    $other = makeProvider('Northside Clinic', ProviderType::Clinic, 'northside.clinicflow.test');
    $broken = makeProvider('Eastgate Clinic', ProviderType::Clinic, 'eastgate.clinicflow.test');
    foreach ([[$this->clinic, 75000], [$other, 50000], [$broken, 50000]] as [$p, $pack]) {
        Wallet::for($p->id)->forceFill(['balance_cents' => 0, 'auto_topup' => true, 'auto_topup_pack_cents' => $pack])->save();
    }
    // One practice's top-up throws (e.g. a gateway error): the others must still be processed.
    $this->app->bind(StartTopup::class, fn ($app) => new class($app->make(WalletLedger::class), $broken->id) extends StartTopup
    {
        public function __construct(WalletLedger $ledger, private string $brokenId)
        {
            parent::__construct($ledger);
        }

        public function create(Wallet $wallet, int $amountCents, string $method = 'pay_link'): WalletTopup
        {
            if ($wallet->tenant_id === $this->brokenId) {
                throw new RuntimeException('gateway down');
            }

            return parent::create($wallet, $amountCents, $method);
        }
    });

    $this->artisan('wallet:auto-topup')->assertFailed();

    // 75 000 c is no longer offered: the closest pack (50 000 c, the lower of two equally close) is used and the owner told.
    expect(Wallet::for($this->clinic->id)->auto_topup_pack_cents)->toBe(50000)
        ->and(WalletTopup::query()->whereIn('wallet_id', [Wallet::for($this->clinic->id)->id, Wallet::for($other->id)->id])->count())->toBe(2)
        ->and(WalletTopup::query()->where('wallet_id', Wallet::for($broken->id)->id)->count())->toBe(0);
    Notification::assertSentTo($this->owner, AutoTopupPackChanged::class, fn (AutoTopupPackChanged $n) => $n->oldPack === 'R750.00' && $n->newPack === 'R500.00');
});
