<?php

use App\Domains\Identity\Actions\AddStaffMember;
use App\Domains\Identity\Enums\StaffRole;
use App\Domains\Identity\Models\AuditEntry;
use App\Domains\Identity\Models\Staff;
use App\Domains\Lab\Models\LabOrder;
use App\Domains\Messaging\Contracts\MessageSender;
use App\Domains\Patients\Actions\CaptureConsent;
use App\Domains\Patients\Actions\RegisterPatient;
use App\Domains\Patients\Enums\Channel;
use App\Domains\Patients\Enums\ConsentGivenBy;
use App\Domains\Patients\Enums\IdType;
use App\Domains\Patients\Models\Patient;
use App\Domains\Patients\Support\RegistrationData;
use App\Domains\Platform\Enums\ProviderStatus;
use App\Domains\Platform\Enums\ProviderType;
use App\Domains\Platform\Models\CmsPage;
use App\Domains\Platform\Models\Provider;
use App\Domains\Scheduling\Actions\BookAppointment;
use App\Domains\Scheduling\Actions\CreateRosterSession;
use App\Domains\Scheduling\Models\Appointment;
use App\Domains\Visits\Actions\CheckInPatient;
use App\Domains\Visits\Enums\PayerType;
use App\Http\Middleware\RequireRecentConfirmation;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

uses(DatabaseMigrations::class);

beforeEach(function (): void {
    if (config('database.default') !== 'mysql') {
        $this->markTestSkipped('Tenancy tests require MySQL.');
    }
    // These tests exercise protected actions as a user who has just confirmed their identity (step-up).
    $this->withSession([RequireRecentConfirmation::SESSION_KEY => now()->getTimestamp()]);

    $this->clinic = makeProvider('Sunrise Medical Centre', ProviderType::Clinic, 'sunrise.clinicflow.test');
    $add = app(AddStaffMember::class);
    foreach (['doctorUser' => StaffRole::Doctor, 'receptionUser' => StaffRole::Receptionist, 'managerUser' => StaffRole::Manager, 'adminUser' => StaffRole::PracticeAdmin] as $key => $role) {
        $this->{$key} = User::factory()->create();
        $add->handle($this->clinic, $this->{$key}, $role);
    }

    tenancy()->initialize($this->clinic);
    $this->mother = registerTestPatient('Thandi', '880412', null, '0825550147');
    $this->child = app(RegisterPatient::class)->handle(new RegistrationData(
        firstNames: 'Lwazi', surname: 'Mokoena', idType: IdType::SaId, idNumber: saId('200315', '5321'), passportCountry: null, dateOfBirth: null,
        cell: null, noCell: true, email: null, preferredLanguage: 'zu', preferredChannel: Channel::Sms, address: null,
        guardianName: 'Thandi Mokoena', guardianRelationship: 'Mother', guardianCell: '0825550147', popiaConsent: true, treatmentConsent: true,
        consentGivenBy: ConsentGivenBy::Guardian, maturityConfirmed: false,
    ));
    $this->stranger = registerTestPatient('Peter', '750101', null, '0829998888');
    $this->doctor = Staff::query()->findOrFail($this->doctorUser->id);
    $this->day = now()->addDays(2)->startOfDay();
    app(CreateRosterSession::class)->handle($this->doctor, $this->day->copy()->setTime(9, 0), $this->day->copy()->setTime(10, 0));
    tenancy()->end();
});

afterEach(function (): void {
    tenancy()->end();
    Provider::query()->get()->each->delete();
});

function portalSignIn(object $t, string $cell = '0825550147'): void
{
    $t->post('http://sunrise.clinicflow.test/my/login', ['cell' => $cell])->assertRedirect();
    $sent = app(MessageSender::class)->sent;
    preg_match('/\b(\d{6})\b/', (string) end($sent)['body'], $m);
    $t->post('http://sunrise.clinicflow.test/my/verify', ['code' => $m[1] ?? '000000'])->assertRedirect('http://sunrise.clinicflow.test/my');
}

it('signs patients in by SMS code without revealing which numbers are registered', function (): void {
    $this->post('http://sunrise.clinicflow.test/my/login', ['cell' => '0820000000'])->assertRedirect();
    expect(app(MessageSender::class)->sent)->toBe([]);
    $this->post('http://sunrise.clinicflow.test/my/verify', ['code' => '123456'])->assertSessionHasErrors('code');

    $this->get('http://sunrise.clinicflow.test/my')->assertRedirect('http://sunrise.clinicflow.test/my/login');
    portalSignIn($this);

    $this->get('http://sunrise.clinicflow.test/my')->assertOk()->assertInertia(fn ($page) => $page->component('Portal/Home')
        ->has('profiles', 2)->where('profiles.0.name', 'Thandi Test')->where('profiles.1.name', 'Lwazi Mokoena'));
    $this->post("http://sunrise.clinicflow.test/my/profiles/{$this->stranger->id}")->assertForbidden();
});

it('lets a guardian book and cancel for a child, but not touch other patients', function (): void {
    portalSignIn($this);
    $this->post("http://sunrise.clinicflow.test/my/profiles/{$this->child->id}");

    $this->post('http://sunrise.clinicflow.test/my/appointments', ['staff_id' => $this->doctor->id, 'starts_at' => $this->day->copy()->setTime(9, 15)->toDateTimeString()])
        ->assertSessionHasNoErrors();

    $this->clinic->run(function (): void {
        $mine = Appointment::query()->sole();
        expect($mine->patient_id)->toBe($this->child->id);
        $this->strangerAppointment = app(BookAppointment::class)->handle($this->stranger, $this->doctor, $this->day->copy()->setTime(9, 30));
        $this->mine = $mine;
    });

    $this->post("http://sunrise.clinicflow.test/my/appointments/{$this->strangerAppointment->id}/cancel")->assertForbidden();
    $this->post("http://sunrise.clinicflow.test/my/appointments/{$this->mine->id}/cancel")->assertSessionHasNoErrors();
});

it('shows only released results in the portal', function (): void {
    $this->clinic->run(function (): void {
        $visit = app(CheckInPatient::class)->handle($this->mother, PayerType::Cash);
        LabOrder::create(['visit_id' => $visit->id, 'patient_id' => $this->mother->id, 'ordering_staff_id' => $this->doctor->id, 'status' => 'verified']);
        LabOrder::create(['visit_id' => $visit->id, 'patient_id' => $this->mother->id, 'ordering_staff_id' => $this->doctor->id, 'status' => 'released', 'released_at' => now()]);
    });

    portalSignIn($this);
    $this->get('http://sunrise.clinicflow.test/my')->assertInertia(fn ($page) => $page->has('results', 1)->where('visit.ticket', 'A001'));
});

it('imports legacy patients, skips bad rows with reasons and requires consent at check-in', function (): void {
    $csv = "first_names,surname,id_number,date_of_birth,cell,email,medical_aid_scheme,medical_aid_number\n"
        .'Grace,Mabena,'.saId('760505', '0123').",,+27821234567,grace@example.test,GEMS,9001\n"
        ."Bad,Check,8804120547089,,,,,\n"
        .",Nameless,,1990-01-01,,,,\n"
        .'Thandi,Test,'.saId('880412').",,,,,\n"
        ."Kabelo,Dube,,1995-07-14,0835550000,,,\n";
    $file = UploadedFile::fake()->createWithContent('legacy.csv', $csv);

    $this->actingAs($this->receptionUser)->post('http://sunrise.clinicflow.test/patients/import', ['file' => $file])->assertForbidden();
    $this->actingAs($this->adminUser)->post('http://sunrise.clinicflow.test/patients/import', ['file' => $file])->assertSessionHas('success', '2 of 5 patients imported; 3 skipped.');

    $this->clinic->run(function (): void {
        $grace = Patient::query()->where('first_names', 'Grace')->sole();
        expect($grace->cell)->toBe('0821234567')->and($grace->needs_consent)->toBeTrue()
            ->and(fn () => app(CheckInPatient::class)->handle($grace))->toThrow(ValidationException::class);

        app(CaptureConsent::class)->handle($grace, ConsentGivenBy::Patient, true, true);
        expect(app(CheckInPatient::class)->handle($grace)->ticket)->toBe('A001');
    });
});

it('gives managers a searchable audit log, CSV export and POPIA patient export', function (): void {
    $this->actingAs($this->receptionUser)->get('http://sunrise.clinicflow.test/compliance/audit')->assertForbidden();
    $this->actingAs($this->managerUser)->get('http://sunrise.clinicflow.test/compliance/audit?search=registered')
        ->assertOk()->assertInertia(fn ($page) => $page->component('Compliance/Audit')->where('entries.data.0.description', 'Patient registered'));
    $this->actingAs($this->managerUser)->get('http://sunrise.clinicflow.test/compliance/audit/export')->assertOk()->assertHeader('content-type', 'text/csv; charset=UTF-8');

    $this->actingAs($this->managerUser)->get("http://sunrise.clinicflow.test/compliance/patients/{$this->mother->id}/export")
        ->assertOk()->assertJsonPath('patient.first_names', 'Thandi')->assertJsonPath('access_log.0.description', 'Patient registered');

    $this->clinic->run(fn () => expect(AuditEntry::query()->where('description', 'Patient data exported (POPIA request)')->exists())->toBeTrue());
});

it('publishes sanitised website pages and lists only verified providers', function (): void {
    makeProvider('Pending Clinic', ProviderType::Clinic)->forceFill(['status' => ProviderStatus::PendingVerification])->save();
    $admin = User::factory()->create();
    $admin->forceFill(['is_platform_admin' => true])->save();

    $this->actingAs($admin)->post('http://localhost/admin/pages', ['slug' => 'login', 'title' => 'x', 'body' => 'x'])->assertStatus(422);
    $this->actingAs($admin)->post('http://localhost/admin/pages', [
        'slug' => 'home', 'title' => 'Clinic Flow', 'body' => '<h1>Care, connected</h1><script>alert(1)</script><p onclick="x()">Hi</p>', 'published' => true,
    ])->assertSessionHasNoErrors();

    expect(CmsPage::query()->sole()->body)->not->toContain('<script')->not->toContain('onclick');
    $this->get('http://localhost/')->assertInertia(fn ($page) => $page->component('Public/Page')->where('title', 'Clinic Flow'));
    $this->get('http://localhost/pages/missing')->assertNotFound();

    $this->get('http://localhost/find-care')->assertInertia(fn ($page) => $page->component('Public/Directory')
        ->has('providers', 1)->where('providers.0.name', 'Sunrise Medical Centre'));
});
