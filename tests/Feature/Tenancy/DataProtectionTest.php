<?php

use App\Domains\Identity\Actions\AddStaffMember;
use App\Domains\Identity\Contracts\OtpSender;
use App\Domains\Identity\Enums\StaffRole;
use App\Domains\Messaging\Contracts\MessageSender;
use App\Domains\Platform\Enums\ProviderType;
use App\Domains\Platform\Models\Package;
use App\Domains\Platform\Models\Provider;
use App\Http\Middleware\RequireRecentConfirmation;
use App\Models\User;
use Database\Seeders\ClinicalReferenceSeeder;
use Database\Seeders\PackageSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

uses(DatabaseMigrations::class);

beforeEach(function (): void {
    if (config('database.default') !== 'mysql') {
        $this->markTestSkipped('Tenancy tests require MySQL.');
    }
    $this->seed(ClinicalReferenceSeeder::class);
    $this->seed(PackageSeeder::class);
    $this->app->instance(OtpSender::class, new class implements OtpSender
    {
        public function send(User $user, string $code): void {}
    });
    $this->outbox = new class implements MessageSender
    {
        /** @var list<array{0: string, 1: ?string, 2: string}> */
        public array $sent = [];

        public function send(string $channel, string $recipient, ?string $subject, string $body): bool
        {
            $this->sent[] = [$recipient, $subject, $body];

            return true;
        }
    };
    $this->app->instance(MessageSender::class, $this->outbox);
    $this->clinic = makeProvider('Sunrise Medical Centre', ProviderType::Clinic, 'sunrise.clinicflow.test');
    $this->owner = User::factory()->create(['email' => 'owner@sunrise.test']);
    $this->doctorUser = User::factory()->create(['name' => 'Dr Mokoena', 'email' => 'doctor@sunrise.test', 'password' => 'correct-horse-battery']);
    app(AddStaffMember::class)->handle($this->clinic, $this->owner, StaffRole::Owner);
    app(AddStaffMember::class)->handle($this->clinic, $this->doctorUser, StaffRole::Doctor);
});

afterEach(function (): void {
    tenancy()->end();
    Provider::query()->get()->each->delete();
});

it('locks an account after 10 wrong passwords from any addresses, emails the user once, and unlocks after 15 minutes', function (): void {
    foreach (range(1, 11) as $i) {
        $this->withServerVariables(['REMOTE_ADDR' => "203.0.113.{$i}"])
            ->post('http://localhost/login', ['login' => 'doctor@sunrise.test', 'password' => "guess-{$i}"])->assertSessionHasErrors('login');
    }
    $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.7'])->post('http://localhost/login', ['login' => 'doctor@sunrise.test', 'password' => 'correct-horse-battery'])
        ->assertSessionHasErrors(['login' => 'Too many failed sign-ins. This account is locked for 15 minutes.']);
    expect(collect($this->outbox->sent)->where(1, 'Your account was locked after failed sign-ins')->count())->toBe(1);

    $this->travel(16)->minutes();
    $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.8'])->post('http://localhost/login', ['login' => 'doctor@sunrise.test', 'password' => 'correct-horse-battery'])
        ->assertRedirect('/login/verify');
});

it('alerts the owners once when someone opens unusually many patient records, and refuses past the hard limit', function (): void {
    config(['clinicflow.security.record_views_alert' => 3, 'clinicflow.security.record_views_hard_limit' => 5]);
    $patients = $this->clinic->run(fn () => collect(range(1, 6))->map(fn ($i) => registerTestPatient("Patient{$i}", '8804'.(10 + $i), null, '08200000'.str_pad((string) $i, 2, '0', STR_PAD_LEFT))->id)->all());
    $open = fn (string $id) => $this->actingAs($this->doctorUser)->get("http://sunrise.clinicflow.test/patients/{$id}/care");

    $open($patients[0])->assertOk();
    $open($patients[1])->assertOk();
    $open($patients[0])->assertOk();
    expect(collect($this->outbox->sent)->where(1, 'Clinic Flow security alert'))->toHaveCount(0);
    $open($patients[2])->assertOk();
    $open($patients[3])->assertOk();
    $alerts = collect($this->outbox->sent)->where(1, 'Clinic Flow security alert');
    expect($alerts)->toHaveCount(1)->and($alerts->first()[0])->toBe('owner@sunrise.test')->and($alerts->first()[2])->toContain('Dr Mokoena opened 3 different patient records');

    $open($patients[4])->assertOk();
    $open($patients[5])->assertStatus(429);
    $open($patients[0])->assertOk();
    $this->clinic->run(fn () => expect(DB::table('record_views')->where('staff_id', $this->doctorUser->id)->distinct()->count('patient_id'))->toBe(5));
});

it('alerts the owners about every patient data export', function (): void {
    $patient = $this->clinic->run(fn () => registerTestPatient('Thandi', '880412')->id);
    $this->actingAs($this->owner)->withSession([RequireRecentConfirmation::SESSION_KEY => now()->getTimestamp()])
        ->get("http://sunrise.clinicflow.test/compliance/patients/{$patient}/export")->assertOk();
    expect(collect($this->outbox->sent)->where(1, 'Clinic Flow security alert')->pluck(2)->implode(' '))->toContain('exported a patient');
});

it('limits patient searches per person', function (): void {
    foreach (range(1, 60) as $i) {
        $this->actingAs($this->doctorUser)->get('http://sunrise.clinicflow.test/patients?q=thandi')->assertOk();
    }
    $this->actingAs($this->doctorUser)->get('http://sunrise.clinicflow.test/patients?q=thandi')->assertStatus(429);
});

it('refuses a password known from data breaches at sign-up', function (): void {
    config(['clinicflow.security.check_leaked_passwords' => true]);
    $hash = strtoupper(sha1('sunrise-2026'));
    Http::fake(['api.pwnedpasswords.com/range/'.substr($hash, 0, 5) => Http::response(substr($hash, 5).":4521\r\n0000000000000000000000000000000000A:1")]);
    $this->post('http://localhost/start', [
        'name' => 'Sunrise Medical Centre', 'type' => 'clinic', 'subdomain' => 'sunrise2', 'package_id' => Package::query()->where('code', 'clinic-standard')->value('id'),
        'owner_name' => 'Dr Sizwe Mthembu', 'owner_email' => 'sizwe@sunrise.test', 'owner_phone' => '0827001122', 'password' => 'sunrise-2026', 'password_confirmation' => 'sunrise-2026',
        'references' => ['bhf_practice_number' => '0123456', 'owner_hpcsa' => 'MP0654321'], 'accept_terms' => true,
    ])->assertSessionHasErrors('password');
});
