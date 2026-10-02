<?php

use App\Domains\Identity\Actions\AddStaffMember;
use App\Domains\Identity\Enums\StaffRole;
use App\Domains\Identity\Models\Staff;
use App\Domains\Patients\Actions\RegisterPatient;
use App\Domains\Patients\Enums\Channel;
use App\Domains\Patients\Enums\ConsentGivenBy;
use App\Domains\Patients\Enums\IdType;
use App\Domains\Patients\Support\RegistrationData;
use App\Domains\Platform\Enums\ProviderStatus;
use App\Domains\Platform\Enums\ProviderType;
use App\Domains\Platform\Models\Provider;
use App\Domains\Scheduling\Actions\AvailableSlots;
use App\Domains\Scheduling\Actions\BookAppointment;
use App\Domains\Scheduling\Actions\CancelAppointment;
use App\Domains\Scheduling\Actions\CreateRosterSession;
use App\Domains\Scheduling\Enums\ConsultType;
use App\Domains\Scheduling\Models\Appointment;
use App\Domains\Scheduling\Models\Room;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Validation\ValidationException;

uses(DatabaseMigrations::class);

beforeEach(function (): void {
    if (config('database.default') !== 'mysql') {
        $this->markTestSkipped('Tenancy tests require MySQL.');
    }

    $this->clinic = makeProvider('Sunrise Medical Centre', ProviderType::Clinic, 'sunrise.clinicflow.test');
    $this->receptionist = User::factory()->create(['name' => 'Nomvula Sithole']);
    $this->doctorUser = User::factory()->create(['name' => 'Dr Kagiso Mokoena']);
    $this->secondDoctorUser = User::factory()->create(['name' => 'Dr Lindiwe Mahlangu']);
    $add = app(AddStaffMember::class);
    $add->handle($this->clinic, $this->receptionist, StaffRole::Receptionist);
    $add->handle($this->clinic, $this->doctorUser, StaffRole::Doctor);
    $add->handle($this->clinic, $this->secondDoctorUser, StaffRole::Doctor);

    $this->day = CarbonImmutable::now()->addDays(2)->startOfDay();
    tenancy()->initialize($this->clinic);
    $this->room = Room::create(['name' => 'Room 4']);
    $this->doctor = Staff::query()->findOrFail($this->doctorUser->id);
    $this->secondDoctor = Staff::query()->findOrFail($this->secondDoctorUser->id);
    app(CreateRosterSession::class)->handle($this->doctor, $this->day->setTime(8, 0), $this->day->setTime(9, 0), $this->room);
    app(CreateRosterSession::class)->handle($this->secondDoctor, $this->day->setTime(8, 0), $this->day->setTime(9, 0));
    $this->patient = app(RegisterPatient::class)->handle(new RegistrationData(
        firstNames: 'Thandi', surname: 'Mokoena', idType: IdType::SaId, idNumber: saId(), passportCountry: null, dateOfBirth: null,
        cell: '0825550147', noCell: false, email: null, preferredLanguage: 'zu', preferredChannel: Channel::WhatsApp, address: null,
        guardianName: null, guardianRelationship: null, guardianCell: null, popiaConsent: true, treatmentConsent: true,
        consentGivenBy: ConsentGivenBy::Patient, maturityConfirmed: false,
    ));
});

afterEach(function (): void {
    tenancy()->end();
    Provider::query()->get()->each->delete();
});

it('refuses overlapping sessions for one person or one room', function (): void {
    $roster = app(CreateRosterSession::class);

    expect(fn () => $roster->handle($this->doctor, $this->day->setTime(8, 30), $this->day->setTime(10, 0)))->toThrow(ValidationException::class)
        ->and(fn () => $roster->handle($this->secondDoctor, $this->day->setTime(10, 0), $this->day->setTime(9, 0)))->toThrow(ValidationException::class);

    $nurse = Staff::query()->create(['id' => 999, 'name' => 'Sister Dlamini', 'email' => 'n@x.test', 'role' => 'nurse']);
    expect(fn () => $roster->handle($nurse, $this->day->setTime(8, 30), $this->day->setTime(9, 30), $this->room))->toThrow(ValidationException::class);
});

it('lists free slots and removes them once booked', function (): void {
    $slots = app(AvailableSlots::class);
    expect(array_map(fn ($s) => $s['starts_at']->format('H:i'), $slots->handle($this->doctor->id, $this->day)))->toBe(['08:00', '08:15', '08:30', '08:45']);

    app(BookAppointment::class)->handle($this->patient, $this->doctor, $this->day->setTime(8, 15));

    expect(array_map(fn ($s) => $s['starts_at']->format('H:i'), $slots->handle($this->doctor->id, $this->day)))->toBe(['08:00', '08:30', '08:45']);
});

it('only books real, free slots with a doctor', function (string $case, string $field): void {
    $book = app(BookAppointment::class);
    $attempt = match ($case) {
        'outside' => fn () => $book->handle($this->patient, $this->doctor, $this->day->setTime(10, 0)),
        'between' => fn () => $book->handle($this->patient, $this->doctor, $this->day->setTime(8, 10)),
        'past' => fn () => $book->handle($this->patient, $this->doctor, CarbonImmutable::now()->subDay()),
        'video' => fn () => $book->handle($this->patient, $this->doctor, $this->day->setTime(8, 0), ConsultType::Video),
        'non-doctor' => fn () => $book->handle($this->patient, Staff::query()->findOrFail($this->receptionist->id), $this->day->setTime(8, 0)),
    };

    try {
        $attempt();
        $this->fail('Expected a validation error.');
    } catch (ValidationException $e) {
        expect($e->errors())->toHaveKey($field);
    }
})->with([
    'outside the session' => ['outside', 'starts_at'],
    'between slots' => ['between', 'starts_at'],
    'in the past' => ['past', 'starts_at'],
    'video before telemedicine' => ['video', 'consult_type'],
    'with a non-doctor' => ['non-doctor', 'staff_id'],
]);

it('prevents double booking the doctor or the patient', function (): void {
    $book = app(BookAppointment::class);
    $book->handle($this->patient, $this->doctor, $this->day->setTime(8, 0));

    expect(fn () => $book->handle($this->patient, $this->doctor, $this->day->setTime(8, 0)))->toThrow(ValidationException::class)
        ->and(fn () => $book->handle($this->patient, $this->secondDoctor, $this->day->setTime(8, 0)))->toThrow(ValidationException::class);
});

it('needs a reason to cancel and frees the slot', function (): void {
    $appointment = app(BookAppointment::class)->handle($this->patient, $this->doctor, $this->day->setTime(8, 0));

    expect(fn () => app(CancelAppointment::class)->handle($appointment, ' '))->toThrow(ValidationException::class);

    app(CancelAppointment::class)->handle($appointment, 'Patient feels better');
    expect(Appointment::query()->findOrFail($appointment->id)->cancelled_reason)->toBe('Patient feels better')
        ->and(app(AvailableSlots::class)->handle($this->doctor->id, $this->day))->toHaveCount(4);
});

it('lets reception book over HTTP but not a pharmacist', function (): void {
    $patientId = $this->patient->id;
    tenancy()->end();
    $payload = ['patient_id' => $patientId, 'staff_id' => $this->doctorUser->id, 'starts_at' => $this->day->setTime(8, 30)->toDateTimeString(), 'consult_type' => 'in_person'];

    $this->actingAs($this->receptionist)->post('http://sunrise.clinicflow.test/appointments', $payload)->assertSessionHasNoErrors();

    $pharmacist = User::factory()->create();
    app(AddStaffMember::class)->handle($this->clinic, $pharmacist, StaffRole::Pharmacist);
    $this->actingAs($pharmacist)->post('http://sunrise.clinicflow.test/appointments', $payload)->assertForbidden();

    $this->clinic->run(fn () => expect(Appointment::query()->count())->toBe(1));
});

it('blocks changes when the provider is read-only', function (): void {
    tenancy()->end();
    $this->clinic->status = ProviderStatus::ReadOnly;
    $this->clinic->save();

    $this->actingAs($this->receptionist)
        ->post('http://sunrise.clinicflow.test/patients', patientPayload(['id_number' => saId('900101')]))
        ->assertStatus(423);
    $this->actingAs($this->receptionist)->get('http://sunrise.clinicflow.test/appointments')->assertOk();
});

it('shows the day view with free slots', function (): void {
    tenancy()->end();

    $this->actingAs($this->receptionist)
        ->get('http://sunrise.clinicflow.test/appointments?date='.$this->day->toDateString())
        ->assertInertia(fn ($page) => $page->component('Appointments/Index')
            ->has('doctors', 2)
            ->where('canBook', true)
            ->where('doctors.0.freeSlots', ['08:00', '08:15', '08:30', '08:45']));
});
