<?php

use App\Domains\Billing\Actions\RecordPayment;
use App\Domains\Billing\Enums\PaymentMethod;
use App\Domains\Billing\Models\Invoice;
use App\Domains\Clinical\Actions\CompleteConsultation;
use App\Domains\Clinical\Actions\SaveConsultation;
use App\Domains\Clinical\Models\Consultation;
use App\Domains\Identity\Contracts\OtpSender;
use App\Domains\Patients\Actions\RegisterPatient;
use App\Domains\Patients\Enums\Channel;
use App\Domains\Patients\Enums\ConsentGivenBy;
use App\Domains\Patients\Enums\IdType;
use App\Domains\Patients\Models\Patient;
use App\Domains\Patients\Support\RegistrationData;
use App\Domains\Patients\Support\SaIdNumber;
use App\Domains\Platform\Enums\ProviderStatus;
use App\Domains\Platform\Enums\ProviderType;
use App\Domains\Platform\Models\Provider;
use App\Domains\Prescribing\Actions\RequestSigningPin;
use App\Domains\Prescribing\Actions\SaveDraftPrescription;
use App\Domains\Prescribing\Actions\SignPrescription;
use App\Domains\Prescribing\Models\Medicine;
use App\Domains\Prescribing\Models\Prescription;
use App\Domains\Visits\Actions\CheckInPatient;
use App\Domains\Visits\Actions\TransitionVisit;
use App\Domains\Visits\Enums\PayerType;
use App\Domains\Visits\Enums\VisitStage;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/*
 * All feature, unit and architecture tests run on the Laravel TestCase.
 */
pest()->extend(TestCase::class)->in('Feature', 'Unit', 'Arch');

/*
 * Feature tests: make sure the network hub database has its tables before each test. Registering
 * or searching patients also touches the hub, and test files must not depend on an earlier file
 * in the same CI part having created them (the check is cheap and does nothing once they exist).
 */
pest()->beforeEach(function (): void {
    if (config('database.default') === 'mysql') {
        ensureHubTables();
    }
})->in('Feature');

function ensureHubTables(): void
{
    try {
        if (Schema::connection('hub')->hasTable('hub_identities')) {
            return;
        }
    } catch (Throwable) {
        return; // no hub database configured for this run
    }
    Artisan::call('migrate', ['--database' => 'hub', '--path' => 'database/migrations/hub', '--force' => true]);
}

/**
 * Build a valid South African ID number (Luhn check digit computed).
 */
function saId(string $dob = '880412', string $sequence = '0547', string $citizenship = '0'): string
{
    $partial = $dob.$sequence.$citizenship.'8';

    for ($check = 0; $check <= 9; $check++) {
        if (SaIdNumber::luhnValid($partial.$check)) {
            return $partial.$check;
        }
    }

    throw new RuntimeException('Could not build an ID number.');
}

function makeProvider(string $name, ProviderType $type = ProviderType::Clinic, ?string $domain = null): Provider
{
    $provider = Provider::create(['name' => $name, 'type' => $type, 'status' => ProviderStatus::Trial]);

    if ($domain !== null) {
        $provider->domains()->create(['domain' => $domain]);
    }

    return $provider;
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function patientPayload(array $overrides = []): array
{
    return array_merge([
        'first_names' => 'Thandi',
        'surname' => 'Mokoena',
        'id_type' => 'sa_id',
        'id_number' => saId(),
        'cell' => '0825550147',
        'no_cell' => false,
        'preferred_language' => 'zu',
        'preferred_channel' => 'whatsapp',
        'popia_consent' => true,
        'treatment_consent' => true,
        'consent_given_by' => 'patient',
        'maturity_confirmed' => false,
        'medical_aid_scheme' => 'Discovery Health',
        'medical_aid_number' => 'DH412778901',
    ], $overrides);
}

/** Registers a test patient (shared by many test files, so it lives here). */
function registerTestPatient(string $first, string $idDob, ?string $scheme = null, string $cell = '0825550147'): Patient
{
    return app(RegisterPatient::class)->handle(new RegistrationData(
        firstNames: $first, surname: 'Test', idType: IdType::SaId, idNumber: saId($idDob), passportCountry: null, dateOfBirth: null,
        cell: $cell, noCell: false, email: null, preferredLanguage: 'en', preferredChannel: Channel::Sms, address: null,
        guardianName: null, guardianRelationship: null, guardianCell: null, popiaConsent: true, treatmentConsent: true,
        consentGivenBy: ConsentGivenBy::Patient, maturityConfirmed: false, medicalAidScheme: $scheme,
    ));
}

/** Pharmacy / prescribing helpers shared by several test files. */
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
