<?php

use App\Domains\Identity\Actions\AddStaffMember;
use App\Domains\Identity\Enums\StaffRole;
use App\Domains\Identity\Models\Membership;
use App\Domains\Locums\Actions\LocumMarketplace;
use App\Domains\Platform\Enums\ProviderType;
use App\Domains\Platform\Models\Provider;
use App\Domains\Scheduling\Models\RosterSession;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

uses(DatabaseMigrations::class);

beforeEach(function (): void {
    if (config('database.default') !== 'mysql') {
        $this->markTestSkipped('Tenancy tests require MySQL.');
    }
    $this->clinic = makeProvider('Sunrise Medical Centre', ProviderType::Clinic, 'sunrise.clinicflow.test');
    $this->ownerUser = User::factory()->create();
    app(AddStaffMember::class)->handle($this->clinic, $this->ownerUser, StaffRole::Owner);
    $this->market = app(LocumMarketplace::class);
});

afterEach(function (): void {
    tenancy()->end();
    Provider::query()->get()->each->delete();
});

function verifiedLocum(object $t, string $name, string $indemnityUntil = '+1 year'): array
{
    $user = User::factory()->create(['name' => $name]);
    $id = $t->market->saveProfile($user, ['hpcsa_number' => 'MP'.random_int(1000000, 9999999), 'qualifications' => 'MBChB (Wits)', 'languages' => ['English', 'isiZulu'], 'areas' => ['Soweto'], 'hourly_rate_cents' => 65000, 'bio' => null]);
    $t->market->uploadDocument($id, 'hpcsa', UploadedFile::fake()->create('reg.pdf', 50, 'application/pdf'), now()->addYear()->toDateString());
    $t->market->uploadDocument($id, 'indemnity', UploadedFile::fake()->create('cover.pdf', 50, 'application/pdf'), CarbonImmutable::parse($indemnityUntil)->toDateString());
    $t->market->review($id, true, 1, null);

    return [$user, $id];
}

function postShift(object $t, string $start = 'tomorrow 08:00', string $end = 'tomorrow 16:00', ?int $invite = null): int
{
    return $t->market->postShift($t->clinic, ['title' => 'GP locum', 'starts_at' => (string) CarbonImmutable::parse($start), 'ends_at' => (string) CarbonImmutable::parse($end),
        'rate_cents' => 70000, 'rate_basis' => 'hour', 'requirements' => null, 'branch_id' => null, 'invited_profile_id' => $invite], $t->ownerUser->id);
}

it('verifies a locum only with current registration and indemnity documents, and checks them against the shift date', function (): void {
    $user = User::factory()->create();
    expect(fn () => $this->market->saveProfile($user, ['hpcsa_number' => '12345', 'qualifications' => 'MBChB', 'languages' => ['English'], 'areas' => ['Soweto'], 'hourly_rate_cents' => null, 'bio' => null]))->toThrow(ValidationException::class);
    $id = $this->market->saveProfile($user, ['hpcsa_number' => 'MP 0123456', 'qualifications' => 'MBChB', 'languages' => ['English'], 'areas' => ['Soweto'], 'hourly_rate_cents' => null, 'bio' => null]);
    expect(fn () => $this->market->review($id, true, 1, null))->toThrow(ValidationException::class)
        ->and(fn () => $this->market->uploadDocument($id, 'indemnity', UploadedFile::fake()->create('c.pdf', 10, 'application/pdf'), now()->subDay()->toDateString()))->toThrow(ValidationException::class);

    $this->market->uploadDocument($id, 'hpcsa', UploadedFile::fake()->create('r.pdf', 10, 'application/pdf'), now()->addYear()->toDateString());
    $this->market->uploadDocument($id, 'indemnity', UploadedFile::fake()->create('c.pdf', 10, 'application/pdf'), now()->addMonths(2)->toDateString());
    $this->market->review($id, true, 1, null);

    expect($this->market->eligible($id, CarbonImmutable::now()->addMonth()))->toBeTrue()
        ->and($this->market->eligible($id, CarbonImmutable::now()->addMonths(3)))->toBeFalse()
        ->and(DB::table('locum_profiles')->value('hpcsa_number'))->toBe('MP0123456');
});

it('books one applicant, declines the others, and opens the practice to the locum only around the shift', function (): void {
    [$locum, $profile] = verifiedLocum($this, 'Dr Mokoena');
    [, $other] = verifiedLocum($this, 'Dr Pillay');
    $unverified = User::factory()->create();
    $pending = $this->market->saveProfile($unverified, ['hpcsa_number' => 'MP7654321', 'qualifications' => 'MBChB', 'languages' => ['English'], 'areas' => ['Soweto'], 'hourly_rate_cents' => null, 'bio' => null]);

    $shift = postShift($this);
    expect(fn () => $this->market->apply($shift, $pending, null))->toThrow(ValidationException::class);
    $this->market->apply($shift, $profile, 'Available all day');
    $this->market->apply($shift, $other, null);
    $this->market->accept((int) DB::table('locum_applications')->where('locum_profile_id', $profile)->value('id'), $this->clinic);

    expect(DB::table('locum_shifts')->value('status'))->toBe('filled')
        ->and(DB::table('locum_applications')->where('locum_profile_id', $other)->value('status'))->toBe('declined');
    $membership = Membership::query()->where('user_id', $locum->id)->where('tenant_id', $this->clinic->id)->sole();
    expect($membership->role)->toBe(StaffRole::LocumDoctor)
        ->and($membership->getAttribute('expires_at')?->toDateTimeString())->toBe(CarbonImmutable::parse('tomorrow 16:00')->addHours(12)->toDateTimeString());
    $this->clinic->run(fn () => expect(RosterSession::query()->where('staff_id', $locum->id)->sole()->starts_at->toDateTimeString())->toBe(CarbonImmutable::parse('tomorrow 08:00')->toDateTimeString()));

    $clash = postShift($this, 'tomorrow 12:00', 'tomorrow 18:00');
    expect(fn () => $this->market->apply($clash, $profile, null))->toThrow(ValidationException::class);

    $home = 'http://sunrise.clinicflow.test/workspace';
    $this->actingAs($locum)->get($home)->assertForbidden();
    $this->travelTo(CarbonImmutable::parse('tomorrow 07:30'));
    $this->actingAs($locum)->get($home)->assertOk();
    $this->travelTo(CarbonImmutable::parse('tomorrow 16:00')->addHours(13));
    $this->actingAs($locum)->get($home)->assertForbidden();
});

it('never turns a practice\'s own doctor into a locum and only lets an invited locum take an offered shift', function (): void {
    [$ownDoctor, $profile] = verifiedLocum($this, 'Dr Naidoo');
    app(AddStaffMember::class)->handle($this->clinic, $ownDoctor, StaffRole::Doctor);
    [, $stranger] = verifiedLocum($this, 'Dr Smith');

    $shift = postShift($this, 'tomorrow 08:00', 'tomorrow 12:00', $profile);
    expect(fn () => $this->market->apply($shift, $stranger, null))->toThrow(ValidationException::class);
    $this->market->apply($shift, $profile, null);
    config(['clinicflow.locums.booking_fee_cents' => 20000]);
    $this->market->accept((int) DB::table('locum_applications')->value('id'), $this->clinic);

    $m = Membership::query()->where('user_id', $ownDoctor->id)->where('tenant_id', $this->clinic->id)->sole();
    expect($m->role)->toBe(StaffRole::Doctor)->and($m->getAttribute('expires_at'))->toBeNull()
        ->and(DB::table('locum_fees')->sole()->amount_cents)->toBe(20000);
});

it('serves the locum portal, the practice shifts page and the verification page', function (): void {
    [$locum] = verifiedLocum($this, 'Dr Mokoena');
    postShift($this);
    $admin = User::factory()->create();
    $admin->forceFill(['is_platform_admin' => true])->save();

    $this->actingAs($locum)->get('http://localhost/locum')->assertOk()->assertInertia(fn ($p) => $p->component('Locums/Portal')->where('profile.status', 'verified')->where('shifts.0.practice', 'Sunrise Medical Centre'));
    $this->actingAs($this->ownerUser)->get('http://sunrise.clinicflow.test/locums')->assertOk()->assertInertia(fn ($p) => $p->component('Locums/Practice')->where('locums.0.name', 'Dr Mokoena'));
    $this->actingAs($admin)->get('http://localhost/admin/locums')->assertOk()->assertInertia(fn ($p) => $p->component('Admin/Locums')->has('profiles', 1));
});
