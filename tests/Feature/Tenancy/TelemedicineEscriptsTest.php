<?php

use App\Domains\Hub\Actions\EscriptExchange;
use App\Domains\Hub\Actions\NetworkIdentity;
use App\Domains\Hub\Models\HubEscript;
use App\Domains\Identity\Actions\AddStaffMember;
use App\Domains\Identity\Contracts\OtpSender;
use App\Domains\Identity\Enums\StaffRole;
use App\Domains\Identity\Models\Staff;
use App\Domains\Patients\Models\Patient;
use App\Domains\Platform\Enums\ProviderType;
use App\Domains\Platform\Enums\SubscriptionStatus;
use App\Domains\Platform\Models\Package;
use App\Domains\Platform\Models\Provider;
use App\Domains\Platform\Models\Subscription;
use App\Domains\Prescribing\Actions\AmendPrescription;
use App\Domains\Prescribing\Actions\RequestSigningPin;
use App\Domains\Prescribing\Actions\SignPrescription;
use App\Domains\Scheduling\Actions\BookAppointment;
use App\Domains\Scheduling\Actions\CancelAppointment;
use App\Domains\Scheduling\Enums\ConsultType;
use App\Domains\Scheduling\Models\Appointment;
use App\Domains\Scheduling\Models\RosterSession;
use App\Domains\Telemedicine\Models\TeleSession;
use App\Domains\Telemedicine\Models\VideoConfig;
use App\Domains\Telemedicine\Support\LiveKit;
use App\Domains\Visits\Enums\PayerType;
use App\Domains\Wallet\Models\Wallet;
use App\Domains\Wallet\Models\WalletReservation;
use App\Models\User;
use Database\Seeders\ClinicalReferenceSeeder;
use Database\Seeders\PackageSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

uses(DatabaseMigrations::class);

beforeEach(function (): void {
    if (config('database.default') !== 'mysql') {
        $this->markTestSkipped('Tenancy tests require MySQL.');
    }

    $this->artisan('migrate:fresh', ['--database' => 'hub', '--path' => 'database/migrations/hub'])->assertSuccessful();
    $this->seed([PackageSeeder::class, ClinicalReferenceSeeder::class]);
    $this->clinic = makeProvider('Sunrise Medical Centre', ProviderType::Clinic, 'sunrise.clinicflow.test');
    $this->pharmacy = makeProvider('Corner Pharmacy', ProviderType::Pharmacy, 'corner.clinicflow.test');
    $this->otherPharmacy = makeProvider('Other Pharmacy', ProviderType::Pharmacy, 'other.clinicflow.test');
    $this->doctorUser = User::factory()->create(['name' => 'Dr Naidoo']);
    $this->otherDoctorUser = User::factory()->create();
    $this->ownerUser = User::factory()->create();
    $this->pharmacistUser = User::factory()->create();
    $add = app(AddStaffMember::class);
    $add->handle($this->clinic, $this->doctorUser, StaffRole::Doctor);
    $add->handle($this->clinic, $this->otherDoctorUser, StaffRole::Doctor);
    $add->handle($this->clinic, $this->ownerUser, StaffRole::Owner);
    $add->handle($this->pharmacy, $this->pharmacistUser, StaffRole::Pharmacist);

    $this->subscription = Subscription::create([
        'tenant_id' => $this->clinic->id, 'package_id' => Package::query()->where('code', 'clinic-starter')->value('id'),
        'status' => SubscriptionStatus::Active, 'current_period_ends_at' => now()->addMonth(),
    ]);
    $this->video = VideoConfig::create(['driver' => 'cloud', 'mode' => 'test', 'url' => 'wss://cf-test.livekit.cloud', 'api_key' => 'APIkey1', 'api_secret' => 'secret-secret-secret-secret-1234', 'enabled' => true]);
});

afterEach(function (): void {
    tenancy()->end();
    Provider::query()->get()->each->delete();
});

function enableTelemedicine(object $t, int $walletCents = 50000): void
{
    $t->subscription->forceFill(['addons' => ['telemedicine']])->save();
    Wallet::for($t->clinic->id)->forceFill(['balance_cents' => $walletCents])->save();
}

function bookOnline(object $t, ConsultType $type = ConsultType::Video): Appointment
{
    return $t->clinic->run(function () use ($type): Appointment {
        $doctor = Staff::query()->findOrFail(test()->doctorUser->id);
        $start = now()->addDay()->setTime(9, 0);
        RosterSession::query()->firstOrCreate(['staff_id' => $doctor->id, 'session_type' => 'telemedicine', 'starts_at' => $start], ['ends_at' => $start->copy()->addHours(2), 'slot_minutes' => 15]);
        $patient = Patient::query()->where('cell', '0825550147')->first() ?? registerTestPatient('Thandi', '880412', null, '0825550147');

        return app(BookAppointment::class)->handle($patient, $doctor, $start, $type);
    });
}

function livekitEvent(object $t, array $event): TestResponse
{
    $body = json_encode($event, JSON_THROW_ON_ERROR);
    $token = (new LiveKit($t->video))->jwt(['sha256' => base64_encode(hash('sha256', $body, true))]);

    return $t->call('POST', 'http://localhost/api/webhooks/livekit', [], [], [], ['CONTENT_TYPE' => 'application/webhook+json', 'HTTP_AUTHORIZATION' => $token], $body);
}

it('signs join passes and verifies LiveKit webhooks', function (): void {
    $lk = new LiveKit($this->video);
    [$head, $body] = explode('.', $lk->joinToken('room-1', 'doctor-7', 'Dr Naidoo'));
    $claims = json_decode(base64_decode(strtr($body, '-_', '+/')), true);

    expect($claims['iss'])->toBe('APIkey1')->and($claims['sub'])->toBe('doctor-7')->and($claims['video'])->toMatchArray(['room' => 'room-1', 'roomJoin' => true]);

    $payload = '{"event":"room_finished"}';
    $good = $lk->jwt(['sha256' => base64_encode(hash('sha256', $payload, true))]);
    expect($lk->verifyWebhook($payload, $good))->toBeTrue()
        ->and($lk->verifyWebhook('{"event":"tampered"}', $good))->toBeFalse()
        ->and($lk->verifyWebhook($payload, $good.'x'))->toBeFalse();
});

it('keeps one active video option and tests the connection', function (): void {
    $admin = User::factory()->create();
    $admin->forceFill(['is_platform_admin' => true])->save();

    $this->actingAs($admin)->put('http://localhost/admin/telemedicine/self_hosted', ['mode' => 'live', 'enabled' => true, 'url' => 'wss://video.clinicflow.test'])
        ->assertSessionHasErrors('url');
    $this->actingAs($admin)->put('http://localhost/admin/telemedicine/self_hosted', ['mode' => 'live', 'enabled' => true, 'url' => 'wss://video.clinicflow.test', 'api_key' => 'K2', 'api_secret' => 'S2-long-secret'])
        ->assertSessionHasNoErrors();

    expect(VideoConfig::active()?->driver)->toBe('self_hosted')->and($this->video->fresh()?->enabled)->toBeFalse()->and($this->video->fresh()?->api_secret)->toBe('secret-secret-secret-secret-1234');

    Http::fake(['video.clinicflow.test/twirp/*' => Http::response([], 200)]);
    $this->actingAs($admin)->post('http://localhost/admin/telemedicine/self_hosted/test')->assertSessionHas('success');
    Http::assertSent(fn ($r) => str_contains($r->url(), 'https://video.clinicflow.test/twirp/livekit.RoomService/CreateRoom'));
});

it('books online consults only with the add-on and enough wallet credit, and releases on cancel', function (): void {
    expect(fn () => bookOnline($this))->toThrow(ValidationException::class);

    enableTelemedicine($this, 10000);
    expect(fn () => bookOnline($this))->toThrow(ValidationException::class);
    $this->clinic->run(fn () => expect(Appointment::query()->count())->toBe(0));

    Wallet::for($this->clinic->id)->forceFill(['balance_cents' => 50000])->save();
    $appointment = bookOnline($this);
    expect(Wallet::for($this->clinic->id)->reserved_cents)->toBe(15 * 250);

    $this->clinic->run(function () use ($appointment): void {
        expect(TeleSession::query()->sole()->room_name)->toBe("cf_{$this->clinic->id}_{$appointment->id}");
        app(CancelAppointment::class)->handle($appointment, 'Patient unwell');
    });
    expect(Wallet::for($this->clinic->id)->reserved_cents)->toBe(0)
        ->and(WalletReservation::query()->sole()->status)->toBe('released');
});

it('charges only the minutes both were connected, once', function (): void {
    enableTelemedicine($this);
    $appointment = bookOnline($this);
    $room = "cf_{$this->clinic->id}_{$appointment->id}";
    $t0 = now()->addDay()->setTime(9, 1)->timestamp;

    livekitEvent($this, ['event' => 'participant_joined', 'room' => ['name' => $room], 'participant' => ['identity' => 'doctor-'.$this->doctorUser->id], 'createdAt' => $t0])->assertOk();
    livekitEvent($this, ['event' => 'participant_joined', 'room' => ['name' => $room], 'participant' => ['identity' => 'patient-x'], 'createdAt' => $t0 + 30])->assertOk();
    livekitEvent($this, ['event' => 'participant_left', 'room' => ['name' => $room], 'participant' => ['identity' => 'patient-x'], 'createdAt' => $t0 + 30 + 605])->assertOk();
    livekitEvent($this, ['event' => 'room_finished', 'room' => ['name' => $room], 'createdAt' => $t0 + 700])->assertOk();

    $this->call('POST', 'http://localhost/api/webhooks/livekit', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'forged'], '{}')->assertStatus(401);

    $this->clinic->run(function (): void {
        $session = TeleSession::query()->sole();
        expect($session->status)->toBe('ended')->and($session->connected_seconds)->toBe(605)->and($session->charged_minutes)->toBe(11);
    });
    expect(Wallet::for($this->clinic->id)->balance_cents)->toBe(50000 - 11 * 250)->and(Wallet::for($this->clinic->id)->reserved_cents)->toBe(0);
});

it('releases the reservation when the consult never connects', function (): void {
    enableTelemedicine($this);
    $appointment = bookOnline($this, ConsultType::Audio);
    $room = "cf_{$this->clinic->id}_{$appointment->id}";

    livekitEvent($this, ['event' => 'participant_joined', 'room' => ['name' => $room], 'participant' => ['identity' => 'doctor-1'], 'createdAt' => now()->timestamp]);
    livekitEvent($this, ['event' => 'room_finished', 'room' => ['name' => $room], 'createdAt' => now()->addMinutes(10)->timestamp]);

    $this->clinic->run(fn () => expect(TeleSession::query()->sole()->status)->toBe('failed'));
    expect(Wallet::for($this->clinic->id)->balance_cents)->toBe(50000)->and(WalletReservation::query()->sole()->status)->toBe('released');
});

it('opens the call screen only for the booked doctor from 15 minutes before', function (): void {
    enableTelemedicine($this);
    $appointment = bookOnline($this);
    Http::fake(['cf-test.livekit.cloud/twirp/*' => Http::response([], 200)]);

    $this->actingAs($this->doctorUser)->get("http://sunrise.clinicflow.test/telemedicine/{$appointment->id}/call")->assertForbidden();

    $this->travelTo(now()->addDay()->setTime(8, 50));
    $this->actingAs($this->otherDoctorUser)->get("http://sunrise.clinicflow.test/telemedicine/{$appointment->id}/call")->assertForbidden();
    $this->actingAs($this->doctorUser)->get("http://sunrise.clinicflow.test/telemedicine/{$appointment->id}/call")
        ->assertInertia(fn ($page) => $page->component('Telemedicine/Call')->where('serverUrl', 'wss://cf-test.livekit.cloud')->where('type', 'video')->has('token'));
});

it('sends e-scripts only for linked patients and lets the chosen pharmacy dispense once', function (): void {
    $this->doctor = $this->clinic->run(fn () => Staff::query()->findOrFail($this->doctorUser->id));
    [$script, $patient] = $this->clinic->run(function (): array {
        $patient = registerTestPatient('Sipho', '850101', null, '0821112222');
        $consult = seenByDoctor($this, $patient, PayerType::Cash);

        return [signedScript($this, $consult, [['Paracetamol', 10]]), $patient];
    });
    $exchange = app(EscriptExchange::class);
    $send = fn (string $to) => $this->clinic->run(fn () => $exchange->send($script->fresh(), $this->clinic, $to));

    expect(fn () => $send($this->pharmacy->id))->toThrow(ValidationException::class);

    $this->clinic->run(fn () => app(NetworkIdentity::class)->register($patient->fresh(), $this->clinic));
    expect(fn () => $send($this->clinic->id))->toThrow(ValidationException::class);

    $escript = $send($this->pharmacy->id);
    expect($escript->signature_hash)->toBe($this->clinic->run(fn () => $script->fresh()?->signature_hash))
        ->and($escript->payload['items'][0]['description'])->toContain('Paracetamol')
        ->and(fn () => $send($this->pharmacy->id))->toThrow(ValidationException::class);

    $this->actingAs($this->pharmacistUser)->get('http://corner.clinicflow.test/escripts')->assertInertia(fn ($page) => $page->where('escripts.0.status', 'sent'));
    expect(fn () => $exchange->accept($escript, $this->otherPharmacy))->toThrow(HttpException::class);

    $this->actingAs($this->pharmacistUser)->post("http://corner.clinicflow.test/escripts/{$escript->id}/accept")->assertSessionHasNoErrors();
    $this->actingAs($this->pharmacistUser)->post("http://corner.clinicflow.test/escripts/{$escript->id}/dispense")->assertSessionHasNoErrors();
    $this->actingAs($this->pharmacistUser)->post("http://corner.clinicflow.test/escripts/{$escript->id}/dispense")->assertSessionHasErrors('escript');

    expect($escript->fresh()?->status)->toBe('dispensed');
});

it('cancels the earlier e-script when the doctor signs a new version', function (): void {
    $this->doctor = $this->clinic->run(fn () => Staff::query()->findOrFail($this->doctorUser->id));
    $v1 = $this->clinic->run(function () {
        $patient = registerTestPatient('Sipho', '850101', null, '0821112222');
        app(NetworkIdentity::class)->register($patient, $this->clinic);

        return signedScript($this, seenByDoctor($this, $patient, PayerType::Cash), [['Paracetamol', 10]]);
    });
    $old = $this->clinic->run(fn () => app(EscriptExchange::class)->send($v1->fresh(), $this->clinic, $this->pharmacy->id));

    $this->clinic->run(function () use ($v1): void {
        $v2 = app(AmendPrescription::class)->handle($v1->fresh(), 'Pharmacy out of stock');
        app(RequestSigningPin::class)->handle($v2, $this->doctor);
        app(SignPrescription::class)->handle($v2, $this->doctor, app(OtpSender::class)->sent[$this->doctorUser->id]);
    });

    expect($old->fresh()?->status)->toBe('cancelled')
        ->and(fn () => app(EscriptExchange::class)->accept($old->fresh(), $this->pharmacy))->toThrow(ValidationException::class)
        ->and(HubEscript::query()->count())->toBe(1);
});
