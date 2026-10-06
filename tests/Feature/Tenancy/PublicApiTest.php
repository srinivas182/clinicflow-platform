<?php

use App\Domains\Api\Actions\ApiKeys;
use App\Domains\Identity\Actions\AddStaffMember;
use App\Domains\Identity\Enums\StaffRole;
use App\Domains\Platform\Enums\ProviderType;
use App\Domains\Platform\Enums\SubscriptionStatus;
use App\Domains\Platform\Models\Package;
use App\Domains\Platform\Models\Provider;
use App\Domains\Platform\Models\Subscription;
use App\Domains\Scheduling\Models\Appointment;
use App\Domains\Scheduling\Models\RosterSession;
use App\Http\Middleware\RequireRecentConfirmation;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\PackageSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

uses(DatabaseMigrations::class);

beforeEach(function (): void {
    if (config('database.default') !== 'mysql') {
        $this->markTestSkipped('Tenancy tests require MySQL.');
    }
    // These tests exercise protected actions as a user who has just confirmed their identity (step-up).
    $this->withSession([RequireRecentConfirmation::SESSION_KEY => now()->getTimestamp()]);
    $this->seed(PackageSeeder::class);
    $this->clinic = makeProvider('Sunrise Medical Centre', ProviderType::Clinic, 'sunrise.clinicflow.test');
    $this->subscription = Subscription::create(['tenant_id' => $this->clinic->id, 'package_id' => Package::query()->where('code', 'clinic-pro')->value('id'),
        'status' => SubscriptionStatus::Active, 'current_period_ends_at' => now()->addMonth()]);
    $this->ownerUser = User::factory()->create();
    $this->doctorUser = User::factory()->create(['name' => 'Dr Mokoena']);
    app(AddStaffMember::class)->handle($this->clinic, $this->ownerUser, StaffRole::Owner);
    app(AddStaffMember::class)->handle($this->clinic, $this->doctorUser, StaffRole::Doctor);
    $this->base = 'http://sunrise.clinicflow.test/api/v1';
});

afterEach(function (): void {
    tenancy()->end();
    Provider::query()->get()->each->delete();
});

function apiKey(object $t, array $scopes, array $ips = [], ?string $expires = null): string
{
    return $t->clinic->run(fn () => app(ApiKeys::class)->create('Integration', $scopes, $ips, $expires, $t->ownerUser->id)['key']);
}

it('creates keys that are shown once and stored only as a hash, and revokes them', function (): void {
    $this->clinic->run(function (): void {
        $keys = app(ApiKeys::class);
        expect(fn () => $keys->create('No scopes', [], [], null, 1))->toThrow(ValidationException::class)
            ->and(fn () => $keys->create('Bad IP', ['prices:read'], ['not-an-ip'], null, 1))->toThrow(ValidationException::class);
        $created = $keys->create('Website', ['prices:read', 'made:up'], ['41.0.0.0/8'], null, 1);
        $row = DB::table('api_keys')->find($created['id']);
        expect($created['key'])->toStartWith('cf_live_')->and(json_decode($row->scopes, true))->toBe(['prices:read'])
            ->and($row->key_hash)->toBe(hash('sha256', $created['key']))
            ->and(DB::table('api_keys')->where('key_hash', $created['key'])->orWhere('prefix', $created['key'])->exists())->toBeFalse();
        $keys->revoke($created['id']);
        expect($keys->authenticate($created['key'], '41.1.2.3'))->toBeNull();
    });
});

it('refuses requests without a valid key, permission, package feature, allowed IP, or within the rate limit', function (): void {
    $key = apiKey($this, ['prices:read']);
    $this->getJson("{$this->base}/prices")->assertStatus(401);
    $this->getJson("{$this->base}/prices", ['Authorization' => 'Bearer cf_live_wrong'])->assertStatus(401);
    $this->getJson("{$this->base}/invoices?from=2026-01-01&to=2026-01-31", ['Authorization' => "Bearer {$key}"])->assertStatus(403)->assertJsonPath('error.status', 403);
    $this->getJson("{$this->base}/prices", ['Authorization' => "Bearer {$key}"])->assertOk()->assertJsonStructure(['data' => ['online_consults', 'prepaid_packages']]);

    $ipBound = apiKey($this, ['prices:read'], ['10.9.9.9']);
    $this->getJson("{$this->base}/prices", ['Authorization' => "Bearer {$ipBound}"])->assertStatus(401);

    config(['clinicflow.api.per_minute' => 2]);
    $limited = apiKey($this, ['prices:read']);
    $this->getJson("{$this->base}/prices", ['Authorization' => "Bearer {$limited}"])->assertOk();
    $this->getJson("{$this->base}/prices", ['Authorization' => "Bearer {$limited}"])->assertOk();
    $this->getJson("{$this->base}/prices", ['Authorization' => "Bearer {$limited}"])->assertStatus(429);

    $this->subscription->update(['package_id' => Package::query()->where('code', 'clinic-starter')->value('id')]);
    $this->getJson("{$this->base}/prices", ['Authorization' => "Bearer {$key}"])->assertStatus(403);

    $this->clinic->run(fn () => expect(DB::table('api_requests')->count())->toBeGreaterThanOrEqual(5)->and(DB::table('api_requests')->where('status', 429)->count())->toBe(1));
    $this->get("{$this->base}/openapi.json")->assertOk()->assertJsonPath('openapi', '3.1.0');
});

it('returns availability, appointments without reasons, exact-match patient demographics and invoices', function (): void {
    $key = apiKey($this, ['availability:read', 'appointments:read', 'patients:read', 'invoices:read']);
    $auth = ['Authorization' => "Bearer {$key}"];
    $day = CarbonImmutable::parse('tomorrow');
    $patientId = $this->clinic->run(function () use ($day): string {
        $patient = registerTestPatient('Thandi', '880412', null, '0825550147');
        $session = RosterSession::create(['staff_id' => $this->doctorUser->id, 'starts_at' => $day->setTime(9, 0), 'ends_at' => $day->setTime(10, 0), 'slot_minutes' => 15]);
        Appointment::create(['patient_id' => $patient->id, 'staff_id' => $this->doctorUser->id, 'roster_session_id' => $session->id, 'consult_type' => 'in_person',
            'starts_at' => $day->setTime(9, 0), 'ends_at' => $day->setTime(9, 15), 'status' => 'booked', 'reason' => 'Chest pain and shortness of breath']);

        return $patient->id;
    });

    $this->getJson("{$this->base}/availability?date={$day->toDateString()}", $auth)->assertOk()->assertJsonPath('data.0.name', 'Dr Mokoena')->assertJsonCount(3, 'data.0.slots');
    $appointments = $this->getJson("{$this->base}/appointments?from={$day->toDateString()}&to={$day->toDateString()}", $auth)->assertOk()->json('data');
    expect($appointments)->toHaveCount(1)->and($appointments[0])->not->toHaveKey('reason')->and(json_encode($appointments))->not->toContain('Chest pain');

    $this->getJson("{$this->base}/patients?cell=0825550147", $auth)->assertOk()->assertJsonPath('data.0.id', $patientId)->assertJsonMissingPath('data.0.id_number');
    $this->getJson("{$this->base}/patients?id_number=".saId('880412'), $auth)->assertOk()->assertJsonPath('data.0.first_names', 'Thandi');
    $this->getJson("{$this->base}/patients?id_number=1234567890123", $auth)->assertOk()->assertJsonCount(0, 'data');
    $this->getJson("{$this->base}/patients", $auth)->assertStatus(422);
    $this->getJson("{$this->base}/appointments?from=2026-01-01&to=2026-06-01", $auth)->assertStatus(422);
    $this->getJson("{$this->base}/invoices?from={$day->subDay()->toDateString()}&to={$day->toDateString()}", $auth)->assertOk()->assertJsonStructure(['data']);
});

it('lets only the owner or a practice admin manage keys, and shows a new key once', function (): void {
    $this->actingAs($this->doctorUser)->post('http://sunrise.clinicflow.test/settings/api/keys', ['name' => 'X', 'scopes' => ['prices:read']])->assertForbidden();
    $this->actingAs($this->ownerUser)->post('http://sunrise.clinicflow.test/settings/api/keys', ['name' => 'Website', 'scopes' => ['prices:read']])
        ->assertSessionHas('new_api_key', fn ($k) => str_starts_with($k, 'cf_live_'));
    $this->actingAs($this->ownerUser)->get('http://sunrise.clinicflow.test/settings/api')->assertOk()
        ->assertInertia(fn ($p) => $p->component('Settings/Api')->where('enabled', true)->where('keys.0.name', 'Website'));
});
