<?php

use App\Domains\Patients\Models\Patient;
use App\Domains\Platform\Enums\ProviderType;
use App\Domains\Platform\Models\Provider;
use App\Domains\Scribe\Models\AiProvider;
use App\Models\User;
use Database\Seeders\ClinicalReferenceSeeder;
use Illuminate\Encryption\Encrypter;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(DatabaseMigrations::class);

beforeEach(function (): void {
    if (config('database.default') !== 'mysql') {
        $this->markTestSkipped('Tenancy tests require MySQL.');
    }
    $this->seed(ClinicalReferenceSeeder::class);
    $this->clinic = makeProvider('Sunrise Medical Centre', ProviderType::Clinic, 'sunrise.clinicflow.test');
});

afterEach(function (): void {
    tenancy()->end();
    Provider::query()->get()->each->delete();
});

/** Switches the application's encryption keys (as a deployment would). */
function useKeys(string $key, array $previous = []): void
{
    config(['app.key' => $key, 'app.previous_keys' => $previous]);
    app()->forgetInstance('encrypter');
    Crypt::clearResolvedInstance('encrypter');
}

it('rotates the encryption key without downtime and re-encrypts everything under the new key', function (): void {
    $old = 'base64:'.base64_encode(random_bytes(32));
    $new = 'base64:'.base64_encode(random_bytes(32));
    useKeys($old);
    $patientId = $this->clinic->run(fn () => registerTestPatient('Thandi', '880412')->id);
    AiProvider::query()->create(['driver' => 'anthropic', 'kind' => 'notes', 'enabled' => true, 'credentials' => ['api_key' => 'sk-secret']]);
    $user = User::factory()->create();
    DB::table('users')->where('id', $user->id)->update(['totp_secret' => Crypt::encryptString('JBSWY3DPEHPK3PXP')]);

    useKeys($new, [$old]);
    expect($this->clinic->run(fn () => Patient::query()->findOrFail($patientId)->id_number))->not->toBeNull()
        ->and(AiProvider::query()->firstOrFail()->credentials['api_key'])->toBe('sk-secret');

    // Each value written under the old key is counted once, then re-encrypted once.
    $this->artisan('security:reencrypt', ['--dry-run' => true])->expectsOutputToContain('Checked 3 encrypted value(s); 3 would be re-encrypted')->assertSuccessful();
    $this->artisan('security:reencrypt')->expectsOutputToContain('Checked 3 encrypted value(s); 3 re-encrypted')->assertSuccessful();
    $this->artisan('security:reencrypt', ['--dry-run' => true])->expectsOutputToContain('Checked 3 encrypted value(s); 0 would be re-encrypted')->assertSuccessful();

    useKeys($new);
    expect($this->clinic->run(fn () => Patient::query()->findOrFail($patientId)->id_number))->not->toBeNull()
        ->and(AiProvider::query()->firstOrFail()->credentials['api_key'])->toBe('sk-secret')
        ->and(User::query()->findOrFail($user->id)->totp_secret)->toBe('JBSWY3DPEHPK3PXP');

    // Something encrypted with a key that is no longer configured is reported, and the command fails.
    $stray = new Encrypter(random_bytes(32), 'AES-256-CBC');
    DB::table('users')->where('id', $user->id)->update(['totp_secret' => $stray->encryptString('x')]);
    $this->artisan('security:reencrypt')->expectsOutputToContain('1 unreadable')->assertFailed();
});

it('removes operational logs past their retention period and keeps everything else', function (): void {
    $user = User::factory()->create();
    $central = DB::connection((string) config('tenancy.database.central_connection'));
    foreach ([[now()->subDays(400), 'old'], [now()->subDays(10), 'new']] as [$at, $tag]) {
        $central->table('login_events')->insert(['user_id' => $user->id, 'ip' => $tag, 'device' => str_repeat('a', 64), 'created_at' => $at]);
    }
    $central->table('login_challenges')->insert(['id' => strtolower((string) Str::ulid()), 'user_id' => $user->id, 'code_hash' => '-', 'expires_at' => now()->subDays(9), 'created_at' => now()->subDays(9), 'updated_at' => now()->subDays(9)]);
    $central->table('trusted_devices')->insert(['user_id' => $user->id, 'token_hash' => str_repeat('b', 64), 'expires_at' => now()->subDay(), 'created_at' => now()->subDays(31)]);
    $this->clinic->run(function (): void {
        $patient = registerTestPatient('Thandi', '880412');
        DB::table('record_views')->insert([['staff_id' => 1, 'patient_id' => $patient->id, 'route' => 'care.show', 'created_at' => now()->subDays(400)],
            ['staff_id' => 1, 'patient_id' => $patient->id, 'route' => 'care.show', 'created_at' => now()->subDays(3)]]);
        DB::table('message_log')->insert([['channel' => 'sms', 'recipient' => '0821234567', 'body' => 'old', 'status' => 'sent', 'units' => 1, 'sent_at' => now()->subDays(800)],
            ['channel' => 'sms', 'recipient' => '0821234567', 'body' => 'new', 'status' => 'sent', 'units' => 1, 'sent_at' => now()->subDays(5)]]);
    });

    $this->artisan('data:prune', ['--dry-run' => true])->assertSuccessful();
    expect($central->table('login_events')->count())->toBe(2);
    $this->artisan('data:prune')->assertSuccessful();

    expect($central->table('login_events')->pluck('ip')->all())->toBe(['new'])
        ->and($central->table('login_challenges')->count())->toBe(0)->and($central->table('trusted_devices')->count())->toBe(0);
    $this->clinic->run(fn () => expect(DB::table('record_views')->count())->toBe(1)->and(DB::table('message_log')->pluck('body')->all())->toBe(['new'])
        ->and(DB::table('patients')->count())->toBe(1));
});
