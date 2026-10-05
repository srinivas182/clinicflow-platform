<?php

use App\Domains\Identity\Actions\AddStaffMember;
use App\Domains\Identity\Enums\StaffRole;
use App\Domains\Identity\Models\Staff;
use App\Domains\Messaging\Contracts\MessageSender;
use App\Domains\Messaging\Support\LogMessageSender;
use App\Domains\Platform\Actions\ProviderGroups;
use App\Domains\Platform\Enums\ProviderType;
use App\Domains\Platform\Enums\SubscriptionStatus;
use App\Domains\Platform\Models\Package;
use App\Domains\Platform\Models\Provider;
use App\Domains\Platform\Models\ProviderGroup;
use App\Domains\Platform\Models\Subscription;
use App\Domains\Scheduling\Models\Appointment;
use App\Domains\Scheduling\Models\RosterSession;
use App\Domains\Visits\Enums\PayerType;
use App\Models\User;
use Database\Seeders\ClinicalReferenceSeeder;
use Database\Seeders\PackageSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;

uses(DatabaseMigrations::class);

beforeEach(function (): void {
    if (config('database.default') !== 'mysql') {
        $this->markTestSkipped('Tenancy tests require MySQL.');
    }
    $this->artisan('migrate:fresh', ['--database' => 'hub', '--path' => 'database/migrations/hub'])->assertSuccessful();
    $this->seed(ClinicalReferenceSeeder::class);
    $this->seed(PackageSeeder::class);
    $this->app->instance(MessageSender::class, new LogMessageSender);
    $this->clinic = makeProvider('Sunrise Medical Centre', ProviderType::Clinic, 'sunrise.clinicflow.test');
    $this->sub = Subscription::create(['tenant_id' => $this->clinic->id, 'package_id' => Package::query()->where('code', 'clinic-standard')->value('id'),
        'status' => SubscriptionStatus::Active, 'current_period_ends_at' => now()->addMonth()]);
    $this->ownerUser = User::factory()->create();
    $this->doctorUser = User::factory()->create(['name' => 'Dr Mokoena']);
    $this->nurseUser = User::factory()->create();
    app(AddStaffMember::class)->handle($this->clinic, $this->ownerUser, StaffRole::Owner);
    app(AddStaffMember::class)->handle($this->clinic, $this->doctorUser, StaffRole::Doctor);
    app(AddStaffMember::class)->handle($this->clinic, $this->nurseUser, StaffRole::Nurse);
    tenancy()->initialize($this->clinic);
    $this->doctor = Staff::query()->findOrFail($this->doctorUser->id);
    $patient = registerTestPatient('Thandi', '880412');
    seenByDoctor($this, $patient, PayerType::Cash);
    $day = now()->startOfDay();
    $session = RosterSession::create(['staff_id' => $this->doctorUser->id, 'starts_at' => $day->copy()->setTime(9, 0), 'ends_at' => $day->copy()->setTime(10, 0), 'slot_minutes' => 15]);
    foreach ([['09:00', 'completed'], ['09:15', 'no_show']] as [$t, $status]) {
        $start = $day->copy()->setTimeFromTimeString($t);
        Appointment::create(['patient_id' => $patient->id, 'staff_id' => $this->doctorUser->id, 'roster_session_id' => $session->id, 'consult_type' => 'in_person',
            'starts_at' => $start, 'ends_at' => $start->copy()->addMinutes(15), 'status' => $status]);
    }
    tenancy()->end();
});

afterEach(function (): void {
    tenancy()->end();
    Provider::query()->get()->each->delete();
});

it('shows key figures with the previous period, the money trend and doctors by name', function (): void {
    $this->actingAs($this->ownerUser)->get('http://sunrise.clinicflow.test/analytics')->assertOk()->assertInertia(fn ($p) => $p->component('Analytics/Dashboard')
        ->where('available', true)->where('current.billed', 520)->where('current.takings', 520)->where('current.collection_rate', 100)
        ->where('current.no_show_rate', 50)->where('current.utilisation', 50)->where('current.appointments', 2)->where('previous.appointments', 0)
        ->where('doctors.0.doctor', 'Dr Mokoena')->has('trend', 6));
});

it('needs finance access and a package with advanced reports', function (): void {
    $this->actingAs($this->nurseUser)->get('http://sunrise.clinicflow.test/analytics')->assertForbidden();
    $this->sub->update(['package_id' => Package::query()->where('code', 'clinic-starter')->value('id')]);
    $this->actingAs($this->ownerUser)->get('http://sunrise.clinicflow.test/analytics')->assertOk()->assertInertia(fn ($p) => $p->where('available', false)->missing('current'));
});

it('adds key figures per practice to the group roll-up, totals only', function (): void {
    $group = ProviderGroup::query()->create(['name' => 'Sunrise Group', 'billing' => 'separate']);
    app(ProviderGroups::class)->addMember($group, $this->clinic);
    $rows = app(ProviderGroups::class)->dashboard($group->fresh(), now()->startOfMonth()->toDateString(), now()->toDateString());
    expect($rows[0]['collection_rate'])->toEqual(100)->and($rows[0]['no_show_rate'])->toEqual(50)
        ->and(array_keys($rows[0]))->not->toContain('patients');
});
