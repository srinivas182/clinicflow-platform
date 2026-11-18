<?php

use App\Domains\Finance\Accounting\PlatformAccountingConnection;
use App\Domains\Identity\Actions\AddStaffMember;
use App\Domains\Identity\Enums\StaffRole;
use App\Domains\Platform\Enums\ProviderType;
use App\Domains\Platform\Enums\SubscriptionStatus;
use App\Domains\Platform\Models\Package;
use App\Domains\Platform\Models\Provider;
use App\Domains\Platform\Models\Subscription;
use App\Domains\Platform\Notifications\PracticeNotice;
use App\Domains\Platform\Resellers\ResellerProgramme;
use App\Models\User;
use Database\Seeders\PackageSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/*
 * Beta testing T4a: billing admin (tracker rows 19, 20, 78, 122, 124). Auto-debit (33, 34) and saving
 * a card at any time (rows 2, 12) are in AutoDebitTest.
 */

uses(DatabaseMigrations::class);

beforeEach(function (): void {
    if (config('database.default') !== 'mysql') {
        $this->markTestSkipped('Tenancy tests require MySQL.');
    }
    Notification::fake();
    $this->seed(PackageSeeder::class);
    $this->admin = User::factory()->create();
    $this->admin->forceFill(['is_platform_admin' => true])->save();
    $this->clinic = makeProvider('Sunrise Medical Centre', ProviderType::Clinic, 'sunrise.clinicflow.test');
    $this->owner = User::factory()->create(['email' => 'owner@sunrise.test']);
    app(AddStaffMember::class)->handle($this->clinic, $this->owner, StaffRole::Owner);
});

afterEach(function (): void {
    tenancy()->end();
    Provider::query()->get()->each->delete();
});

it('adds a package switched off, and edits what one includes, telling practices on it what they lose (beta #19, #20)', function (): void {
    $limits = ['doctors' => 5, 'branches' => 2, 'storage_gb' => 50];
    $this->actingAs($this->admin)->post('http://localhost/admin/packages', ['code' => 'Clinic Plus', 'name' => 'Clinic Plus', 'provider_type' => 'clinic', 'price_monthly' => 1999, 'trial_days' => 14, 'limits' => $limits])
        ->assertSessionHasErrors('code');
    $this->actingAs($this->admin)->post('http://localhost/admin/packages', ['code' => 'clinic-plus', 'name' => 'Clinic Plus', 'provider_type' => 'clinic', 'price_monthly' => 1999, 'trial_days' => 14,
        'limits' => $limits, 'features' => ['front_desk', 'consults', 'nope']])->assertSessionHasErrors('features.2');
    $this->actingAs($this->admin)->post('http://localhost/admin/packages', ['code' => 'clinic-plus', 'name' => 'Clinic Plus', 'provider_type' => 'clinic', 'price_monthly' => 1999, 'trial_days' => 14,
        'limits' => $limits, 'features' => ['front_desk', 'consults'], 'addons' => ['whatsapp']])->assertSessionHasNoErrors();
    $plus = Package::query()->where('code', 'clinic-plus')->sole();
    expect($plus->is_active)->toBeFalse()->and($plus->price_monthly_cents)->toBe(199900)->and($plus->limits['doctors'])->toBe(5)->and($plus->limits['sms'])->toBe(0);

    $starter = Package::query()->where('code', 'clinic-starter')->sole();
    Subscription::create(['tenant_id' => $this->clinic->id, 'package_id' => $starter->id, 'status' => SubscriptionStatus::Active]);
    $this->actingAs($this->admin)->put("http://localhost/admin/packages/{$starter->id}/details", ['name' => 'Clinic Starter', 'summary' => 'Up to 2 doctors',
        'limits' => ['doctors' => 2, 'branches' => 1, 'storage_gb' => 25], 'features' => array_values(array_diff($starter->features, ['claims'])), 'addons' => $starter->addons])->assertSessionHasNoErrors();
    expect($starter->fresh()?->limits['doctors'])->toBe(2)->and($starter->fresh()?->limits['sms'])->toBe($starter->limits['sms']);
    Notification::assertSentTo($this->owner, PracticeNotice::class, fn (PracticeNotice $n) => $n->kind === 'package_changed'
        && in_array('No longer included: Medical aid claims.', $n->lines, true) && in_array('Doctors: 3 → 2.', $n->lines, true));
});

it('switches the platform books on or off with a schedule (beta #78)', function (): void {
    $conn = PlatformAccountingConnection::create(['driver' => 'xero', 'enabled' => false, 'auto_export' => 'daily', 'access_token' => 't', 'token_expires_at' => now()->addHour()]);
    $this->actingAs($this->admin)->put('http://localhost/admin/accounting/xero/platform', ['enabled' => true, 'auto_export' => 'hourly', 'account_map' => ['bank' => '090']])->assertSessionHasNoErrors();
    expect($conn->fresh()?->enabled)->toBeTrue()->and($conn->fresh()?->auto_export)->toBe('hourly');
    $this->actingAs($this->admin)->put('http://localhost/admin/accounting/xero/platform', ['enabled' => false, 'auto_export' => 'off'])->assertSessionHasNoErrors();
    expect($conn->fresh()?->enabled)->toBeFalse();
});

it('changes commission for new referrals only, switches resellers off and links their sign-in (beta #122, #124)', function (): void {
    $programme = app(ResellerProgramme::class);
    $id = $programme->create('Partner Health', 'partner@health.test', null, 20, 12);
    $code = (string) DB::table('resellers')->where('id', $id)->value('code');
    $programme->attach($this->clinic, $code);

    $this->actingAs($this->admin)->put("http://localhost/admin/resellers/{$id}", ['commission_percent' => 10, 'commission_months' => 6, 'active' => true])->assertSessionHasNoErrors();
    expect((float) DB::table('reseller_referrals')->where('tenant_id', $this->clinic->id)->value('commission_percent'))->toBe(20.0);
    $this->actingAs($this->admin)->put("http://localhost/admin/resellers/{$id}", ['commission_percent' => 10, 'commission_months' => 6, 'active' => false])->assertSessionHasNoErrors();
    expect($programme->validCode($code))->toBeNull();

    $this->actingAs($this->admin)->post("http://localhost/admin/resellers/{$id}/invite")->assertSessionHasNoErrors();
    $user = User::query()->where('email', 'partner@health.test')->sole();
    expect((int) DB::table('resellers')->where('id', $id)->value('user_id'))->toBe($user->id);
    Notification::assertSentTo($user, PracticeNotice::class, fn (PracticeNotice $n) => $n->kind === 'reseller_invite' && str_contains((string) $n->actionUrl, '/reset-password/'));

    // Someone who already has an account with the reseller's email is linked when they open the portal.
    $other = $programme->create('Second Partner', 'second@health.test', null);
    $existing = User::factory()->create(['email' => 'second@health.test']);
    $this->actingAs($existing)->get('http://localhost/reseller')->assertOk();
    expect((int) DB::table('resellers')->where('id', $other)->value('user_id'))->toBe($existing->id);
});
