<?php

use App\Domains\Billing\Actions\AddInvoiceLine;
use App\Domains\Billing\Actions\RecordPayment;
use App\Domains\Billing\Enums\LineKind;
use App\Domains\Billing\Enums\PaymentMethod;
use App\Domains\Billing\Models\Invoice;
use App\Domains\Claims\Actions\ImportRemittances;
use App\Domains\Claims\Actions\SubmitClaim;
use App\Domains\Claims\Models\Claim;
use App\Domains\Claims\Support\ClaimAgeing;
use App\Domains\Clinical\Actions\CallNextPatient;
use App\Domains\Clinical\Actions\CompleteConsultation;
use App\Domains\Clinical\Actions\ManageQuote;
use App\Domains\Clinical\Actions\SaveConsultation;
use App\Domains\Clinical\Models\Consultation;
use App\Domains\Identity\Actions\AddStaffMember;
use App\Domains\Identity\Contracts\OtpSender;
use App\Domains\Identity\Enums\StaffRole;
use App\Domains\Identity\Models\Staff;
use App\Domains\Patients\Models\Patient;
use App\Domains\Pharmacy\Actions\ConfirmCollection;
use App\Domains\Pharmacy\Actions\DispensePrescription;
use App\Domains\Pharmacy\Actions\FulfilOwing;
use App\Domains\Pharmacy\Actions\QueryPrescription;
use App\Domains\Pharmacy\Actions\ReceiveStock;
use App\Domains\Pharmacy\Actions\ReturnUncollected;
use App\Domains\Pharmacy\Models\OwingItem;
use App\Domains\Pharmacy\Models\RegisterEntry;
use App\Domains\Pharmacy\Models\StockItem;
use App\Domains\Platform\Enums\ProviderType;
use App\Domains\Platform\Models\Provider;
use App\Domains\Prescribing\Actions\AmendPrescription;
use App\Domains\Prescribing\Actions\RequestSigningPin;
use App\Domains\Prescribing\Actions\SaveDraftPrescription;
use App\Domains\Prescribing\Actions\SignPrescription;
use App\Domains\Prescribing\Models\Medicine;
use App\Domains\Prescribing\Models\Prescription;
use App\Domains\Visits\Actions\CheckInPatient;
use App\Domains\Visits\Actions\TransitionVisit;
use App\Domains\Visits\Enums\PayerType;
use App\Domains\Visits\Enums\VisitStage;
use App\Domains\Visits\Models\Visit;
use App\Models\User;
use Database\Seeders\ClinicalReferenceSeeder;
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
    foreach (['doctorUser' => StaffRole::Doctor, 'pharmacistUser' => StaffRole::Pharmacist, 'receptionUser' => StaffRole::Receptionist, 'managerUser' => StaffRole::Manager] as $key => $role) {
        $this->{$key} = User::factory()->create();
        $add->handle($this->clinic, $this->{$key}, $role);
    }
    tenancy()->initialize($this->clinic);
    $this->doctor = Staff::query()->findOrFail($this->doctorUser->id);
    $this->pharmacist = Staff::query()->findOrFail($this->pharmacistUser->id);
});

afterEach(function (): void {
    tenancy()->end();
    Provider::query()->get()->each->delete();
});

function medicineId(string $name): int
{
    return (int) Medicine::query()->where('name', $name)->value('id');
}

/**
 * Checks a patient in, takes them through triage to the doctor and returns the consultation.
 */
function seenByDoctor(object $t, Patient $patient, PayerType $payer): Consultation
{
    $visit = app(CheckInPatient::class)->handle($patient, $payer);
    if ($payer === PayerType::Cash) {
        app(RecordPayment::class)->handle(Invoice::query()->where('visit_id', $visit->id)->sole(), PaymentMethod::Cash, 52000);
    }
    app(TransitionVisit::class)->handle($visit, VisitStage::Triage);
    app(TransitionVisit::class)->handle($visit, VisitStage::Doctor);
    $visit->forceFill(['doctor_id' => $t->doctor->id, 'called_at' => now()])->save();
    $consult = Consultation::create(['visit_id' => $visit->id, 'patient_id' => $patient->id, 'doctor_staff_id' => $t->doctor->id]);
    app(SaveConsultation::class)->handle($consult, ['assessment' => 'Seen'], [['code' => 'J20.9', 'primary' => true]], 1);

    return $consult->fresh();
}

/**
 * @param  list<array{0: string, 1: int}>  $lines  [medicine name, quantity]
 */
function signedScript(object $t, Consultation $consult, array $lines): Prescription
{
    $draft = app(SaveDraftPrescription::class)->handle($consult, $t->doctor, array_map(fn (array $l) => [
        'medicine_id' => medicineId($l[0]), 'dose' => 'As directed', 'quantity' => $l[1], 'repeats' => 0,
    ], $lines));
    app(RequestSigningPin::class)->handle($draft, $t->doctor);

    return app(SignPrescription::class)->handle($draft, $t->doctor, app(OtpSender::class)->sent[$t->doctorUser->id]);
}

function atPharmacy(object $t, PayerType $payer, array $lines, string $id = '850101', string $cell = '0821112222'): array
{
    $patient = registerTestPatient('Sipho', $id, $payer === PayerType::MedicalAid ? 'Discovery Health' : null, $cell);
    $patient->forceFill(['medical_aid_number' => $payer === PayerType::MedicalAid ? '12345678' : null])->save();
    $consult = seenByDoctor($t, $patient, $payer);
    $script = signedScript($t, $consult, $lines);
    app(CompleteConsultation::class)->handle($consult);

    return [$consult->visit->fresh(), $script, $patient];
}

it('receives stock into the append-only S5/S6 register', function (): void {
    app(ReceiveStock::class)->handle(medicineId('Tramadol'), 'TR-01', now()->addYear(), 30, 1200);
    app(ReceiveStock::class)->handle(medicineId('Paracetamol'), 'PA-01', now()->addYear(), 100, 50);

    $entry = RegisterEntry::query()->sole();
    expect($entry->schedule)->toBe('S6')->and($entry->balance_after)->toBe(30)
        ->and(fn () => $entry->forceFill(['quantity' => 1])->save())->toThrow(LogicException::class);
});

it('dispenses earliest expiry first, puts shortfalls on the owing list and bills only what was given', function (): void {
    $med = medicineId('Tramadol');
    app(ReceiveStock::class)->handle($med, 'LATE', now()->addMonths(10), 10, 1000);
    app(ReceiveStock::class)->handle($med, 'SOON', now()->addMonth(), 4, 1000);
    [$visit, $script] = atPharmacy($this, PayerType::Cash, [['Tramadol', 20], ['Paracetamol', 10]]);

    $result = app(DispensePrescription::class)->handle($script, $visit, $this->pharmacist);
    $invoice = Invoice::query()->where('visit_id', $visit->id)->sole();

    expect($result['owing'])->toBe(2)
        ->and(StockItem::query()->where('medicine_id', $med)->sole()->batches()->where('batch_number', 'SOON')->value('quantity'))->toBe(0)
        ->and(StockItem::query()->where('medicine_id', $med)->sole()->batches()->where('batch_number', 'LATE')->value('quantity'))->toBe(0)
        ->and(OwingItem::query()->where('status', 'owing')->pluck('quantity')->sort()->values()->all())->toBe([6, 10])
        ->and($invoice->lines()->where('kind', 'medicine')->sum('total_cents'))->toEqual(14000)
        ->and($visit->fresh()?->stage)->toBe(VisitStage::Dispatch)
        ->and($visit->fresh()?->collection_code)->toHaveLength(4)
        ->and(RegisterEntry::query()->where('movement', 'dispensed')->sole()->quantity)->toBe(-14);

    expect(fn () => app(DispensePrescription::class)->handle($script, $visit->fresh(), $this->pharmacist))->toThrow(ValidationException::class);
});

it('never dispenses a superseded version and lets the pharmacist query the doctor', function (): void {
    app(ReceiveStock::class)->handle(medicineId('Paracetamol'), 'PA-01', now()->addYear(), 100, 50);
    [$visit, $v1] = atPharmacy($this, PayerType::Cash, [['Paracetamol', 10]]);

    app(QueryPrescription::class)->handle($v1, $visit, 'Can we give 20?');
    expect($visit->fresh()?->stage)->toBe(VisitStage::Doctor);

    $v2 = app(AmendPrescription::class)->handle($v1, 'Pharmacist query');
    app(RequestSigningPin::class)->handle($v2, $this->doctor);
    app(SignPrescription::class)->handle($v2, $this->doctor, app(OtpSender::class)->sent[$this->doctorUser->id]);
    app(TransitionVisit::class)->handle($visit->fresh(), VisitStage::Pharmacy);

    expect(fn () => app(DispensePrescription::class)->handle($v1->fresh(), $visit->fresh(), $this->pharmacist))->toThrow(ValidationException::class);
    expect(app(DispensePrescription::class)->handle($v2->fresh(), $visit->fresh(), $this->pharmacist)['owing'])->toBe(0);
});

it('hands over only with the code and only when the balance is paid', function (): void {
    app(ReceiveStock::class)->handle(medicineId('Paracetamol'), 'PA-01', now()->addYear(), 100, 50);
    [$visit, $script] = atPharmacy($this, PayerType::Cash, [['Paracetamol', 10]]);
    $code = app(DispensePrescription::class)->handle($script, $visit, $this->pharmacist)['code'];
    $collect = app(ConfirmCollection::class);
    $wrong = $code === '0000' ? '1111' : '0000';

    expect(fn () => $collect->handle($visit->fresh(), $wrong))->toThrow(ValidationException::class)
        ->and(fn () => $collect->handle($visit->fresh(), $code, 'Thandi Mokoena'))->toThrow(ValidationException::class)
        ->and(fn () => $collect->handle($visit->fresh(), $code))->toThrow(ValidationException::class);

    app(RecordPayment::class)->handle(Invoice::query()->where('visit_id', $visit->id)->sole(), PaymentMethod::Cash, 500);
    $collect->handle($visit->fresh(), $code, 'Thandi Mokoena', '8001015009087');

    expect($visit->fresh()?->stage)->toBe(VisitStage::Done)
        ->and($visit->fresh()?->collected_by_name)->toBe('Thandi Mokoena')
        ->and($visit->fresh()?->collected_at)->not->toBeNull();
});

it('lets a manager, not reception, override the discharge gate with a reason', function (): void {
    $patient = registerTestPatient('Ayesha', '970314');
    $consult = seenByDoctor($this, $patient, PayerType::Cash);
    app(AddInvoiceLine::class)->handle(Invoice::query()->where('visit_id', $consult->visit_id)->sole(), LineKind::Certificate, 'Sick note', 15000);
    app(CompleteConsultation::class)->handle($consult);

    $visit = $consult->visit->fresh();
    expect($visit->stage)->toBe(VisitStage::Doctor)
        ->and(app(CallNextPatient::class)->current($this->doctor))->toBeNull();

    $visitId = $visit->id;
    tenancy()->end();
    $this->actingAs($this->receptionUser)->post("http://sunrise.clinicflow.test/visits/{$visitId}/stage", ['stage' => 'done'])->assertSessionHasErrors('balance');
    $this->actingAs($this->receptionUser)->post("http://sunrise.clinicflow.test/visits/{$visitId}/stage", ['stage' => 'done', 'override_reason' => 'Will pay tomorrow'])->assertForbidden();
    $this->actingAs($this->managerUser)->post("http://sunrise.clinicflow.test/visits/{$visitId}/stage", ['stage' => 'done', 'override_reason' => 'Known patient, EFT tonight'])->assertSessionHasNoErrors();

    $this->clinic->run(fn () => expect(Visit::query()->findOrFail($visitId)->discharge_override_reason)->toBe('Known patient, EFT tonight'));
});

it('supplies and bills owing items once stock arrives', function (): void {
    [$visit, $script] = atPharmacy($this, PayerType::Cash, [['Paracetamol', 10]]);
    app(DispensePrescription::class)->handle($script, $visit, $this->pharmacist);
    $owing = OwingItem::query()->sole();

    expect(fn () => app(FulfilOwing::class)->handle($owing, $this->pharmacist))->toThrow(ValidationException::class);

    app(ReceiveStock::class)->handle(medicineId('Paracetamol'), 'PA-02', now()->addYear(), 50, 50);
    app(FulfilOwing::class)->handle($owing, $this->pharmacist);

    expect($owing->fresh()?->status)->toBe('fulfilled')
        ->and(Invoice::query()->where('visit_id', $visit->id)->sole()->lines()->where('kind', 'medicine')->sum('total_cents'))->toEqual(500);
});

it('returns uncollected medicine to stock after the collection window', function (): void {
    app(ReceiveStock::class)->handle(medicineId('Paracetamol'), 'PA-01', now()->addYear(), 100, 50);
    [$visit, $script] = atPharmacy($this, PayerType::Cash, [['Paracetamol', 10]]);
    app(DispensePrescription::class)->handle($script, $visit, $this->pharmacist);

    $this->travel(8)->days();
    expect(app(ReturnUncollected::class)->handle())->toBe(1)
        ->and(StockItem::query()->sole()->onHand())->toBe(100)
        ->and($visit->fresh()?->stage)->toBe(VisitStage::Done)
        ->and(Invoice::query()->where('visit_id', $visit->id)->sole()->needs_review)->toBeTrue();
});

it('discharges medical aid patients against an accepted claim and applies remittances once', function (): void {
    app(ReceiveStock::class)->handle(medicineId('Paracetamol'), 'PA-01', now()->addYear(), 100, 50);
    [$visit, $script, $patient] = atPharmacy($this, PayerType::MedicalAid, [['Paracetamol', 10]]);
    $code = app(DispensePrescription::class)->handle($script, $visit, $this->pharmacist)['code'];
    $invoice = Invoice::query()->where('visit_id', $visit->id)->sole();

    expect(fn () => app(ConfirmCollection::class)->handle($visit->fresh(), $code))->toThrow(ValidationException::class);
    app(SubmitClaim::class)->handle($invoice->fresh());
    app(ConfirmCollection::class)->handle($visit->fresh(), $code);
    expect($visit->fresh()?->stage)->toBe(VisitStage::Done);

    expect(app(ImportRemittances::class)->handle())->toBe(['applied' => 1, 'shortfalls' => 0])
        ->and(app(ImportRemittances::class)->handle())->toBe(['applied' => 0, 'shortfalls' => 0])
        ->and(Claim::query()->sole()->status)->toBe('paid')
        ->and($invoice->fresh()?->status->value)->toBe('paid');
});

it('turns a scheme shortfall into a patient co-payment', function (): void {
    $patient = registerTestPatient('Lerato', '900101', 'Bonitas', '0829990000');
    $patient->forceFill(['medical_aid_number' => '44449999'])->save();
    $consult = seenByDoctor($this, $patient, PayerType::MedicalAid);
    app(SubmitClaim::class)->handle(Invoice::query()->where('visit_id', $consult->visit_id)->sole());

    expect(app(ImportRemittances::class)->handle()['shortfalls'])->toBe(1)
        ->and(Claim::query()->sole()->status)->toBe('part_paid');
    $invoice = Invoice::query()->where('visit_id', $consult->visit_id)->sole();
    expect($invoice->balanceCents())->toBe(10400)->and($invoice->review_note)->toContain('co-payment');
});

it('adds quoted procedures to the invoice only once accepted, with pre-auth for medical aid', function (): void {
    $patient = registerTestPatient('Bongani', '660101', 'GEMS', '0827770000');
    $patient->forceFill(['medical_aid_number' => '77001122'])->save();
    $consult = seenByDoctor($this, $patient, PayerType::MedicalAid);
    $quote = app(ManageQuote::class)->create($consult->visit, [['code' => '0307', 'description' => 'Suturing, simple', 'quantity' => 1, 'unit_cents' => 85000]]);
    $invoice = Invoice::query()->where('visit_id', $consult->visit_id)->sole();

    expect($quote->total_cents)->toBe(85000)
        ->and($invoice->lines()->where('kind', 'procedure')->count())->toBe(0)
        ->and(fn () => app(ManageQuote::class)->accept($quote))->toThrow(ValidationException::class);

    app(ManageQuote::class)->accept($quote, 'PA-55021');
    expect($invoice->lines()->where('kind', 'procedure')->sum('total_cents'))->toEqual(85000)
        ->and(fn () => app(ManageQuote::class)->decline($quote->fresh()))->toThrow(ValidationException::class);
});

it('ages unpaid claims by days since submission', function (): void {
    $patient = registerTestPatient('Zaid', '010101', 'Discovery Health', '0826660000');
    $patient->forceFill(['medical_aid_number' => '11112222'])->save();
    $consult = seenByDoctor($this, $patient, PayerType::MedicalAid);
    $claim = app(SubmitClaim::class)->handle(Invoice::query()->where('visit_id', $consult->visit_id)->sole());
    $claim->forceFill(['submitted_at' => now()->subDays(45)])->save();

    expect(ClaimAgeing::buckets()['31-60'])->toBe(52000)->and(ClaimAgeing::buckets()['0-30'])->toBe(0);
});
