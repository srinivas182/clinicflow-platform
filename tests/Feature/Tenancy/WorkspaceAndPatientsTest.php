<?php

use App\Domains\Identity\Actions\AddStaffMember;
use App\Domains\Identity\Enums\StaffRole;
use App\Domains\Identity\Models\AuditEntry;
use App\Domains\Patients\Actions\SearchPatients;
use App\Domains\Patients\Models\Patient;
use App\Domains\Platform\Enums\ProviderType;
use App\Domains\Platform\Models\Provider;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Auth;
use Inertia\Testing\AssertableInertia;
use Spatie\Permission\Models\Role;

uses(DatabaseMigrations::class);

beforeEach(function (): void {
    if (config('database.default') !== 'mysql') {
        $this->markTestSkipped('Tenancy tests require MySQL.');
    }

    $this->clinic = makeProvider('Sunrise Medical Centre', ProviderType::Clinic, 'sunrise.clinicflow.test');
    $this->receptionist = User::factory()->create(['name' => 'Nomvula Sithole']);
    app(AddStaffMember::class)->handle($this->clinic, $this->receptionist, StaffRole::Receptionist);
});

afterEach(function (): void {
    tenancy()->end();
    Provider::query()->get()->each->delete();
});

function openWorkspace(object $test, User $user, Provider $provider): string
{
    $response = $test->actingAs($user)->post("http://localhost/workspaces/{$provider->id}/open");
    $location = $response->headers->get('Location') ?? $response->headers->get('X-Inertia-Location');

    return (string) $location;
}

it('seeds role templates for the provider type when a provider is created', function (): void {
    $pharmacy = makeProvider('Vilakazi Pharmacy', ProviderType::Pharmacy);

    $this->clinic->run(fn () => expect(Role::count())->toBe(11));
    $pharmacy->run(fn () => expect(Role::query()->pluck('name')->all())->not->toContain('doctor')->toContain('pharmacist'));
});

it('lists usable workspaces and hides expired locum access', function (): void {
    $locum = User::factory()->create(['name' => 'Dr Pieter Ferreira']);
    app(AddStaffMember::class)->handle($this->clinic, $locum, StaffRole::LocumDoctor, now()->subDay());

    $this->actingAs($this->receptionist)->get('http://localhost/workspaces')
        ->assertInertia(fn (AssertableInertia $page) => $page->component('Auth/Workspaces')
            ->has('workspaces', 1)
            ->where('workspaces.0.name', 'Sunrise Medical Centre')
            ->where('workspaces.0.role', 'Receptionist'));

    $this->actingAs($locum)->get('http://localhost/workspaces')
        ->assertInertia(fn (AssertableInertia $page) => $page->has('workspaces', 0));
});

it('carries the user to the provider domain with a single-use link', function (): void {
    $url = openWorkspace($this, $this->receptionist, $this->clinic);
    expect($url)->toStartWith('http://sunrise.clinicflow.test/auth/handoff/');

    Auth::guard('web')->logout();

    $response = $this->get($url)->assertRedirect();
    expect((string) $response->headers->get('Location'))->toBe('http://sunrise.clinicflow.test/workspace');
    $this->assertAuthenticatedAs($this->receptionist);

    $this->get('http://sunrise.clinicflow.test/patients')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('Patients/Index')->where('canRegister', true));

    Auth::guard('web')->logout();
    $this->get($url)->assertForbidden();
});

it('refuses a link issued for a different provider', function (): void {
    makeProvider('Ubuntu Kids Clinic', ProviderType::Clinic, 'ubuntu.clinicflow.test');

    $url = openWorkspace($this, $this->receptionist, $this->clinic);
    Auth::guard('web')->logout();

    $this->get(str_replace('sunrise.', 'ubuntu.', $url))->assertForbidden();
});

it('refuses people without a membership for this provider', function (): void {
    $stranger = User::factory()->create();

    $this->actingAs($stranger)->get('http://sunrise.clinicflow.test/patients')->assertForbidden();
    $this->actingAs($stranger)->post("http://localhost/workspaces/{$this->clinic->id}/open")->assertForbidden();
});

it('lets reception register a patient with consents and an audit entry', function (): void {
    $this->actingAs($this->receptionist)
        ->post('http://sunrise.clinicflow.test/patients', patientPayload())
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $this->clinic->run(function (): void {
        $patient = Patient::query()->sole();

        expect($patient->fullName())->toBe('Thandi Mokoena')
            ->and($patient->date_of_birth->toDateString())->toBe('1988-04-12')
            ->and($patient->sex?->value)->toBe('female')
            ->and($patient->consents()->count())->toBe(2)
            ->and($patient->getRawOriginal('id_number'))->not->toBe(saId())
            ->and(AuditEntry::query()->where('description', 'Patient registered')->exists())->toBeTrue();
    });
});

it('stops a billing clerk from registering patients', function (): void {
    $clerk = User::factory()->create();
    app(AddStaffMember::class)->handle($this->clinic, $clerk, StaffRole::BillingClerk);

    $this->actingAs($clerk)->post('http://sunrise.clinicflow.test/patients', patientPayload())->assertForbidden();
});

it('applies the registration rules', function (array $overrides, string $errorField): void {
    $this->actingAs($this->receptionist)
        ->post('http://sunrise.clinicflow.test/patients', patientPayload($overrides))
        ->assertSessionHasErrors($errorField);
})->with([
    'invalid SA ID' => [['id_number' => '8804120547089'], 'id_number'],
    'child under 12 without guardian' => [['id_number' => saId('200101'), 'consent_given_by' => 'guardian'], 'guardian_name'],
    'teen consenting without maturity' => [['id_number' => saId('110101')], 'maturity_confirmed'],
    'adult with guardian consent' => [['consent_given_by' => 'guardian'], 'consent_given_by'],
    'no cell and not ticked' => [['cell' => ''], 'cell'],
    'missing POPIA consent' => [['popia_consent' => false], 'popia_consent'],
]);

it('accepts a child with guardian details and a patient without a cellphone', function (): void {
    $this->actingAs($this->receptionist)
        ->post('http://sunrise.clinicflow.test/patients', patientPayload([
            'first_names' => 'Lwazi',
            'id_number' => saId('200315', '5321'),
            'cell' => '',
            'no_cell' => true,
            'consent_given_by' => 'guardian',
            'guardian_name' => 'Thandi Mokoena',
            'guardian_relationship' => 'Mother',
            'guardian_cell' => '0825550147',
        ]))
        ->assertSessionHasNoErrors();
});

it('blocks registering the same SA ID twice at one provider', function (): void {
    $this->actingAs($this->receptionist)->post('http://sunrise.clinicflow.test/patients', patientPayload());

    $this->actingAs($this->receptionist)
        ->post('http://sunrise.clinicflow.test/patients', patientPayload(['first_names' => 'T']))
        ->assertSessionHasErrors('id_number');
});

it('finds patients by SA ID, cell or name and never across providers', function (): void {
    $this->actingAs($this->receptionist)->post('http://sunrise.clinicflow.test/patients', patientPayload());
    $other = makeProvider('Ubuntu Kids Clinic');

    $this->clinic->run(function (): void {
        $search = app(SearchPatients::class);
        expect($search->handle(saId()))->toHaveCount(1)
            ->and($search->handle('0825550147'))->toHaveCount(1)
            ->and($search->handle('thandi mok'))->toHaveCount(1);
    });

    $other->run(fn () => expect(app(SearchPatients::class)->handle(saId()))->toHaveCount(0));
});

it('keeps audit entries and patient records permanent', function (): void {
    $this->actingAs($this->receptionist)->post('http://sunrise.clinicflow.test/patients', patientPayload());

    $this->clinic->run(function (): void {
        $entry = AuditEntry::query()->firstOrFail();
        expect(fn () => $entry->update(['description' => 'changed']))->toThrow(LogicException::class)
            ->and(fn () => Patient::query()->firstOrFail()->delete())->toThrow(LogicException::class);
    });
});
