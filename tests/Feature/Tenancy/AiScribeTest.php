<?php

use App\Domains\Identity\Actions\AddStaffMember;
use App\Domains\Identity\Enums\StaffRole;
use App\Domains\Identity\Models\Staff;
use App\Domains\Messaging\Contracts\MessageSender;
use App\Domains\Messaging\Support\LogMessageSender;
use App\Domains\Platform\Enums\ProviderType;
use App\Domains\Platform\Enums\SubscriptionStatus;
use App\Domains\Platform\Models\Package;
use App\Domains\Platform\Models\Provider;
use App\Domains\Platform\Models\Subscription;
use App\Domains\Scribe\Actions\AiScribe;
use App\Domains\Scribe\Models\AiProvider;
use App\Domains\Visits\Enums\PayerType;
use App\Domains\Wallet\Models\Wallet;
use App\Domains\Wallet\Support\WalletSettings;
use App\Models\User;
use Database\Seeders\ClinicalReferenceSeeder;
use Database\Seeders\PackageSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

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
    $this->sub = Subscription::create(['tenant_id' => $this->clinic->id, 'package_id' => Package::query()->where('code', 'clinic-pro')->value('id'),
        'status' => SubscriptionStatus::Active, 'current_period_ends_at' => now()->addMonth(), 'addons' => ['ai_scribe']]);
    $this->doctorUser = User::factory()->create();
    app(AddStaffMember::class)->handle($this->clinic, $this->doctorUser, StaffRole::Doctor);
    AiProvider::query()->create(['driver' => 'deepgram', 'kind' => 'speech', 'enabled' => true, 'credentials' => ['api_key' => 'dg-test']]);
    AiProvider::query()->create(['driver' => 'anthropic', 'kind' => 'notes', 'enabled' => true, 'credentials' => ['api_key' => 'sk-test']]);
    WalletSettings::put('ai.addon_minutes', 10);
    WalletSettings::put('ai.price_per_minute_cents', 150);
    tenancy()->initialize($this->clinic);
    $this->doctor = Staff::query()->findOrFail($this->doctorUser->id);
    $this->consultation = seenByDoctor($this, registerTestPatient('Thandi', '880412'), PayerType::Cash);
    tenancy()->end();
});

afterEach(function (): void {
    tenancy()->end();
    Provider::query()->get()->each->delete();
});

function fakeAi(int $seconds, string $transcript = 'Thandi says she has had a cough for three days. No fever. Plan: rest and fluids.'): void
{
    Http::fake([
        'api.deepgram.com/*' => Http::response(['metadata' => ['duration' => $seconds], 'results' => ['channels' => [['alternatives' => [['transcript' => $transcript]]]]]]),
        'api.anthropic.com/*' => Http::response(['content' => [['type' => 'text', 'text' => json_encode(['history' => 'Cough for three days, no fever.', 'examination' => '[unclear — check]',
            'assessment' => 'Upper respiratory tract infection.', 'plan' => 'Rest and fluids.', 'icd10' => [['code' => 'J06.9', 'description' => 'Acute URTI'], ['code' => 'not-a-code', 'description' => 'x']]])]]]),
    ]);
}

it('uses included minutes first, then charges extra minutes to the wallet, and drafts without the patient\'s name', function (): void {
    $this->clinic->run(function (): void {
        $scribe = app(AiScribe::class);
        Wallet::for($this->clinic->id)->forceFill(['balance_cents' => 5000])->save();
        fakeAi(12 * 60);
        $session = $scribe->start($this->consultation, $this->doctorUser->id, true);
        $scribe->process($session, 'audio-bytes', 'audio/webm', 12 * 60);

        $row = DB::table('scribe_sessions')->find($session);
        expect($row->status)->toBe('drafted')->and($row->minutes_billed)->toBe(12)->and($row->wallet_cents)->toBe(300)
            ->and($scribe->allowance($this->clinic->id)['left'])->toBe(0)
            ->and(Wallet::for($this->clinic->id)->availableCents())->toBe(4700)
            ->and($scribe->draftFor($session)['icd10'])->toBe([['code' => 'J06.9', 'description' => 'Acute URTI']]);
        Http::assertSent(fn (HttpRequest $r) => str_contains($r->url(), 'anthropic') && ! str_contains($r->body(), 'Thandi') && str_contains($r->body(), 'the patient says'));
        Http::assertSent(fn (HttpRequest $r) => str_contains($r->url(), 'deepgram') && str_contains($r->url(), 'model=nova-3-medical'));
    });
});

it('refuses a recording the wallet cannot cover before anything is sent, and charges nothing when transcription fails', function (): void {
    $this->clinic->run(function (): void {
        $scribe = app(AiScribe::class);
        // One fake for the whole test: the speech service is down.
        Http::fake(['api.deepgram.com/*' => Http::response('down', 503), 'api.anthropic.com/*' => Http::response([], 500)]);
        $session = $scribe->start($this->consultation, $this->doctorUser->id, true);
        expect(fn () => $scribe->process($session, 'audio', 'audio/webm', 15 * 60))->toThrow(ValidationException::class);
        Http::assertNothingSent();

        expect(fn () => $scribe->process($session, 'audio', 'audio/webm', 5 * 60))->toThrow(ValidationException::class);
        expect(DB::table('scribe_sessions')->value('status'))->toBe('failed')->and($scribe->allowance($this->clinic->id)['used'])->toBe(0);
        Http::assertSentCount(1);

        expect($scribe->start($this->consultation, $this->doctorUser->id, false))->toBeNull()
            ->and(DB::table('patients')->whereNotNull('ai_scribe_declined_at')->count())->toBe(1);
    });
});

it('charges nothing when no speech is recognised', function (): void {
    $this->clinic->run(function (): void {
        $scribe = app(AiScribe::class);
        fakeAi(4 * 60, '   ');
        $session = $scribe->start($this->consultation, $this->doctorUser->id, true);
        expect(fn () => $scribe->process($session, 'audio', 'audio/webm', 4 * 60))->toThrow(ValidationException::class);
        expect($scribe->allowance($this->clinic->id)['used'])->toBe(0)->and(DB::table('scribe_sessions')->value('status'))->toBe('failed');
        Http::assertNotSent(fn (HttpRequest $r) => str_contains($r->url(), 'anthropic'));
    });
});

it('redrafts from the kept transcript without charging again', function (): void {
    $this->clinic->run(function (): void {
        $scribe = app(AiScribe::class);
        Http::fake(['api.deepgram.com/*' => Http::response(['metadata' => ['duration' => 120], 'results' => ['channels' => [['alternatives' => [['transcript' => 'Cough.']]]]]]),
            'api.anthropic.com/*' => Http::sequence()->push('overloaded', 529)->push(['content' => [['type' => 'text', 'text' => '{"history":"Cough.","examination":"","assessment":"","plan":"","icd10":[]}']]])]);
        $session = $scribe->start($this->consultation, $this->doctorUser->id, true);
        expect(fn () => $scribe->process($session, 'audio', 'audio/webm', 120))->toThrow(ValidationException::class);
        $scribe->draft($session);
        expect(DB::table('scribe_sessions')->value('status'))->toBe('drafted')->and($scribe->allowance($this->clinic->id)['used'])->toBe(2);
        $scribe->close($session, true);
        expect(DB::table('scribe_sessions')->value('status'))->toBe('accepted');
    });
});

it('lets the super admin keep one active provider per kind and set prices, and practices switch the add-on', function (): void {
    $admin = User::factory()->create();
    $admin->forceFill(['is_platform_admin' => true])->save();
    $this->actingAs($admin)->post('http://localhost/admin/ai-scribe/providers/azure', ['enabled' => true])->assertSessionHasErrors('api_key');
    $this->actingAs($admin)->post('http://localhost/admin/ai-scribe/providers/azure', ['api_key' => 'az', 'region' => 'southafricanorth', 'enabled' => true])->assertSessionHasNoErrors();
    expect(AiProvider::active('speech')?->driver)->toBe('azure')->and(AiProvider::query()->where('driver', 'deepgram')->value('enabled'))->toBeFalsy();
    $this->actingAs($admin)->post('http://localhost/admin/ai-scribe/prices', ['monthly' => 599, 'minutes' => 400, 'per_minute' => 1.2, 'max_minutes' => 30])->assertSessionHasNoErrors();
    expect(WalletSettings::get('ai.addon_monthly_cents'))->toBe(59900);

    $owner = User::factory()->create();
    app(AddStaffMember::class)->handle($this->clinic, $owner, StaffRole::Owner);
    $this->actingAs($owner)->post('http://sunrise.clinicflow.test/settings/ai-scribe', ['enabled' => false])->assertSessionHasNoErrors();
    expect(Subscription::query()->find($this->sub->id)->addons)->not->toContain('ai_scribe');
    $this->actingAs($owner)->get('http://sunrise.clinicflow.test/settings/ai-scribe')->assertInertia(fn ($p) => $p->component('Settings/AiScribe')->where('offered', true)->where('on', false));
});

it('waits for the patient to agree on their own screen during a call, and only they can answer', function (): void {
    [$session, $other] = $this->clinic->run(function (): array {
        $scribe = app(AiScribe::class);
        $id = $scribe->request($this->consultation, strtolower((string) Str::ulid()), $this->doctorUser->id, 'call');
        fakeAi(60);
        expect(fn () => $scribe->process($id, 'audio', 'audio/webm', 60))->toThrow(ValidationException::class);
        Http::assertNothingSent();

        return [$id, registerTestPatient('Sipho', '850101', null, '0821112222')->id];
    });
    expect($other)->not->toBeEmpty();

    $this->withSession(['portal_cell' => '0821112222'])->postJson("http://sunrise.clinicflow.test/my/scribe/{$session}/agree")->assertForbidden();
    $this->withSession(['portal_cell' => '0825550147'])->postJson("http://sunrise.clinicflow.test/my/scribe/{$session}/agree")->assertOk()->assertJsonPath('status', 'created');

    $this->clinic->run(function () use ($session): void {
        expect(DB::table('scribe_sessions')->where('id', $session)->value('consent_at'))->not->toBeNull();
        app(AiScribe::class)->process($session, 'audio', 'audio/webm', 60);
        expect(DB::table('scribe_sessions')->where('id', $session)->value('status'))->toBe('drafted');

        $declined = app(AiScribe::class)->request($this->consultation, strtolower((string) Str::ulid()), $this->doctorUser->id, 'call');
        app(AiScribe::class)->answer($declined, $this->consultation->patient_id, false);
        expect(DB::table('scribe_sessions')->where('id', $declined)->value('status'))->toBe('declined')
            ->and(DB::table('patients')->where('id', $this->consultation->patient_id)->value('ai_scribe_declined_at'))->not->toBeNull();
    });
});

it('drafts a chat consult from the messages after the patient agrees, charging one minute', function (): void {
    $this->clinic->run(function (): void {
        $appointment = strtolower((string) Str::ulid());
        $thread = DB::table('chat_threads')->insertGetId(['appointment_id' => null, 'patient_id' => $this->consultation->patient_id, 'doctor_staff_id' => $this->doctorUser->id,
            'kind' => 'consult', 'opens_at' => now()->subHour(), 'closes_at' => now()->addHour(), 'created_at' => now(), 'updated_at' => now()]);
        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        DB::table('chat_threads')->where('id', $thread)->update(['appointment_id' => $appointment]);
        DB::statement('SET FOREIGN_KEY_CHECKS=1');
        foreach ([['patient', 'Hi doctor, Thandi here. I have had a sore throat for two days.'], ['doctor', 'Any fever?'], ['patient', 'No fever.']] as [$sender, $body]) {
            DB::table('chat_messages')->insert(['chat_thread_id' => $thread, 'sender' => $sender, 'body' => $body, 'created_at' => now()]);
        }
        $scribe = app(AiScribe::class);
        $session = $scribe->request($this->consultation, $appointment, $this->doctorUser->id, 'chat');
        fakeAi(60);
        expect(fn () => $scribe->fromChat($session))->toThrow(ValidationException::class);
        $scribe->answer($session, $this->consultation->patient_id, true);
        $scribe->fromChat($session);

        expect(DB::table('scribe_sessions')->where('id', $session)->value('status'))->toBe('drafted')
            ->and($scribe->allowance($this->clinic->id)['used'])->toBe(1);
        Http::assertSent(fn (HttpRequest $r) => str_contains($r->url(), 'anthropic') && str_contains($r->body(), 'sore throat') && ! str_contains($r->body(), 'Thandi'));
        Http::assertNotSent(fn (HttpRequest $r) => str_contains($r->url(), 'deepgram'));
    });
});

it('deletes transcripts and drafts after 30 days', function (): void {
    $this->clinic->run(function (): void {
        fakeAi(60);
        $scribe = app(AiScribe::class);
        $session = $scribe->start($this->consultation, $this->doctorUser->id, true);
        $scribe->process($session, 'audio', 'audio/webm', 60);
        DB::table('scribe_sessions')->where('id', $session)->update(['created_at' => now()->subDays(31)]);
    });
    $this->artisan('scribe:purge')->assertSuccessful();
    $this->clinic->run(fn () => expect(DB::table('scribe_sessions')->whereNotNull('transcript')->orWhereNotNull('draft')->count())->toBe(0));
});
