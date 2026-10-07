<?php

use App\Domains\Platform\Enums\ProviderType;
use App\Domains\Platform\Models\Provider;
use App\Domains\Telemedicine\Events\CallStateChanged;
use App\Domains\Visits\Actions\DeviceTokens;
use App\Domains\Visits\Events\VisitStageChanged;
use Database\Seeders\ClinicalReferenceSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Testing\TestResponse;

uses(DatabaseMigrations::class);

beforeEach(function (): void {
    if (config('database.default') !== 'mysql') {
        $this->markTestSkipped('Tenancy tests require MySQL.');
    }
    $this->seed(ClinicalReferenceSeeder::class);
    config(['broadcasting.default' => 'reverb', 'broadcasting.connections.reverb.key' => 'test-key', 'broadcasting.connections.reverb.secret' => 'test-secret']);
    $this->clinic = makeProvider('Sunrise Medical Centre', ProviderType::Clinic, 'sunrise.clinicflow.test');
    [$this->mine, $this->theirs] = $this->clinic->run(fn () => [registerTestPatient('Thandi', '880412')->id, registerTestPatient('Sipho', '850101', null, '0821112222')->id]);
    $this->base = 'http://sunrise.clinicflow.test';
});

afterEach(function (): void {
    tenancy()->end();
    Provider::query()->get()->each->delete();
});

function portalAuth(object $t, string $channel): TestResponse
{
    return $t->withSession(['portal_cell' => '0825550147'])->postJson("{$t->base}/my/broadcasting/auth", ['socket_id' => '1234.5678', 'channel_name' => 'private-provider.'.$t->clinic->id.'.'.$channel]);
}

it('lets patients listen only to their own channels', function (): void {
    $own = portalAuth($this, "patient.{$this->mine}")->assertOk();
    $channel = 'private-provider.'.$this->clinic->id.".patient.{$this->mine}";
    expect($own->json('auth'))->toBe('test-key:'.hash_hmac('sha256', '1234.5678:'.$channel, 'test-secret'));
    portalAuth($this, "patient.{$this->theirs}")->assertForbidden();
    portalAuth($this, 'queue')->assertForbidden();

    $this->clinic->run(function (): void {
        DB::table('chat_threads')->insert([['id' => 7, 'patient_id' => $this->mine, 'doctor_staff_id' => 1, 'kind' => 'consult', 'opens_at' => now(), 'closes_at' => now()->addHour(), 'created_at' => now(), 'updated_at' => now()],
            ['id' => 8, 'patient_id' => $this->theirs, 'doctor_staff_id' => 1, 'kind' => 'consult', 'opens_at' => now(), 'closes_at' => now()->addHour(), 'created_at' => now(), 'updated_at' => now()]]);
    });
    portalAuth($this, 'chat.7')->assertOk();
    portalAuth($this, 'chat.8')->assertForbidden();

    $this->flushSession();
    $status = $this->postJson("{$this->base}/my/broadcasting/auth", ['socket_id' => '1234.5678', 'channel_name' => "private-provider.{$this->clinic->id}.patient.{$this->mine}"])->status();
    expect($status)->toBeIn([302, 401, 403]);
});

it('lets the paired waiting-room display listen only to the queue', function (): void {
    $token = $this->clinic->run(fn () => app(DeviceTokens::class)->ensure()['display']);
    $auth = fn (string $tok, string $channel) => $this->postJson("{$this->base}/display/{$tok}/broadcasting/auth", ['socket_id' => '1.2', 'channel_name' => 'private-provider.'.$this->clinic->id.'.'.$channel]);
    $auth($token, 'queue')->assertOk();
    $auth($token, "patient.{$this->mine}")->assertForbidden();
    $auth('not-a-real-token', 'queue')->assertNotFound();
});

it('sends queue changes to the patient\'s own channel, and scribe changes to the call', function (): void {
    $event = new VisitStageChanged($this->clinic->id, 'v1', 'A001', 'doctor', $this->mine);
    expect(array_map(fn ($c) => $c->name, $event->broadcastOn()))->toBe(["private-provider.{$this->clinic->id}.queue", "private-provider.{$this->clinic->id}.patient.{$this->mine}"]);

    Event::fake([CallStateChanged::class]);
    $this->clinic->run(function (): void {
        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        DB::table('scribe_sessions')->insert(['id' => 'sess1', 'consultation_id' => 'c1', 'patient_id' => $this->mine, 'staff_id' => 1, 'appointment_id' => '01appointmentid0000000000a',
            'source' => 'call', 'status' => 'awaiting', 'created_at' => now(), 'updated_at' => now()]);
        DB::statement('SET FOREIGN_KEY_CHECKS=1');
        CallStateChanged::forScribeSession('sess1');
    });
    Event::assertDispatched(CallStateChanged::class, fn (CallStateChanged $e) => $e->appointmentId === '01appointmentid0000000000a' && $e->broadcastAs() === 'call.changed');
});
