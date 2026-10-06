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
use App\Domains\Platform\Storage\FileStore;
use App\Domains\Scribe\Actions\AiScribe;
use App\Domains\Scribe\Jobs\ProcessScribeAudio;
use App\Domains\Scribe\Models\AiProvider;
use App\Domains\Visits\Enums\PayerType;
use App\Domains\Wallet\Support\WalletSettings;
use App\Models\User;
use Database\Seeders\ClinicalReferenceSeeder;
use Database\Seeders\PackageSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
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

function fakeBackgroundAi(bool $speechWorks = true): void
{
    Http::fake([
        'api.deepgram.com/*' => $speechWorks ? Http::response(['metadata' => ['duration' => 120], 'results' => ['channels' => [['alternatives' => [['transcript' => 'Cough for three days. No fever.']]]]]]) : Http::response('down', 503),
        'api.anthropic.com/*' => Http::response(['content' => [['type' => 'text', 'text' => '{"history":"Cough for three days.","examination":"","assessment":"","plan":"Rest.","icd10":[]}']]]),
    ]);
}

it('checks everything at upload, then transcribes in the background with the audio encrypted until then', function (): void {
    config(['queue.default' => 'redis']);
    Queue::fake();
    fakeBackgroundAi();
    $session = $this->clinic->run(function (): string {
        $scribe = app(AiScribe::class);
        $id = (string) $scribe->start($this->consultation, $this->doctorUser->id, true);
        $scribe->queueAudio($id, 'RAW-AUDIO-BYTES', 'audio/webm', 120);
        $stored = (string) Storage::disk(FileStore::DISK)->get(AiScribe::audioPath($id));
        expect(DB::table('scribe_sessions')->value('status'))->toBe('processing')
            ->and($stored)->not->toBe('')->not->toContain('RAW-AUDIO-BYTES')->not->toContain(base64_encode('RAW-AUDIO-BYTES'));

        return $id;
    });
    Http::assertNothingSent();
    Queue::assertPushedOn('ai', ProcessScribeAudio::class);

    $job = Queue::pushed(ProcessScribeAudio::class)->first();
    $this->clinic->run(function () use ($job, $session): void {
        $job->handle(app(AiScribe::class));
        expect(DB::table('scribe_sessions')->value('status'))->toBe('drafted')
            ->and(Storage::disk(FileStore::DISK)->exists(AiScribe::audioPath($session)))->toBeFalse()
            ->and(app(AiScribe::class)->allowance($this->clinic->id)['used'])->toBe(2);
    });
});

it('refuses unaffordable recordings at upload, and charges nothing when background transcription fails', function (): void {
    config(['queue.default' => 'redis']);
    Queue::fake();
    $this->clinic->run(function (): void {
        $scribe = app(AiScribe::class);
        $id = (string) $scribe->start($this->consultation, $this->doctorUser->id, true);
        expect(fn () => $scribe->queueAudio($id, 'audio', 'audio/webm', 25 * 60))->toThrow(ValidationException::class);
        expect(Storage::disk(FileStore::DISK)->exists(AiScribe::audioPath($id)))->toBeFalse();
    });
    Queue::assertNothingPushed();

    fakeBackgroundAi(false);
    $this->clinic->run(function (): void {
        $scribe = app(AiScribe::class);
        $id = (string) $scribe->start($this->consultation, $this->doctorUser->id, true);
        $scribe->queueAudio($id, 'audio', 'audio/webm', 120);
        Queue::pushed(ProcessScribeAudio::class)->first()->handle($scribe);
        expect(DB::table('scribe_sessions')->where('id', $id)->value('status'))->toBe('failed')
            ->and($scribe->allowance($this->clinic->id)['used'])->toBe(0)
            ->and(Storage::disk(FileStore::DISK)->exists(AiScribe::audioPath($id)))->toBeFalse();
    });
});

it('deletes leftover audio older than an hour', function (): void {
    $this->clinic->run(function (): void {
        $disk = Storage::disk(FileStore::DISK);
        $disk->put('scribe-audio/old.enc', 'x');
        $disk->put('scribe-audio/new.enc', 'y');
        touch($disk->path('scribe-audio/old.enc'), now()->subHours(2)->getTimestamp());
    });
    $this->artisan('scribe:purge')->assertSuccessful();
    $this->clinic->run(fn () => expect(Storage::disk(FileStore::DISK)->exists('scribe-audio/old.enc'))->toBeFalse()
        ->and(Storage::disk(FileStore::DISK)->exists('scribe-audio/new.enc'))->toBeTrue());
});
