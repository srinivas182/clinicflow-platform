<?php

use App\Domains\Billing\Actions\IssueCreditNote;
use App\Domains\Billing\Actions\RecordPayment;
use App\Domains\Billing\Enums\PaymentMethod;
use App\Domains\Billing\Models\Invoice;
use App\Domains\Billing\Models\SubscriptionInvoice;
use App\Domains\Finance\Actions\CloseCashUp;
use App\Domains\Finance\Models\LedgerEntry;
use App\Domains\Finance\Support\RevenueReport;
use App\Domains\Identity\Actions\AddStaffMember;
use App\Domains\Identity\Enums\StaffRole;
use App\Domains\Identity\Models\Staff;
use App\Domains\Lab\Actions\LabWorkflow;
use App\Domains\Messaging\Contracts\MessageSender;
use App\Domains\Messaging\Models\MessageUsage;
use App\Domains\Pharmacy\Actions\DispensePrescription;
use App\Domains\Pharmacy\Actions\ReceiveStock;
use App\Domains\Platform\Actions\IssueSubscriptionInvoices;
use App\Domains\Platform\Enums\ProviderType;
use App\Domains\Platform\Enums\SubscriptionStatus;
use App\Domains\Platform\Models\Package;
use App\Domains\Platform\Models\Provider;
use App\Domains\Platform\Models\Subscription;
use App\Domains\Prescribing\Models\Prescription;
use App\Domains\Visits\Actions\CheckInPatient;
use App\Domains\Visits\Enums\PayerType;
use App\Models\User;
use Database\Seeders\ClinicalReferenceSeeder;
use Database\Seeders\PackageSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Validation\ValidationException;

uses(DatabaseMigrations::class);

beforeEach(function (): void {
    if (config('database.default') !== 'mysql') {
        $this->markTestSkipped('Tenancy tests require MySQL.');
    }

    $this->seed(ClinicalReferenceSeeder::class);
    $this->clinic = makeProvider('Sunrise Medical Centre', ProviderType::Clinic, 'sunrise.clinicflow.test');
    $add = app(AddStaffMember::class);
    foreach (['doctorUser' => StaffRole::Doctor, 'techUser' => StaffRole::LabTechnician, 'tech2User' => StaffRole::LabTechnician, 'receptionUser' => StaffRole::Receptionist, 'managerUser' => StaffRole::Manager] as $key => $role) {
        $this->{$key} = User::factory()->create();
        $add->handle($this->clinic, $this->{$key}, $role);
    }
    tenancy()->initialize($this->clinic);
    $this->doctor = Staff::query()->findOrFail($this->doctorUser->id);
    $this->tech = Staff::query()->findOrFail($this->techUser->id);
    $this->tech2 = Staff::query()->findOrFail($this->tech2User->id);
});

afterEach(function (): void {
    tenancy()->end();
    Provider::query()->get()->each->delete();
});

it('runs the lab workflow with a second verifier and gates critical results before release', function (): void {
    $patient = registerTestPatient('Pieter', '700101', null, '0823334444');
    $consult = seenByDoctor($this, $patient, PayerType::Cash);
    $lab = app(LabWorkflow::class);

    $order = $lab->order($consult->visit, $this->doctor, ['HB', 'K']);
    $invoice = Invoice::query()->where('visit_id', $consult->visit_id)->sole();
    expect($invoice->lines()->where('kind', 'lab')->sum('total_cents'))->toEqual(29000)
        ->and($invoice->lines()->where('kind', 'lab')->pluck('attributed_staff_id')->unique()->all())->toBe([$this->doctor->id]);

    $lab->collect($order, $this->tech);
    expect($order->fresh()?->sample_barcode)->toStartWith('LAB-')
        ->and(fn () => $lab->enterResults($order->fresh(), ['HB' => 13.1], $this->tech))->toThrow(ValidationException::class);

    $lab->enterResults($order->fresh(), ['HB' => 13.1, 'K' => 6.5], $this->tech);
    expect($order->fresh()?->has_critical)->toBeTrue()
        ->and($order->results()->where('test_code', 'K')->value('flag'))->toBe('critical_high')
        ->and($order->results()->where('test_code', 'HB')->value('flag'))->toBe('normal')
        ->and(fn () => $lab->verify($order->fresh(), $this->tech))->toThrow(ValidationException::class);

    $lab->verify($order->fresh(), $this->tech2);
    expect(fn () => $lab->release($order->fresh(), $this->doctor))->toThrow(ValidationException::class);
    $lab->review($order->fresh(), $this->doctor, 'Repeat potassium today');
    expect(fn () => $lab->release($order->fresh(), $this->doctor))->toThrow(ValidationException::class);
    $lab->acknowledgeCritical($order->fresh(), $this->doctor, 'Phoned patient; sent to casualty');
    $lab->release($order->fresh(), $this->doctor);

    $sent = app(MessageSender::class)->sent;
    expect($order->fresh()?->status)->toBe('released')
        ->and($sent)->toHaveCount(1)
        ->and($sent[0]['recipient'])->toBe('0823334444')
        ->and($sent[0]['body'])->not->toContain('6.5')
        ->and(MessageUsage::query()->where('tenant_id', $this->clinic->id)->value('units'))->toBe(1);
});

it('credits invoices without editing paid lines and keeps the ledger balanced', function (): void {
    $patient = registerTestPatient('Ayesha', '970314');
    $consult = seenByDoctor($this, $patient, PayerType::Cash);
    $invoice = Invoice::query()->where('visit_id', $consult->visit_id)->sole();

    expect(fn () => app(IssueCreditNote::class)->handle($invoice, 99999999, 'Too much'))->toThrow(ValidationException::class)
        ->and(fn () => app(IssueCreditNote::class)->handle($invoice, 1000, ' '))->toThrow(ValidationException::class);

    $note = app(IssueCreditNote::class)->handle($invoice, 12000, 'Goodwill discount');
    $invoice->refresh();

    expect($note->number)->toStartWith('CN-')
        ->and($invoice->total_cents)->toBe(40000)
        ->and($invoice->needs_review)->toBeTrue()
        ->and($invoice->review_note)->toContain('refund R120.00')
        ->and((int) LedgerEntry::query()->sum('debit_cents'))->toBe((int) LedgerEntry::query()->sum('credit_cents'))
        ->and(LedgerEntry::query()->where('account', 'bank:cash')->sum('debit_cents'))->toEqual(52000)
        ->and(fn () => LedgerEntry::query()->firstOrFail()->forceFill(['debit_cents' => 1])->save())->toThrow(LogicException::class);
});

it('closes a cashier drawer once a day and needs a reason for a difference', function (): void {
    $patient = registerTestPatient('Thabo', '880808', null, '0825557777');
    $visit = app(CheckInPatient::class)->handle($patient, PayerType::Cash);
    $invoice = Invoice::query()->where('visit_id', $visit->id)->sole();
    $reception = User::query()->findOrFail($this->receptionUser->id);
    app(RecordPayment::class)->handle($invoice, PaymentMethod::Cash, 30000, null, $reception);
    app(RecordPayment::class)->handle($invoice, PaymentMethod::CardMachine, 22000, 'SLIP-1', $reception);
    $cashUp = app(CloseCashUp::class);

    expect($cashUp->expected($reception->id))->toBe(['cash' => 30000, 'card_machine' => 22000])
        ->and(fn () => $cashUp->handle($reception->id, 29000))->toThrow(ValidationException::class);

    $closed = $cashUp->handle($reception->id, 29000, 'R10 note given as change by mistake');
    expect($closed->difference_cents)->toBe(-1000)
        ->and($invoice->payments()->whereNull('cash_up_id')->count())->toBe(0)
        ->and(fn () => $cashUp->handle($reception->id, 0))->toThrow(ValidationException::class);
});

it('attributes revenue to the seeing, prescribing and ordering doctor', function (): void {
    app(ReceiveStock::class)->handle(medicineId('Paracetamol'), 'PA-1', now()->addYear(), 50, 50);
    [$visit] = atPharmacy($this, PayerType::Cash, [['Paracetamol', 10]]);
    app(LabWorkflow::class)->order($visit, $this->doctor, ['CRP']);
    $script = Prescription::query()->sole();
    app(DispensePrescription::class)->handle($script, $visit->fresh(), Staff::query()->findOrFail($this->doctorUser->id));

    $row = collect(RevenueReport::byDoctor(now()->startOfDay(), now()->endOfDay()))->firstWhere('doctor', $this->doctor->name);
    expect($row['consultation'])->toBe(52000)->and($row['medicine'])->toBe(500)->and($row['lab'])->toBe(16000)->and($row['total'])->toBe(68500);

    tenancy()->end();
    $this->actingAs($this->managerUser)->get('http://sunrise.clinicflow.test/finance')->assertOk();
    $this->actingAs($this->receptionUser)->get('http://sunrise.clinicflow.test/finance')->assertForbidden();
});

it('charges messaging above the package allowance on the next subscription invoice', function (): void {
    tenancy()->end();
    $this->seed(PackageSeeder::class);
    $package = Package::query()->where('code', 'clinic-starter')->sole();
    Subscription::create(['tenant_id' => $this->clinic->id, 'package_id' => $package->id, 'status' => SubscriptionStatus::Trialing, 'trial_ends_at' => now()->addDays(2)]);
    MessageUsage::create(['tenant_id' => $this->clinic->id, 'period' => now()->addDays(2)->subMonthNoOverflow()->format('Y-m'), 'units' => 250, 'sms_units' => 250]);

    app(IssueSubscriptionInvoices::class)->handle();
    $invoice = SubscriptionInvoice::query()->sole();

    expect($invoice->messaging_units)->toBe(250)
        ->and($invoice->messaging_overage_cents)->toBe(50 * 35)
        ->and($invoice->amount_cents)->toBe($package->price_monthly_cents + 1750);
});
