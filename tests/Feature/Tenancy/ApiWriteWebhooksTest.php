<?php

use App\Domains\Api\Actions\ApiKeys;
use App\Domains\Api\Webhooks\Webhooks;
use App\Domains\Api\Webhooks\WebhookUrlGuard;
use App\Domains\Identity\Actions\AddStaffMember;
use App\Domains\Identity\Enums\StaffRole;
use App\Domains\Platform\Enums\ProviderType;
use App\Domains\Platform\Enums\SubscriptionStatus;
use App\Domains\Platform\Models\Package;
use App\Domains\Platform\Models\Provider;
use App\Domains\Platform\Models\Subscription;
use App\Domains\Scheduling\Models\RosterSession;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\PackageSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

uses(DatabaseMigrations::class);

beforeEach(function (): void {
    if (config('database.default') !== 'mysql') {
        $this->markTestSkipped('Tenancy tests require MySQL.');
    }
    $this->seed(PackageSeeder::class);
    $this->clinic = makeProvider('Sunrise Medical Centre', ProviderType::Clinic, 'sunrise.clinicflow.test');
    Subscription::create(['tenant_id' => $this->clinic->id, 'package_id' => Package::query()->where('code', 'clinic-pro')->value('id'), 'status' => SubscriptionStatus::Active, 'current_period_ends_at' => now()->addMonth()]);
    $this->ownerUser = User::factory()->create();
    $this->doctorUser = User::factory()->create(['name' => 'Dr Mokoena']);
    app(AddStaffMember::class)->handle($this->clinic, $this->ownerUser, StaffRole::Owner);
    app(AddStaffMember::class)->handle($this->clinic, $this->doctorUser, StaffRole::Doctor);
    // No real DNS in tests: "evil.test" resolves to a private address, everything else to a public one.
    app()->instance(WebhookUrlGuard::class, new class extends WebhookUrlGuard
    {
        protected function resolve(string $host): array
        {
            return $host === 'evil.test' ? ['10.1.2.3'] : ['93.184.216.34'];
        }
    });
    $this->base = 'http://sunrise.clinicflow.test/api/v1';
});

afterEach(function (): void {
    tenancy()->end();
    Provider::query()->get()->each->delete();
});

it('books, reschedules and cancels through the API using the same rules as the booking screen', function (): void {
    $day = CarbonImmutable::parse('tomorrow');
    [$key, $readOnly, $patientId] = $this->clinic->run(function () use ($day): array {
        RosterSession::create(['staff_id' => $this->doctorUser->id, 'starts_at' => $day->setTime(9, 0), 'ends_at' => $day->setTime(10, 0), 'slot_minutes' => 15]);

        return [app(ApiKeys::class)->create('Booking', ['appointments:write'], [], null, 1)['key'], app(ApiKeys::class)->create('Read', ['appointments:read'], [], null, 1)['key'],
            registerTestPatient('Thandi', '880412')->id];
    });
    $auth = ['Authorization' => "Bearer {$key}"];
    $slot = $day->setTime(9, 15)->toIso8601String();

    $this->postJson("{$this->base}/appointments/book", ['patient_id' => $patientId, 'staff_id' => $this->doctorUser->id, 'starts_at' => $slot], ['Authorization' => "Bearer {$readOnly}"])->assertStatus(403);
    $booked = $this->postJson("{$this->base}/appointments/book", ['patient_id' => $patientId, 'staff_id' => $this->doctorUser->id, 'starts_at' => $slot], $auth)->assertStatus(201)->json('data');
    $this->postJson("{$this->base}/appointments/book", ['patient_id' => $patientId, 'staff_id' => $this->doctorUser->id, 'starts_at' => $slot], $auth)->assertStatus(422);

    $moved = $this->postJson("{$this->base}/appointments/{$booked['id']}/reschedule", ['starts_at' => $day->setTime(9, 45)->toIso8601String()], $auth)->assertOk()->json('data');
    expect($moved['replaces'])->toBe($booked['id']);
    $this->postJson("{$this->base}/appointments/{$moved['id']}/reschedule", ['starts_at' => $day->setTime(11, 0)->toIso8601String()], $auth)->assertStatus(422);
    $this->postJson("{$this->base}/appointments/{$moved['id']}/cancel", ['reason' => 'Patient asked'], $auth)->assertOk()->assertJsonPath('data.status', 'cancelled');

    $this->clinic->run(fn () => expect(DB::table('appointments')->where('id', $booked['id'])->value('status'))->toBe('cancelled')
        ->and(DB::table('appointments')->where('id', $moved['id'])->value('status'))->toBe('cancelled'));
});

it('registers patients only with consent stated by the integrator, and records how it was obtained', function (): void {
    $key = $this->clinic->run(fn () => app(ApiKeys::class)->create('Forms', ['patients:write'], [], null, 1)['key']);
    $auth = ['Authorization' => "Bearer {$key}"];
    $patient = ['first_names' => 'Sipho', 'surname' => 'Dlamini', 'date_of_birth' => '1985-01-01', 'cell' => '0821112222'];

    $this->postJson("{$this->base}/patients/register", $patient, $auth)->assertStatus(422)->assertJsonValidationErrors('consent');
    $this->postJson("{$this->base}/patients/register", $patient + ['consent' => ['popia' => false, 'treatment' => true, 'method' => 'online_form', 'obtained_at' => now()->subHour()->toIso8601String()]], $auth)
        ->assertStatus(422)->assertJsonValidationErrors('consent.popia');
    $id = $this->postJson("{$this->base}/patients/register", $patient + ['consent' => ['popia' => true, 'treatment' => true, 'method' => 'online_form', 'obtained_at' => now()->subHour()->toIso8601String()]], $auth)
        ->assertStatus(201)->json('data.id');

    $this->clinic->run(fn () => expect(DB::table('activity_log')->where('subject_id', $id)->where('description', 'like', '%consent stated by the integrator%')->value('properties'))->toContain('online_form'));
});

it('accepts only public https webhook addresses', function (): void {
    $this->clinic->run(function (): void {
        $hooks = app(Webhooks::class);
        foreach (['http://hooks.example.com/x', 'https://localhost/x', 'https://10.0.0.5/x', 'https://evil.test/x', 'https://user:pw@hooks.example.com/x'] as $bad) {
            expect(fn () => $hooks->addEndpoint($bad, ['appointment.booked'], 1))->toThrow(ValidationException::class);
        }
        expect(fn () => $hooks->addEndpoint('https://hooks.example.com/x', [], 1))->toThrow(ValidationException::class);
        expect($hooks->addEndpoint('https://hooks.example.com/x', ['appointment.booked'], 1)['secret'])->toStartWith('whsec_');
    });
});

it('signs deliveries, sends only IDs and status, and retries then switches off a failing address', function (): void {
    $this->clinic->run(function (): void {
        $hooks = app(Webhooks::class);
        $secret = $hooks->addEndpoint('https://hooks.example.com/ok', ['patient.registered', 'invoice.paid'], 1)['secret'];
        Http::fake(['hooks.example.com/ok' => Http::response('', 200)]);

        $patient = registerTestPatient('Thandi', '880412');
        expect($hooks->deliverDue())->toBe(1);
        Http::assertSent(function (HttpRequest $r) use ($secret, $patient): bool {
            [$t, $v1] = array_map(fn ($p) => explode('=', $p, 2)[1], explode(',', $r->header('X-ClinicFlow-Signature')[0]));
            $body = json_decode($r->body(), true);

            return hash_equals(hash_hmac('sha256', $t.'.'.$r->body(), $secret), $v1) && $body['event'] === 'patient.registered'
                && $body['data'] === ['patient_id' => $patient->id];
        });
        expect(DB::table('webhook_deliveries')->value('status'))->toBe('delivered');

        DB::table('webhook_endpoints')->delete();
        $hooks->addEndpoint('https://hooks.example.com/down', ['patient.registered'], 1);
        Http::fake(['hooks.example.com/down' => Http::response('', 500)]);
        for ($n = 0; $n < Webhooks::DISABLE_AFTER; $n++) {
            registerTestPatient('P'.$n, '9001'.str_pad((string) ($n + 10), 2, '0', STR_PAD_LEFT), null, '08300000'.$n.'0');
            for ($i = 0; $i <= count(Webhooks::BACKOFF); $i++) {
                $hooks->deliverDue();
                $this->travel(13)->hours();
            }
        }
        expect(DB::table('webhook_deliveries')->where('status', 'failed')->count())->toBe(Webhooks::DISABLE_AFTER)
            ->and(DB::table('webhook_deliveries')->where('status', 'failed')->value('attempts'))->toBe(count(Webhooks::BACKOFF) + 1)
            ->and((bool) DB::table('webhook_endpoints')->value('active'))->toBeFalse()
            ->and(DB::table('message_log')->where('subject', 'Webhook switched off')->exists())->toBeTrue();
    });
});
