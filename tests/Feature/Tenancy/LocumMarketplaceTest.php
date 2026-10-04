<?php

use App\Domains\Identity\Actions\AddStaffMember;
use App\Domains\Identity\Enums\StaffRole;
use App\Domains\Identity\Models\Membership;
use App\Domains\Locums\Actions\LocumMarketplace;
use App\Domains\Locums\Actions\LocumShiftLifecycle;
use App\Domains\Platform\Enums\ProviderType;
use App\Domains\Platform\Models\Provider;
use App\Domains\Scheduling\Models\RosterSession;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
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

/** Platform-database queries, whichever practice the test last visited. */
function locumDb(): ConnectionInterface
{
    return DB::connection((string) config('tenancy.database.central_connection'));
}

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
        ->and(locumDb()->table('locum_profiles')->value('hpcsa_number'))->toBe('MP0123456');
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
    $this->market->accept((int) locumDb()->table('locum_applications')->where('locum_profile_id', $profile)->value('id'), $this->clinic);

    expect(locumDb()->table('locum_shifts')->value('status'))->toBe('filled')
        ->and(locumDb()->table('locum_applications')->where('locum_profile_id', $other)->value('status'))->toBe('declined');
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
    $this->market->accept((int) locumDb()->table('locum_applications')->value('id'), $this->clinic);

    $m = Membership::query()->where('user_id', $ownDoctor->id)->where('tenant_id', $this->clinic->id)->sole();
    expect($m->role)->toBe(StaffRole::Doctor)->and($m->getAttribute('expires_at'))->toBeNull()
        ->and(locumDb()->table('locum_fees')->sole()->amount_cents)->toBe(20000);
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

function bookShift(object $t, int $profile, string $start, string $end, ?string $area = null): int
{
    $id = $t->market->postShift($t->clinic, ['title' => 'GP locum', 'area' => $area, 'starts_at' => (string) CarbonImmutable::parse($start), 'ends_at' => (string) CarbonImmutable::parse($end),
        'rate_cents' => 70000, 'rate_basis' => 'hour', 'requirements' => null, 'branch_id' => null, 'invited_profile_id' => null], $t->ownerUser->id);
    $t->market->apply($id, $profile, null);
    $t->market->accept((int) locumDb()->table('locum_applications')->where('locum_shift_id', $id)->value('id'), $t->clinic);

    return $id;
}

it('takes hours from the locum, lets the practice confirm or adjust them with a reason, and issues the shift invoice', function (): void {
    [$locum, $profile] = verifiedLocum($this, 'Dr Mokoena');
    $life = app(LocumShiftLifecycle::class);
    $shift = bookShift($this, $profile, '+3 days 08:00', '+3 days 16:00');

    expect(fn () => $life->submitHours($shift, $profile, (string) CarbonImmutable::parse('+3 days 08:00'), (string) CarbonImmutable::parse('+3 days 16:30'), 30))->toThrow(ValidationException::class);
    $this->travelTo(CarbonImmutable::parse('+3 days 17:00'));
    $this->actingAs($locum)->post("http://localhost/locum/shifts/{$shift}/hours", ['start' => (string) now()->setTime(8, 0), 'end' => (string) now()->setTime(16, 30), 'break_minutes' => 30])->assertSessionHasNoErrors();
    expect(locumDb()->table('locum_shifts')->where('id', $shift)->value('hours_status'))->toBe('submitted');

    expect(fn () => $life->confirmHours($shift, $this->clinic, (string) now()->setTime(8, 0), (string) now()->setTime(16, 0), 30, null))->toThrow(ValidationException::class);
    locumDb()->table('locum_profiles')->where('id', $profile)->update(['vat_number' => '4123456789']);
    $life->confirmHours($shift, $this->clinic, (string) now()->setTime(8, 0), (string) now()->setTime(16, 0), 30, 'Left at 16:00');
    $row = locumDb()->table('locum_shifts')->where('id', $shift)->first();
    expect($row->hours_status)->toBe('adjusted')->and($row->invoice_number)->toBe('LOC-'.now()->format('Y').'-000001')
        ->and($row->invoice_total_cents)->toBe((int) round(525000 * 1.15));

    $this->actingAs($this->ownerUser)->get("http://sunrise.clinicflow.test/locums/shifts/{$shift}/invoice")->assertOk()->assertHeader('Content-Type', 'application/pdf');
    $this->actingAs($this->ownerUser)->post("http://sunrise.clinicflow.test/locums/shifts/{$shift}/paid")->assertSessionHasNoErrors();
    $this->actingAs($this->ownerUser)->post("http://sunrise.clinicflow.test/locums/shifts/{$shift}/rebook", ['again' => true])->assertSessionHasNoErrors();
    expect(locumDb()->table('locum_shifts')->where('id', $shift)->value('invoice_paid_at'))->not->toBeNull()
        ->and((bool) locumDb()->table('locum_shifts')->where('id', $shift)->value('rebook'))->toBeTrue();
});

it('removes access when a booked shift is cancelled and records late cancellations', function (): void {
    [$locum, $profile] = verifiedLocum($this, 'Dr Mokoena');
    $life = app(LocumShiftLifecycle::class);
    $early = bookShift($this, $profile, '+3 days 08:00', '+3 days 12:00');
    $soon = bookShift($this, $profile, '+10 hours', '+14 hours');

    $life->cancelBooked($early, 'practice', 'Doctor back from leave');
    expect((bool) locumDb()->table('locum_shifts')->where('id', $early)->value('late_cancellation'))->toBeFalse();
    $this->clinic->run(fn () => expect(RosterSession::query()->where('staff_id', $locum->id)->count())->toBe(1));
    expect(Membership::query()->where('user_id', $locum->id)->sole()->getAttribute('expires_at')->isFuture())->toBeTrue();

    $this->actingAs($locum)->post("http://localhost/locum/shifts/{$soon}/cancel", ['reason' => 'Family emergency'])->assertSessionHasNoErrors();
    expect((bool) locumDb()->table('locum_shifts')->where('id', $soon)->value('late_cancellation'))->toBeTrue()
        ->and(locumDb()->table('locum_shifts')->where('id', $soon)->value('cancelled_by'))->toBe('locum')
        ->and(Membership::query()->where('user_id', $locum->id)->sole()->getAttribute('expires_at')->isFuture())->toBeFalse();
    $this->clinic->run(fn () => expect(RosterSession::query()->where('staff_id', $locum->id)->count())->toBe(0));
});

it('alerts verified locums in the shift area once, and reminds both sides the day before', function (): void {
    [, $soweto] = verifiedLocum($this, 'Dr Mokoena');
    [, $durban] = verifiedLocum($this, 'Dr Naidoo');
    locumDb()->table('locum_profiles')->where('id', $durban)->update(['areas' => json_encode(['Durban'])]);
    $life = app(LocumShiftLifecycle::class);

    $open = $this->market->postShift($this->clinic, ['title' => 'GP locum', 'area' => 'soweto', 'starts_at' => (string) CarbonImmutable::parse('+5 days 08:00'), 'ends_at' => (string) CarbonImmutable::parse('+5 days 16:00'),
        'rate_cents' => 70000, 'rate_basis' => 'hour', 'requirements' => null, 'branch_id' => null, 'invited_profile_id' => null], $this->ownerUser->id);
    expect($life->alert($open))->toBe(1)->and($life->alert($open))->toBe(0)
        ->and(locumDb()->table('locum_alerts')->where('locum_profile_id', $durban)->exists())->toBeFalse();

    bookShift($this, $soweto, '+20 hours', '+28 hours');
    expect($life->remind())->toBe(1)->and($life->remind())->toBe(0);
});
