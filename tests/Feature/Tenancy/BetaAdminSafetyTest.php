<?php

use App\Domains\Billing\Models\SubscriptionInvoice;
use App\Domains\Identity\Actions\AddStaffMember;
use App\Domains\Identity\Enums\StaffRole;
use App\Domains\Platform\Actions\IssueSubscriptionInvoices;
use App\Domains\Platform\Enums\ProviderType;
use App\Domains\Platform\Enums\SubscriptionStatus;
use App\Domains\Platform\Models\Package;
use App\Domains\Platform\Models\Provider;
use App\Domains\Platform\Models\ProviderGroup;
use App\Domains\Platform\Models\Subscription;
use App\Domains\Platform\Notifications\PracticeNotice;
use App\Domains\Platform\Storage\StorageTargets;
use App\Domains\Platform\SupportDesk\SupportDesk;
use App\Http\Middleware\NormalizeSouthAfricanPhones;
use App\Http\Middleware\RequireRecentConfirmation;
use App\Models\User;
use Database\Seeders\PackageSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

/*
 * Beta testing T3: admin safety and validation (tracker rows 22, 46–48, 83–87, 120, 133, 157–163,
 * 173, 211–214, 226–230, 241–242, 277–278, 291–294, practice row 1).
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
    $this->as = fn (bool $confirmed = true) => $this->actingAs($this->admin)->withSession($confirmed ? [RequireRecentConfirmation::SESSION_KEY => now()->getTimestamp()] : []);
    $this->clinic = makeProvider('Sunrise Medical Centre', ProviderType::Clinic, 'sunrise.clinicflow.test');
    $this->owner = User::factory()->create(['email' => 'owner@sunrise.test']);
    app(AddStaffMember::class)->handle($this->clinic, $this->owner, StaffRole::Owner);
});

afterEach(function (): void {
    tenancy()->end();
    Provider::query()->get()->each->delete();
});

it('gives a price rise 30 days notice to paying practices and applies it from that date (beta #22)', function (): void {
    $package = Package::query()->where('code', 'clinic-starter')->firstOrFail();
    $sub = Subscription::create(['tenant_id' => $this->clinic->id, 'package_id' => $package->id, 'status' => SubscriptionStatus::Active, 'current_period_ends_at' => now()->addDays(2)]);
    $old = $package->price_monthly_cents;

    ($this->as)()->put("http://localhost/admin/packages/{$package->id}", ['price_monthly' => $old / 100 + 100, 'trial_days' => 30, 'is_active' => true])->assertSessionHasNoErrors();
    expect($package->fresh()?->price_monthly_cents)->toBe($old)->and($package->fresh()?->pending_price_monthly_cents)->toBe($old + 10000);
    Notification::assertSentTo($this->owner, PracticeNotice::class, fn (PracticeNotice $n) => $n->kind === 'price_change');

    app(IssueSubscriptionInvoices::class)->handle();
    expect(SubscriptionInvoice::query()->where('subscription_id', $sub->id)->sole()->amount_cents)->toBe($old);
    $this->travel(31)->days();
    app(IssueSubscriptionInvoices::class)->handle();
    expect($package->fresh()?->price_monthly_cents)->toBe($old + 10000)->and($package->fresh()?->pending_price_from)->toBeNull();
});

it('needs a fresh identity check to switch a gateway to Live, and 404s unknown gateways (beta #46, #48)', function (): void {
    $body = ['mode' => 'live', 'enabled' => false, 'is_default' => false, 'credentials' => []];
    ($this->as)(false)->put('http://localhost/admin/payments/yoco', $body)->assertRedirect('http://localhost/confirm-identity');
    ($this->as)()->put('http://localhost/admin/payments/yoco', $body)->assertSessionHasNoErrors();
    ($this->as)()->put('http://localhost/admin/payments/not-a-gateway', $body)->assertNotFound();
    ($this->as)()->post('http://localhost/admin/payments/not-a-gateway/test')->assertNotFound();
});

it('validates groups and resellers and reports when there is nothing to do (beta #83, #86, #87, #120)', function (): void {
    ($this->as)()->post('http://localhost/admin/groups/create', ['name' => 'Sunrise Group', 'billing' => 'combined'])->assertSessionHasNoErrors();
    ($this->as)()->post('http://localhost/admin/groups/create', ['name' => 'Sunrise Group', 'billing' => 'separate'])->assertSessionHasErrors('name');
    $group = ProviderGroup::query()->sole();
    ($this->as)()->post('http://localhost/admin/groups/billing', ['group_id' => $group->id, 'billing' => 'weekly'])->assertSessionHasErrors('billing');
    ($this->as)()->post('http://localhost/admin/groups/invoice', ['group_id' => $group->id])->assertSessionHas('success', 'Nothing to invoice: no practice in this group has an unpaid invoice.');

    $reseller = DB::table('resellers')->insertGetId(['name' => 'Partner Health', 'email' => 'partner@health.test', 'code' => 'PARTNER', 'commission_percent' => 10, 'created_at' => now(), 'updated_at' => now()]);
    ($this->as)()->post("http://localhost/admin/resellers/{$reseller}/pay", ['period' => now()->format('Y-m'), 'reference' => 'EFT-1'])
        ->assertSessionHas('error', 'Nothing to pay: no unpaid commission for this reseller in that month.');
});

it('keeps brands, storage and website pages tidy (beta #157, #161, #163, #173, #291, #294)', function (): void {
    $brand = ['name' => 'Partner Health', 'slug' => 'partner', 'primary_color' => '#0f7c74', 'accent_color' => '#f2a33a', 'powered_by' => true, 'active' => true];
    ($this->as)()->post('http://localhost/admin/brands', $brand)->assertSessionHasNoErrors();
    ($this->as)()->post('http://localhost/admin/brands', ['slug' => 'partner-two'] + $brand)->assertSessionHasErrors('name');
    $svg = UploadedFile::fake()->createWithContent('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');
    ($this->as)()->post('http://localhost/admin/brands', ['name' => 'Other', 'slug' => 'other', 'logo' => $svg] + $brand)->assertSessionHasErrors('logo');
    ($this->as)()->post('http://localhost/admin/brands/assign', ['provider_id' => 'no-such-practice'])->assertNotFound();
    ($this->as)()->post('http://localhost/admin/brands/999/senders/sms', ['sms_sender' => 'PARTNER'])->assertNotFound();

    $targets = app(StorageTargets::class);
    $local = ['driver' => 'local', 'provider' => null, 'bucket' => null, 'region' => null, 'endpoint' => null, 'path_style' => false, 'encrypt' => false, 'root' => null, 'key' => null, 'secret' => null];
    $targets->save(null, ['name' => 'Server disk'] + $local);
    expect(fn () => $targets->save(null, ['name' => 'server DISK'] + $local))->toThrow(ValidationException::class);

    $page = ['title' => 'About', 'published' => true];
    ($this->as)()->post('http://localhost/admin/pages', ['slug' => 'start'] + $page)->assertSessionHasErrors('slug');
    ($this->as)()->post('http://localhost/admin/pages', ['slug' => 'about-us', 'menu_order' => 5] + $page)->assertSessionHasNoErrors();
    ($this->as)()->post('http://localhost/admin/pages', ['slug' => 'team', 'menu_order' => 5] + $page)->assertSessionHasErrors('menu_order');
});

it('keeps support tickets and the status page consistent (beta #211, #213, #214, #226, #227, #229, #230)', function (): void {
    $desk = app(SupportDesk::class);
    expect(fn () => $desk->open($this->clinic, $this->owner, str_repeat('x', 201), 'Body'))->toThrow(ValidationException::class);
    $ticket = $desk->open($this->clinic, $this->owner, 'Printing', 'Blank PDF');
    expect(fn () => $desk->reply($ticket, $this->owner, 'practice', str_repeat('y', 5001)))->toThrow(ValidationException::class);
    ($this->as)()->post('http://localhost/admin/support/reply', ['ticket_id' => 999, 'body' => 'Hi'])->assertNotFound();
    $desk->close($ticket);
    expect(fn () => $desk->reply($ticket, $this->owner, 'practice', 'Still broken'))->toThrow(ValidationException::class, 'closed');

    ($this->as)()->post('http://localhost/admin/status/report', ['title' => 'X', 'kind' => 'incident', 'impact' => 'minor', 'components' => ['nope'], 'body' => 'b'])->assertSessionHasErrors('components.0');
    ($this->as)()->post('http://localhost/admin/status/report', ['title' => 'Upgrade', 'kind' => 'maintenance', 'impact' => 'minor', 'body' => 'b', 'scheduled_for' => now()->subDay()->toDateTimeString()])->assertSessionHasErrors('scheduled_for');
    ($this->as)()->post('http://localhost/admin/status/report', ['title' => 'Upgrade', 'kind' => 'maintenance', 'impact' => 'minor', 'body' => 'b', 'scheduled_for' => now()->addDay()->toDateTimeString()])->assertSessionHasNoErrors();
    $id = DB::table('status_incidents')->value('id');
    ($this->as)()->post('http://localhost/admin/status/update', ['incident_id' => $id, 'status' => 'resolved', 'body' => 'done'])->assertSessionHasErrors('status');
    ($this->as)()->post('http://localhost/admin/status/update', ['incident_id' => 999, 'status' => 'resolved', 'body' => 'done'])->assertNotFound();
});

it('checks messaging and WhatsApp senders and gates Live on a passed test (beta #241, #242, #277, #278)', function (): void {
    $sms = ['mode' => 'test', 'enabled' => true, 'is_default' => true, 'credentials' => ['account_sid' => 'AC1', 'auth_token' => 't']];
    ($this->as)()->put('http://localhost/admin/messaging/providers/twilio', ['sender' => 'Clinic Flow Ltd'] + $sms)->assertSessionHasErrors('sender');
    ($this->as)()->put('http://localhost/admin/messaging/providers/twilio', ['sender' => 'ClinicFlow'] + $sms)->assertSessionHasNoErrors();
    ($this->as)()->put('http://localhost/admin/messaging/providers/twilio', ['sender' => 'ClinicFlow', 'mode' => 'live'] + $sms)->assertSessionHasErrors('mode');

    ($this->as)()->put('http://localhost/admin/whatsapp/providers/twilio', ['enabled' => true, 'sender' => 'WhatsApp', 'credentials' => ['account_sid' => 'AC1', 'auth_token' => 't']])->assertSessionHasErrors('sender');
    ($this->as)()->put('http://localhost/admin/whatsapp/providers/twilio', ['enabled' => true, 'sender' => '+27821234567', 'credentials' => ['account_sid' => 'AC1']])->assertSessionHasErrors('credentials');
    ($this->as)()->put('http://localhost/admin/whatsapp/providers/twilio', ['enabled' => false, 'credentials' => ['bogus' => 'x']])->assertSessionHasErrors('credentials');
});

it('accepts +27 and spaced cell numbers and stores them in the local format (practice row 1)', function (): void {
    expect(NormalizeSouthAfricanPhones::normalize('+27 82 123 4567'))->toBe('0821234567')
        ->and(NormalizeSouthAfricanPhones::normalize('27821234567'))->toBe('0821234567')
        ->and(NormalizeSouthAfricanPhones::normalize('082-123-4567'))->toBe('0821234567')
        ->and(NormalizeSouthAfricanPhones::normalize('+44 20 7946 0958'))->toBe('+44 20 7946 0958');
});
