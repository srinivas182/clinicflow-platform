<?php

use App\Domains\Api\Actions\ApiKeys;
use App\Domains\Api\Fhir\FhirConsents;
use App\Domains\Identity\Actions\AddStaffMember;
use App\Domains\Identity\Enums\StaffRole;
use App\Domains\Patients\Models\Patient;
use App\Domains\Platform\Enums\ProviderType;
use App\Domains\Platform\Enums\SubscriptionStatus;
use App\Domains\Platform\Models\Package;
use App\Domains\Platform\Models\Provider;
use App\Domains\Platform\Models\Subscription;
use App\Domains\Visits\Actions\CheckInPatient;
use App\Domains\Visits\Enums\PayerType;
use App\Http\Middleware\RequireRecentConfirmation;
use App\Models\User;
use Database\Seeders\PackageSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(DatabaseMigrations::class);

beforeEach(function (): void {
    if (config('database.default') !== 'mysql') {
        $this->markTestSkipped('Tenancy tests require MySQL.');
    }
    // These tests exercise protected actions as a user who has just confirmed their identity (step-up).
    $this->withSession([RequireRecentConfirmation::SESSION_KEY => now()->getTimestamp()]);
    $this->seed(PackageSeeder::class);
    $this->clinic = makeProvider('Sunrise Medical Centre', ProviderType::Clinic, 'sunrise.clinicflow.test');
    Subscription::create(['tenant_id' => $this->clinic->id, 'package_id' => Package::query()->where('code', 'clinic-pro')->value('id'), 'status' => SubscriptionStatus::Active, 'current_period_ends_at' => now()->addMonth()]);
    $this->ownerUser = User::factory()->create();
    $this->doctorUser = User::factory()->create(['name' => 'Dr Mokoena']);
    app(AddStaffMember::class)->handle($this->clinic, $this->ownerUser, StaffRole::Owner);
    app(AddStaffMember::class)->handle($this->clinic, $this->doctorUser, StaffRole::Doctor);
    $this->fhir = 'http://sunrise.clinicflow.test/api/fhir/r4';

    [$this->key, $this->keyId, $this->patientId, $this->otherId] = $this->clinic->run(function (): array {
        $created = app(ApiKeys::class)->create('Hospital EHR', ['fhir:read'], [], null, 1);
        $patient = registerTestPatient('Thandi', '880412', null, '0825550147');
        $other = registerTestPatient('Sipho', '850101', null, '0821112222');
        DB::table('allergies')->insert(['patient_id' => $patient->id, 'substance' => 'Penicillin', 'reaction' => 'Rash', 'status' => 'active', 'recorded_by' => 1, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('problems')->insert(['patient_id' => $patient->id, 'icd10_code' => 'E11.9', 'description' => 'Type 2 diabetes', 'chronic' => true, 'status' => 'active', 'onset_date' => '2020-01-01', 'added_by' => 1, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('immunisations')->insert(['patient_id' => $patient->id, 'vaccine' => 'Influenza', 'dose' => '1', 'given_on' => '2026-04-01', 'batch' => 'FLU26', 'given_by' => 1, 'created_at' => now(), 'updated_at' => now()]);

        return [$created['key'], $created['id'], $patient->id, $other->id];
    });
    $this->auth = ['Authorization' => "Bearer {$this->key}"];
});

afterEach(function (): void {
    tenancy()->end();
    Provider::query()->get()->each->delete();
});

it('shows a connected system nothing until the patient consents, then only the consented parts', function (): void {
    $this->get("{$this->fhir}/metadata")->assertOk()->assertJsonPath('resourceType', 'CapabilityStatement');
    $this->getJson("{$this->fhir}/Patient/{$this->patientId}", $this->auth)->assertNotFound()->assertJsonPath('resourceType', 'OperationOutcome');
    $this->getJson("{$this->fhir}/Condition?patient={$this->patientId}", $this->auth)->assertForbidden();
    $this->getJson("{$this->fhir}/Patient", $this->auth)->assertOk()->assertJsonPath('total', 0);

    $this->actingAs($this->doctorUser)->post("http://sunrise.clinicflow.test/patients/{$this->patientId}/connected", ['key_id' => $this->keyId, 'categories' => ['problems', 'allergies']])->assertStatus(422);
    $this->actingAs($this->doctorUser)->post("http://sunrise.clinicflow.test/patients/{$this->patientId}/connected", ['key_id' => $this->keyId, 'categories' => ['problems', 'allergies'], 'confirmed' => true])->assertSessionHasNoErrors();

    $this->getJson("{$this->fhir}/Patient", $this->auth)->assertOk()->assertJsonPath('total', 1)->assertJsonPath('entry.0.resource.id', $this->patientId);
    $this->getJson("{$this->fhir}/Patient/{$this->otherId}", $this->auth)->assertNotFound();
    $this->getJson("{$this->fhir}/Condition?patient=Patient/{$this->patientId}", $this->auth)->assertOk()
        ->assertHeader('Content-Type', 'application/fhir+json')->assertJsonPath('entry.0.resource.code.coding.0.code', 'E11.9');
    $this->getJson("{$this->fhir}/AllergyIntolerance?patient={$this->patientId}", $this->auth)->assertOk()->assertJsonPath('entry.0.resource.code.text', 'Penicillin');
    $this->getJson("{$this->fhir}/Immunization?patient={$this->patientId}", $this->auth)->assertForbidden();
    $this->getJson("{$this->fhir}/Condition", $this->auth)->assertStatus(400);

    $this->clinic->run(fn () => expect(DB::table('patient_access_log')->where('patient_id', $this->patientId)->where('summary', 'like', '%Hospital EHR%read your problem list%')->exists())->toBeTrue());

    $this->actingAs($this->doctorUser)->post("http://sunrise.clinicflow.test/patients/{$this->patientId}/connected", ['key_id' => $this->keyId, 'categories' => []])->assertSessionHasNoErrors();
    $this->getJson("{$this->fhir}/Condition?patient={$this->patientId}", $this->auth)->assertForbidden();
});

it('shares only released lab results, and stops at the consent expiry', function (): void {
    $this->clinic->run(function (): void {
        $visit = app(CheckInPatient::class)->handle(Patient::query()->findOrFail($this->patientId), PayerType::Cash);
        foreach ([['HbA1c', '4548-4', 7.9, now()], ['Creatinine', '2160-0', 88, null]] as [$name, $code, $value, $released]) {
            $order = strtolower((string) Str::ulid());
            DB::table('lab_orders')->insert(['id' => $order, 'visit_id' => $visit->id, 'patient_id' => $this->patientId, 'ordering_staff_id' => $this->doctorUser->id, 'status' => 'verified',
                'verified_at' => now(), 'released_at' => $released, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('lab_results')->insert(['lab_order_id' => $order, 'test_code' => $code, 'name' => $name, 'unit' => '%', 'reference' => '4.0-6.0', 'value' => $value, 'flag' => 'high']);
        }
        app(FhirConsents::class)->grant($this->patientId, $this->keyId, ['results'], 'portal', null, now()->addDay()->toDateTimeString());
    });

    $this->getJson("{$this->fhir}/Observation?patient={$this->patientId}", $this->auth)->assertOk()->assertJsonPath('total', 1)
        ->assertJsonPath('entry.0.resource.code.coding.0.system', 'http://loinc.org')->assertJsonPath('entry.0.resource.interpretation.0.coding.0.code', 'H');
    $this->travel(2)->days();
    $this->getJson("{$this->fhir}/Observation?patient={$this->patientId}", $this->auth)->assertForbidden();
});

it('lets patients choose sharing in their portal, only for their own records', function (): void {
    $this->withSession(['portal_cell' => '0825550147'])->post('http://sunrise.clinicflow.test/my/care/connected', ['patient_id' => $this->patientId, 'key_id' => $this->keyId, 'categories' => ['immunisations']])->assertSessionHasNoErrors();
    $this->withSession(['portal_cell' => '0825550147'])->post('http://sunrise.clinicflow.test/my/care/connected', ['patient_id' => $this->otherId, 'key_id' => $this->keyId, 'categories' => ['immunisations']])->assertForbidden();
    $this->getJson("{$this->fhir}/Immunization?patient={$this->patientId}", $this->auth)->assertOk()->assertJsonPath('entry.0.resource.vaccineCode.text', 'Influenza');
    $this->withSession(['portal_cell' => '0825550147'])->get('http://sunrise.clinicflow.test/my/care')
        ->assertInertia(fn ($p) => $p->where('connected.0.name', 'Hospital EHR')->where("connected.0.patients.{$this->patientId}", ['immunisations']));
});

it('lets only the practice owner create a key with clinical-record access', function (): void {
    $admin = User::factory()->create();
    app(AddStaffMember::class)->handle($this->clinic, $admin, StaffRole::PracticeAdmin);
    $this->actingAs($admin)->post('http://sunrise.clinicflow.test/settings/api/keys', ['name' => 'EHR', 'scopes' => ['fhir:read']])->assertForbidden();
    $this->actingAs($admin)->post('http://sunrise.clinicflow.test/settings/api/keys', ['name' => 'Website', 'scopes' => ['prices:read']])->assertSessionHas('new_api_key');
    $this->actingAs($this->ownerUser)->post('http://sunrise.clinicflow.test/settings/api/keys', ['name' => 'EHR', 'scopes' => ['fhir:read']])->assertSessionHas('new_api_key');
});
