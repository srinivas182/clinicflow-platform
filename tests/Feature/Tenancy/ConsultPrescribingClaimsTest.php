<?php

use App\Domains\Billing\Models\Invoice;
use App\Domains\Claims\Actions\CheckEligibility;
use App\Domains\Claims\Actions\SubmitClaim;
use App\Domains\Clinical\Actions\CompleteConsultation;
use App\Domains\Clinical\Actions\ManageAllergies;
use App\Domains\Clinical\Actions\SaveConsultation;
use App\Domains\Clinical\Models\Consultation;
use App\Domains\Documents\Models\IssuedDocument;
use App\Domains\Identity\Actions\AddStaffMember;
use App\Domains\Identity\Contracts\OtpSender;
use App\Domains\Identity\Enums\StaffRole;
use App\Domains\Identity\Models\Staff;
use App\Domains\Platform\Enums\ProviderType;
use App\Domains\Platform\Models\Provider;
use App\Domains\Prescribing\Actions\AmendPrescription;
use App\Domains\Prescribing\Actions\CheckPrescriptionSafety;
use App\Domains\Prescribing\Actions\RequestSigningPin;
use App\Domains\Prescribing\Actions\SaveDraftPrescription;
use App\Domains\Prescribing\Actions\SignPrescription;
use App\Domains\Prescribing\Models\Medicine;
use App\Domains\Prescribing\Models\Prescription;
use App\Domains\Visits\Actions\CheckInPatient;
use App\Domains\Visits\Actions\TransitionVisit;
use App\Domains\Visits\Enums\PayerType;
use App\Domains\Visits\Enums\VisitStage;
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
    foreach (['doctorUser' => StaffRole::Doctor, 'otherDoctorUser' => StaffRole::Doctor, 'nurseUser' => StaffRole::Nurse, 'receptionUser' => StaffRole::Receptionist, 'clerkUser' => StaffRole::BillingClerk] as $key => $role) {
        $this->{$key} = User::factory()->create();
        $add->handle($this->clinic, $this->{$key}, $role);
    }

    tenancy()->initialize($this->clinic);
    $this->doctor = Staff::query()->findOrFail($this->doctorUser->id);
    $this->doctor->forceFill(['professional_number' => 'MP0654321'])->save();
    $this->patient = registerTestPatient('Sipho', '850101', 'Discovery Health', '0821112222');
    $this->patient->forceFill(['medical_aid_number' => '12345678'])->save();
    app(ManageAllergies::class)->add($this->patient, 'Penicillin', 'Rash');

    $this->visit = app(CheckInPatient::class)->handle($this->patient, PayerType::MedicalAid);
    app(TransitionVisit::class)->handle($this->visit, VisitStage::Triage);
    app(TransitionVisit::class)->handle($this->visit, VisitStage::Doctor);
    $this->visit->forceFill(['doctor_id' => $this->doctor->id, 'called_at' => now()])->save();
    $this->consult = Consultation::create(['visit_id' => $this->visit->id, 'patient_id' => $this->patient->id, 'doctor_staff_id' => $this->doctor->id]);
});

afterEach(function (): void {
    tenancy()->end();
    Provider::query()->get()->each->delete();
});

function med(string $name): int
{
    return (int) Medicine::query()->where('name', $name)->value('id');
}

/**
 * @param  list<array{0: string, 1: int, 2?: ?string}>  $lines  [name, repeats, override reason]
 */
function draftScript(object $t, array $lines): Prescription
{
    return app(SaveDraftPrescription::class)->handle($t->consult, $t->doctor, array_map(fn (array $l) => [
        'medicine_id' => med($l[0]), 'dose' => 'As directed', 'quantity' => 10, 'repeats' => $l[1], 'override_reason' => $l[2] ?? null,
    ], $lines));
}

function issueTypes(Prescription $p): array
{
    return array_map(fn ($i) => $i->type.':'.$i->level, app(CheckPrescriptionSafety::class)->handle($p));
}

function signNow(object $t, Prescription $p): Prescription
{
    app(RequestSigningPin::class)->handle($p, $t->doctor);

    return app(SignPrescription::class)->handle($p, $t->doctor, app(OtpSender::class)->sent[$t->doctorUser->id]);
}

it('opens the consult only for the doctor who called the patient', function (): void {
    tenancy()->end();
    $this->actingAs($this->doctorUser)->get("http://sunrise.clinicflow.test/consults/{$this->visit->id}")
        ->assertOk()->assertInertia(fn ($page) => $page->component('Consult/Show')->where('patient.allergies.0', 'Penicillin'));
    $this->actingAs($this->otherDoctorUser)->get("http://sunrise.clinicflow.test/consults/{$this->visit->id}")->assertForbidden();
    $this->actingAs($this->nurseUser)->get("http://sunrise.clinicflow.test/consults/{$this->visit->id}")->assertForbidden();
});

it('saves notes with ICD-10 codes and refuses a stale save', function (): void {
    $save = app(SaveConsultation::class);
    $save->handle($this->consult, ['subjective' => 'Cough 5 days'], [['code' => 'j20.9', 'primary' => true], ['code' => 'R50.9', 'primary' => false]], 1);

    expect($this->consult->fresh()?->lock_version)->toBe(2)
        ->and($this->consult->diagnoses()->where('is_primary', true)->value('icd10_code'))->toBe('J20.9')
        ->and(fn () => $save->handle($this->consult, ['subjective' => 'Overwrite'], [], 1))->toThrow(ValidationException::class)
        ->and(fn () => $save->handle($this->consult, [], [['code' => 'XX99', 'primary' => true]], 2))->toThrow(ValidationException::class)
        ->and(fn () => $save->handle($this->consult, [], [['code' => 'J20.9', 'primary' => true], ['code' => 'R51', 'primary' => true]], 2))->toThrow(ValidationException::class)
        ->and(fn () => $save->handle($this->consult, [], [['code' => 'Z76.0', 'primary' => true]], 2))->toThrow(ValidationException::class);
});

it('blocks allergies and schedule repeat limits, and needs reasons for interactions and duplicates', function (): void {
    expect(issueTypes(draftScript($this, [['Amoxicillin', 0]])))->toContain('allergy:block');
    expect(issueTypes(draftScript($this, [['Tramadol', 1]])))->toContain('schedule:block');
    expect(issueTypes(draftScript($this, [['Amlodipine', 6]])))->toContain('schedule:block');
    expect(issueTypes(draftScript($this, [['Amlodipine', 5]])))->toBe([]);

    $p = draftScript($this, [['Warfarin', 0], ['Ibuprofen', 0]]);
    expect(issueTypes($p))->toContain('interaction:override')
        ->and(app(CheckPrescriptionSafety::class)->canSign($p, app(CheckPrescriptionSafety::class)->handle($p)))->toBeFalse();

    $p = draftScript($this, [['Warfarin', 0], ['Ibuprofen', 0, 'Short course, INR checked Friday']]);
    expect(app(CheckPrescriptionSafety::class)->canSign($p, app(CheckPrescriptionSafety::class)->handle($p)))->toBeTrue();

    expect(issueTypes(draftScript($this, [['Paracetamol', 0], ['Paracetamol', 0]])))->toContain('duplicate:override');
});

it('signs only with the correct PIN, freezes the script and issues the PDF', function (): void {
    $unsafe = draftScript($this, [['Amoxicillin', 0]]);
    expect(fn () => app(RequestSigningPin::class)->handle($unsafe, $this->doctor))->toThrow(ValidationException::class);

    $p = draftScript($this, [['Azithromycin', 0], ['Paracetamol', 0]]);
    app(RequestSigningPin::class)->handle($p, $this->doctor);
    expect(fn () => app(SignPrescription::class)->handle($p, $this->doctor, '000000'))->toThrow(ValidationException::class);

    $signed = app(SignPrescription::class)->handle($p, $this->doctor, app(OtpSender::class)->sent[$this->doctorUser->id]);

    expect($signed->status)->toBe('signed')
        ->and($signed->signature_hash)->toHaveLength(64)
        ->and(IssuedDocument::query()->findOrFail($signed->issued_document_id)->type)->toBe('prescription')
        ->and(fn () => $signed->forceFill(['change_reason' => 'edit'])->save())->toThrow(LogicException::class);
});

it('lets only the prescribing doctor sign', function (): void {
    $p = draftScript($this, [['Paracetamol', 0]]);
    $other = Staff::query()->findOrFail($this->otherDoctorUser->id);
    expect(fn () => app(RequestSigningPin::class)->handle($p, $other))->toThrow(ValidationException::class);

    tenancy()->end();
    $this->actingAs($this->nurseUser)->post("http://sunrise.clinicflow.test/prescriptions/{$p->id}/pin")->assertForbidden();
});

it('creates a new version for changes and only the newest signed version is dispensable', function (): void {
    $v1 = signNow($this, draftScript($this, [['Azithromycin', 0]]));
    expect(fn () => app(AmendPrescription::class)->handle($v1, ''))->toThrow(ValidationException::class);

    $v2 = app(AmendPrescription::class)->handle($v1, 'Pharmacist query: out of stock');
    expect($v2->version)->toBe(2)->and($v2->items()->count())->toBe(1)->and($v1->fresh()?->isDispensable())->toBeTrue();

    $v2 = app(SaveDraftPrescription::class)->handle($this->consult, $this->doctor, [['medicine_id' => med('Amoxicillin'), 'dose' => '1 three times daily', 'quantity' => 15, 'repeats' => 0]]);
    expect(issueTypes($v2))->toContain('allergy:block');

    $v2 = app(SaveDraftPrescription::class)->handle($this->consult, $this->doctor, [['medicine_id' => med('Nitrofurantoin'), 'dose' => '1 twice daily', 'quantity' => 10, 'repeats' => 0]]);
    signNow($this, $v2);

    expect($v1->fresh()?->status)->toBe('superseded')
        ->and($v1->fresh()?->isDispensable())->toBeFalse()
        ->and($v2->fresh()?->isDispensable())->toBeTrue();
});

it('completes the consult only with a primary diagnosis and routes to pharmacy when a script was signed', function (): void {
    expect(fn () => app(CompleteConsultation::class)->handle($this->consult))->toThrow(ValidationException::class);

    app(SaveConsultation::class)->handle($this->consult, ['assessment' => 'Bronchitis'], [['code' => 'J20.9', 'primary' => true]], 1);
    draftScript($this, [['Azithromycin', 0]]);
    expect(fn () => app(CompleteConsultation::class)->handle($this->consult->fresh()))->toThrow(ValidationException::class);

    signNow($this, Prescription::query()->where('status', 'draft')->firstOrFail());
    app(CompleteConsultation::class)->handle($this->consult->fresh());

    expect($this->visit->fresh()?->stage)->toBe(VisitStage::Pharmacy)
        ->and($this->consult->fresh()?->status)->toBe('completed');
});

it('checks medical aid eligibility at the front desk', function (): void {
    expect(app(CheckEligibility::class)->handle($this->patient, $this->visit)->status)->toBe('active');

    $this->patient->forceFill(['medical_aid_number' => '55550000'])->save();
    expect(app(CheckEligibility::class)->handle($this->patient)->status)->toBe('inactive');

    $patientId = $this->patient->id;
    tenancy()->end();
    $this->actingAs($this->receptionUser)->post("http://sunrise.clinicflow.test/patients/{$patientId}/eligibility")->assertSessionHas('success');
    $this->actingAs($this->nurseUser)->post("http://sunrise.clinicflow.test/patients/{$patientId}/eligibility")->assertForbidden();
});

it('builds claims from the invoice and diagnoses, and resubmits after correction', function (): void {
    $invoice = Invoice::query()->where('visit_id', $this->visit->id)->sole();
    expect(fn () => app(SubmitClaim::class)->handle($invoice))->toThrow(ValidationException::class);

    app(SaveConsultation::class)->handle($this->consult, [], [['code' => 'R50.9', 'primary' => false], ['code' => 'J20.9', 'primary' => true]], 1);

    $this->patient->forceFill(['medical_aid_number' => '55550000'])->save();
    $claim = app(SubmitClaim::class)->handle($invoice->fresh());
    expect($claim->status)->toBe('rejected')->and($claim->rejection_reason)->toContain('not active');

    $this->patient->forceFill(['medical_aid_number' => '12345678'])->save();
    $claim = app(SubmitClaim::class)->handle($invoice->fresh());
    $line = $claim->lines()->firstOrFail();

    expect($claim->status)->toBe('accepted')
        ->and($claim->submissions)->toBe(2)
        ->and($claim->switch_reference)->toStartWith('DEMO-')
        ->and($line->tariff_code)->toBe('0190')
        ->and($line->icd10_codes)->toBe(['J20.9', 'R50.9'])
        ->and(fn () => app(SubmitClaim::class)->handle($invoice->fresh()))->toThrow(ValidationException::class);

    tenancy()->end();
    $this->actingAs($this->clerkUser)->get('http://sunrise.clinicflow.test/claims')->assertOk()->assertInertia(fn ($page) => $page->where('claims.data.0.status', 'accepted'));
    $this->actingAs($this->nurseUser)->get('http://sunrise.clinicflow.test/claims')->assertForbidden();
});
