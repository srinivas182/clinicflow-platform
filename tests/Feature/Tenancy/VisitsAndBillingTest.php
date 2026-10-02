<?php

use App\Domains\Billing\Actions\AddInvoiceLine;
use App\Domains\Billing\Actions\RecordPayment;
use App\Domains\Billing\Actions\RefundPayment;
use App\Domains\Billing\Actions\RemoveInvoiceLine;
use App\Domains\Billing\Contracts\PaymentGateway;
use App\Domains\Billing\Enums\InvoiceStatus;
use App\Domains\Billing\Enums\LineKind;
use App\Domains\Billing\Enums\PaymentMethod;
use App\Domains\Billing\Enums\PaymentStatus;
use App\Domains\Billing\Models\Invoice;
use App\Domains\Identity\Actions\AddStaffMember;
use App\Domains\Identity\Enums\StaffRole;
use App\Domains\Patients\Actions\RegisterPatient;
use App\Domains\Patients\Enums\Channel;
use App\Domains\Patients\Enums\ConsentGivenBy;
use App\Domains\Patients\Enums\IdType;
use App\Domains\Patients\Models\Patient;
use App\Domains\Patients\Support\RegistrationData;
use App\Domains\Platform\Enums\ProviderType;
use App\Domains\Platform\Models\Provider;
use App\Domains\Platform\Models\Setting;
use App\Domains\Scheduling\Enums\AppointmentStatus;
use App\Domains\Scheduling\Models\Appointment;
use App\Domains\Visits\Actions\CheckInPatient;
use App\Domains\Visits\Actions\DeviceTokens;
use App\Domains\Visits\Actions\IssueTicket;
use App\Domains\Visits\Actions\RemoveFromQueue;
use App\Domains\Visits\Actions\TransitionVisit;
use App\Domains\Visits\Enums\LeftReason;
use App\Domains\Visits\Enums\PayerType;
use App\Domains\Visits\Enums\VisitStage;
use App\Domains\Visits\Models\Visit;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Validation\ValidationException;

uses(DatabaseMigrations::class);

function registerTestPatient(string $first, string $idDob, ?string $scheme = null, string $cell = '0825550147'): Patient
{
    return app(RegisterPatient::class)->handle(new RegistrationData(
        firstNames: $first, surname: 'Test', idType: IdType::SaId, idNumber: saId($idDob), passportCountry: null, dateOfBirth: null,
        cell: $cell, noCell: false, email: null, preferredLanguage: 'en', preferredChannel: Channel::Sms, address: null,
        guardianName: null, guardianRelationship: null, guardianCell: null, popiaConsent: true, treatmentConsent: true,
        consentGivenBy: ConsentGivenBy::Patient, maturityConfirmed: false, medicalAidScheme: $scheme,
    ));
}

beforeEach(function (): void {
    if (config('database.default') !== 'mysql') {
        $this->markTestSkipped('Tenancy tests require MySQL.');
    }

    $this->clinic = makeProvider('Sunrise Medical Centre', ProviderType::Clinic, 'sunrise.clinicflow.test');
    $this->receptionist = User::factory()->create();
    $this->clerk = User::factory()->create();
    app(AddStaffMember::class)->handle($this->clinic, $this->receptionist, StaffRole::Receptionist);
    app(AddStaffMember::class)->handle($this->clinic, $this->clerk, StaffRole::BillingClerk);

    tenancy()->initialize($this->clinic);
    $this->cash = registerTestPatient('Ayesha', '970314');
    $this->aid = registerTestPatient('Sipho', '850101', 'Discovery Health', '0821112222');
});

afterEach(function (): void {
    tenancy()->end();
    Provider::query()->get()->each->delete();
});

it('issues sequential tickets that restart every day', function (): void {
    $tickets = app(IssueTicket::class);
    $today = CarbonImmutable::today();

    expect($tickets->handle($today))->toBe('A001')
        ->and($tickets->handle($today))->toBe('A002')
        ->and($tickets->handle($today->addDay()))->toBe('A001');
});

it('opens the visit and its invoice with the consult fee at check-in', function (): void {
    $visit = app(CheckInPatient::class)->handle($this->cash);
    $invoice = Invoice::query()->where('visit_id', $visit->id)->sole();

    expect($visit->stage)->toBe(VisitStage::CheckedIn)
        ->and($visit->payer_type)->toBe(PayerType::Cash)
        ->and($invoice->total_cents)->toBe(52000)
        ->and($invoice->number)->toStartWith('INV-'.now()->format('Y').'-')
        ->and(fn () => app(CheckInPatient::class)->handle($this->cash))->toThrow(ValidationException::class);
});

it('makes cash patients pay the consult fee before triage, but not medical aid patients', function (): void {
    $cashVisit = app(CheckInPatient::class)->handle($this->cash);
    $aidVisit = app(CheckInPatient::class)->handle($this->aid);
    $move = app(TransitionVisit::class);

    expect(fn () => $move->handle($cashVisit, VisitStage::Triage))->toThrow(ValidationException::class);
    expect($move->handle($aidVisit, VisitStage::Triage)->stage)->toBe(VisitStage::Triage);

    $invoice = Invoice::query()->where('visit_id', $cashVisit->id)->sole();
    app(RecordPayment::class)->handle($invoice, PaymentMethod::CardMachine, 52000, 'SLIP-4471');

    expect($move->handle($cashVisit->fresh(), VisitStage::Triage)->stage)->toBe(VisitStage::Triage);
});

it('lets cash patients pay at the end when the clinic chooses that', function (): void {
    Setting::put('billing', 'payment_timing', 'end');
    $visit = app(CheckInPatient::class)->handle($this->cash);

    expect(app(TransitionVisit::class)->handle($visit, VisitStage::Triage)->stage)->toBe(VisitStage::Triage);
});

it('enforces the visit lifecycle on the server', function (): void {
    Setting::put('billing', 'payment_timing', 'end');
    $visit = app(CheckInPatient::class)->handle($this->cash);
    $move = app(TransitionVisit::class);

    expect(fn () => $move->handle($visit, VisitStage::Pharmacy))->toThrow(ValidationException::class);

    $move->handle($visit, VisitStage::Triage);
    $move->handle($visit, VisitStage::Doctor);
    $move->handle($visit, VisitStage::Pharmacy);
    $move->handle($visit, VisitStage::Doctor); // pharmacist query loops back
    $move->handle($visit, VisitStage::Done);

    expect(fn () => $move->handle($visit, VisitStage::Doctor))->toThrow(ValidationException::class)
        ->and($visit->events()->count())->toBe(6);
});

it('removes only waiting patients and flags a prepaid fee by the refund rule', function (): void {
    $appointment = Appointment::create([
        'patient_id' => $this->cash->id, 'staff_id' => $this->receptionist->id, 'consult_type' => 'in_person',
        'starts_at' => now(), 'ends_at' => now()->addMinutes(15), 'status' => AppointmentStatus::Booked,
    ]);
    $visit = app(CheckInPatient::class)->handle($this->cash, null, $appointment);
    $invoice = Invoice::query()->where('visit_id', $visit->id)->sole();
    app(RecordPayment::class)->handle($invoice, PaymentMethod::Cash, 52000);

    app(RemoveFromQueue::class)->handle($visit, LeftReason::LeftBeforeSeen);

    expect($visit->fresh()?->stage)->toBe(VisitStage::Left)
        ->and($invoice->fresh()?->needs_review)->toBeTrue()
        ->and($invoice->fresh()?->review_note)->toContain('refund due')
        ->and($appointment->fresh()?->status)->toBe(AppointmentStatus::NoShow)
        ->and(fn () => app(RemoveFromQueue::class)->handle($visit, LeftReason::EnteredInError))->toThrow(ValidationException::class);
});

it('takes partial and full payments and locks paid lines', function (): void {
    $visit = app(CheckInPatient::class)->handle($this->cash);
    $invoice = Invoice::query()->where('visit_id', $visit->id)->sole();
    $pay = app(RecordPayment::class);

    expect(fn () => $pay->handle($invoice, PaymentMethod::CardMachine, 10000))->toThrow(ValidationException::class)
        ->and(fn () => $pay->handle($invoice, PaymentMethod::Cash, 60000))->toThrow(ValidationException::class);

    $pay->handle($invoice, PaymentMethod::Cash, 20000);
    expect($invoice->fresh()?->status)->toBe(InvoiceStatus::PartPaid);

    $pay->handle($invoice->fresh(), PaymentMethod::Cash, 32000);
    $invoice = $invoice->fresh();
    expect($invoice?->status)->toBe(InvoiceStatus::Paid)
        ->and($invoice?->lines()->whereNull('locked_at')->count())->toBe(0);

    $consult = $invoice->lines()->firstOrFail();
    expect(fn () => app(RemoveInvoiceLine::class)->handle($consult))->toThrow(ValidationException::class);

    $extra = app(AddInvoiceLine::class)->handle($invoice, LineKind::Certificate, 'Sick note', 5000);
    expect($invoice->fresh()?->status)->toBe(InvoiceStatus::PartPaid);
    app(RemoveInvoiceLine::class)->handle($extra);
    expect($invoice->fresh()?->status)->toBe(InvoiceStatus::Paid);
});

it('settles a pay link only when the gateway confirms it', function (): void {
    $visit = app(CheckInPatient::class)->handle($this->cash);
    $invoice = Invoice::query()->where('visit_id', $visit->id)->sole();
    $payment = app(RecordPayment::class)->handle($invoice, PaymentMethod::PayLink, 52000);

    expect($payment->status)->toBe(PaymentStatus::Pending)
        ->and($invoice->fresh()?->status)->toBe(InvoiceStatus::Open)
        ->and(app(PaymentGateway::class)->calls)->toHaveCount(1);

    app(RecordPayment::class)->confirm($payment);
    expect($invoice->fresh()?->status)->toBe(InvoiceStatus::Paid);
});

it('refunds with a reason and never more than was paid', function (): void {
    $visit = app(CheckInPatient::class)->handle($this->cash);
    $invoice = Invoice::query()->where('visit_id', $visit->id)->sole();
    $payment = app(RecordPayment::class)->handle($invoice, PaymentMethod::Cash, 52000);
    $refund = app(RefundPayment::class);

    expect(fn () => $refund->handle($payment, 1000, ' '))->toThrow(ValidationException::class)
        ->and(fn () => $refund->handle($payment, 60000, 'Too much'))->toThrow(ValidationException::class);

    $refund->handle($payment, 52000, 'Left before being seen');
    expect($invoice->fresh()?->paid_cents)->toBe(0)
        ->and($invoice->fresh()?->status)->toBe(InvoiceStatus::Open);
});

it('lets the billing clerk refund but not reception', function (): void {
    $visit = app(CheckInPatient::class)->handle($this->cash);
    $invoice = Invoice::query()->where('visit_id', $visit->id)->sole();
    $payment = app(RecordPayment::class)->handle($invoice, PaymentMethod::Cash, 52000);
    tenancy()->end();

    $this->actingAs($this->receptionist)->post("http://sunrise.clinicflow.test/payments/{$payment->id}/refunds", ['amount' => 520, 'reason' => 'x'])->assertForbidden();
    $this->actingAs($this->clerk)->post("http://sunrise.clinicflow.test/payments/{$payment->id}/refunds", ['amount' => 520, 'reason' => 'Duplicate'])->assertSessionHasNoErrors();
});

it('checks in a booked patient at the kiosk and shows tickets without names', function (): void {
    Appointment::create([
        'patient_id' => $this->aid->id, 'staff_id' => $this->receptionist->id, 'consult_type' => 'in_person',
        'starts_at' => now(), 'ends_at' => now()->addMinutes(15), 'status' => AppointmentStatus::Booked,
    ]);
    $tokens = app(DeviceTokens::class)->ensure();
    tenancy()->end();

    $this->post('http://sunrise.clinicflow.test/kiosk/wrong-token', ['cell' => '0821112222'])->assertNotFound();
    $this->post("http://sunrise.clinicflow.test/kiosk/{$tokens['kiosk']}", ['cell' => '0829999999'])->assertSessionHasErrors('cell');
    $this->post("http://sunrise.clinicflow.test/kiosk/{$tokens['kiosk']}", ['cell' => '0821112222'])->assertSessionHas('success', 'A001');

    $this->get("http://sunrise.clinicflow.test/display/{$tokens['display']}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Devices/Display')->where('waiting.0.ticket', 'A001'))
        ->assertDontSee('Sipho');

    $this->clinic->run(fn () => expect(Visit::query()->sole()->check_in_channel)->toBe('kiosk'));
});

it('opens the front desk for reception and checks in over HTTP', function (): void {
    $patientId = $this->aid->id;
    tenancy()->end();

    $this->actingAs($this->receptionist)->get('http://sunrise.clinicflow.test/front-desk?search=Sipho')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('FrontDesk/Index')->has('results', 1));

    $this->actingAs($this->receptionist)->post('http://sunrise.clinicflow.test/visits', ['patient_id' => $patientId, 'payer_type' => 'medical_aid'])
        ->assertRedirect()->assertSessionHas('success');

    $lab = User::factory()->create();
    app(AddStaffMember::class)->handle($this->clinic, $lab, StaffRole::LabTechnician);
    $this->actingAs($lab)->get('http://sunrise.clinicflow.test/front-desk')->assertForbidden();
});
