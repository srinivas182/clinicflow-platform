<?php

use App\Domains\Billing\Actions\AddInvoiceLine;
use App\Domains\Billing\Actions\RecordPayment;
use App\Domains\Billing\Enums\LineKind;
use App\Domains\Billing\Enums\PaymentMethod;
use App\Domains\Billing\Models\Invoice;
use App\Domains\Billing\Models\SubscriptionInvoice;
use App\Domains\Billing\Prepaid\PrepaidPackages;
use App\Domains\Identity\Actions\AddStaffMember;
use App\Domains\Identity\Enums\StaffRole;
use App\Domains\Platform\Actions\SettleSubscriptionInvoice;
use App\Domains\Platform\Enums\ProviderType;
use App\Domains\Platform\Enums\SubscriptionStatus;
use App\Domains\Platform\Models\Package;
use App\Domains\Platform\Models\Provider;
use App\Domains\Platform\Models\Subscription;
use App\Domains\Platform\Resellers\ResellerProgramme;
use App\Domains\Visits\Actions\CheckInPatient;
use App\Domains\Visits\Enums\PayerType;
use App\Models\User;
use Database\Seeders\PackageSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

uses(DatabaseMigrations::class);

beforeEach(function (): void {
    if (config('database.default') !== 'mysql') {
        $this->markTestSkipped('Tenancy tests require MySQL.');
    }
    $this->seed(PackageSeeder::class);
    $this->clinic = makeProvider('Sunrise Medical Centre', ProviderType::Clinic, 'sunrise.clinicflow.test');
    $this->ownerUser = User::factory()->create();
    app(AddStaffMember::class)->handle($this->clinic, $this->ownerUser, StaffRole::Owner);
});

afterEach(function (): void {
    tenancy()->end();
    Provider::query()->get()->each->delete();
});

it('sells a prepaid package, activates it on payment for three years and uses it against matching invoice lines only', function (): void {
    $this->clinic->run(function (): void {
        $packages = app(PrepaidPackages::class);
        expect(fn () => $packages->savePackage(null, 'Bad', null, 100000, [['service' => 'anything', 'label' => 'x', 'quantity' => 1]]))->toThrow(ValidationException::class);
        $id = $packages->savePackage(null, '3 consultations', 'Three GP consultations', 120000, [['service' => 'consultation', 'label' => 'Consultation', 'quantity' => 3]]);

        $patient = registerTestPatient('Thandi', '880412');
        $saleInvoice = Invoice::query()->findOrFail($packages->sell($patient, $id));
        expect($saleInvoice->total_cents)->toBe(120000)->and(DB::table('patient_packages')->value('status'))->toBe('pending');

        app(RecordPayment::class)->handle($saleInvoice, PaymentMethod::Cash, 120000);
        $pp = DB::table('patient_packages')->first();
        expect($pp->status)->toBe('active')->and(substr((string) $pp->expires_at, 0, 10))->toBe(now()->addYears(3)->toDateString());

        $visit = app(CheckInPatient::class)->handle($patient, PayerType::Cash);
        $invoice = Invoice::query()->where('visit_id', $visit->id)->sole();
        $consult = $invoice->lines()->get()->first(fn ($l) => PrepaidPackages::serviceFor($l) === 'consultation');
        $packages->redeem($pp->id, $consult, $this->ownerUser->id);
        expect($invoice->fresh()?->balanceCents())->toBe(0)
            ->and(PrepaidPackages::remaining(DB::table('patient_packages')->first()))->toBe(['consultation' => 2])
            ->and(fn () => $packages->redeem($pp->id, $consult, $this->ownerUser->id))->toThrow(ValidationException::class);

        $lab = app(AddInvoiceLine::class)->handle($invoice->fresh(), LineKind::Lab, 'Lab: HbA1c', 25000, 1, 'HBA1C');
        expect(fn () => $packages->redeem($pp->id, $lab, $this->ownerUser->id))->toThrow(ValidationException::class);

        $this->travel(3)->years();
        $this->travel(1)->days();
        expect($packages->expire())->toBe(1);
    });
});

it('credits resellers for referred practices within their commission window and records EFT payouts', function (): void {
    $programme = app(ResellerProgramme::class);
    $resellerUser = User::factory()->create(['email' => 'agent@resell.test']);
    $id = $programme->create('Thuli Agents', 'agent@resell.test', null, 20, 12);
    $code = (string) DB::table('resellers')->where('id', $id)->value('code');

    $this->get('http://localhost/?ref='.$code)->assertCookie(ResellerProgramme::COOKIE);
    $this->get('http://localhost/?ref=NOPE999')->assertCookieMissing(ResellerProgramme::COOKIE);

    $programme->attach($this->clinic, $code);
    $sub = Subscription::create(['tenant_id' => $this->clinic->id, 'package_id' => Package::query()->where('code', 'clinic-starter')->value('id'), 'status' => SubscriptionStatus::Active, 'current_period_ends_at' => now()->addMonth()]);
    $invoice = SubscriptionInvoice::create(['number' => 'CF-R1', 'tenant_id' => $this->clinic->id, 'subscription_id' => $sub->id, 'period_start' => now(), 'period_end' => now()->addMonth(),
        'amount_cents' => 149000, 'messaging_units' => 0, 'messaging_overage_cents' => 0, 'vat_cents' => 22350, 'total_cents' => 171350, 'status' => 'open', 'checkout_token' => 'r1', 'due_at' => now()->addWeek()]);
    app(SettleSubscriptionInvoice::class)->settle($invoice, 'eft', 'X1');
    expect(DB::table('reseller_commissions')->sole()->amount_cents)->toBe(29800);

    $this->travel(13)->months();
    $late = SubscriptionInvoice::create(['number' => 'CF-R2', 'tenant_id' => $this->clinic->id, 'subscription_id' => $sub->id, 'period_start' => now(), 'period_end' => now()->addMonth(),
        'amount_cents' => 149000, 'messaging_units' => 0, 'messaging_overage_cents' => 0, 'vat_cents' => 22350, 'total_cents' => 171350, 'status' => 'open', 'checkout_token' => 'r2', 'due_at' => now()->addWeek()]);
    app(SettleSubscriptionInvoice::class)->settle($late, 'eft', 'X2');
    expect(DB::table('reseller_commissions')->count())->toBe(1);
    $this->travelBack();

    $period = (string) DB::table('reseller_commissions')->value('period');
    expect(fn () => $programme->markPaid($id, $period, ''))->toThrow(ValidationException::class);
    expect($programme->markPaid($id, $period, 'EFT-OCT'))->toBe(1);

    $this->actingAs($resellerUser)->get('http://localhost/reseller')->assertOk()
        ->assertInertia(fn ($p) => $p->component('Reseller/Portal')->where('periods.0.paid', true)->where('referrals.0.practice', 'Sunrise Medical Centre'));
    $this->actingAs($this->ownerUser)->get('http://localhost/reseller')->assertForbidden();
});
