<?php

use App\Domains\Wallet\Support\WalletSettings;
use App\Http\Middleware\RequireRecentConfirmation;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;

/*
 * Beta testing T3: every admin change is in the activity log with what was submitted (secrets
 * masked), and setting changes keep old and new values (tracker rows 56, 74, 90, 96, 121, 145,
 * 169, 181, 235, 249, 257, 282). Also wallet price limits (54, 55).
 */

uses(DatabaseMigrations::class);

beforeEach(function (): void {
    $this->admin = User::factory()->create(['name' => 'Ops Admin']);
    $this->admin->forceFill(['is_platform_admin' => true])->save();
    $this->as = fn () => $this->actingAs($this->admin)->withSession([RequireRecentConfirmation::SESSION_KEY => now()->getTimestamp()]);
    $this->wallet = ['video' => 3, 'audio' => 1.5, 'chat' => 15, 'threshold' => 150, 'packs' => [['amount' => 500, 'bonus' => 0], ['amount' => 1000, 'bonus' => 50]]];
});

it('logs each admin change with the submitted values and setting changes with old and new values', function (): void {
    ($this->as)()->put('http://localhost/admin/wallet', $this->wallet)->assertSessionHasNoErrors();

    $entry = DB::table('activity_log')->where('log_name', 'admin')->latest('id')->first();
    expect($entry?->description)->toBe('Admin: wallet settings save')
        ->and(json_decode((string) $entry?->properties, true)['input']['video'])->toBe(3);
    $change = DB::table('activity_log')->where('log_name', 'settings')->where('description', 'Setting changed: wallet.price_video_per_minute_cents')->sole();
    expect(json_decode((string) $change->properties, true))->toMatchArray(['old' => 250, 'new' => 300])
        ->and((int) $change->causer_id)->toBe($this->admin->id);

    // Saving the same values again records the request but no setting change.
    ($this->as)()->put('http://localhost/admin/wallet', $this->wallet)->assertSessionHasNoErrors();
    expect(DB::table('activity_log')->where('description', 'Setting changed: wallet.price_video_per_minute_cents')->count())->toBe(1);
});

it('never stores secrets and skips refused changes', function (): void {
    ($this->as)()->post('http://localhost/admin/security/bot', ['enabled' => false, 'site_key' => '0x4AAAAAAAexampleSiteKey', 'secret' => 'very-secret-value'])->assertSessionHasNoErrors();
    expect(DB::table('activity_log')->where('properties', 'like', '%very-secret-value%')->exists())->toBeFalse()
        ->and(DB::table('activity_log')->where('description', 'Admin: security bot')->exists())->toBeTrue();

    $before = DB::table('activity_log')->where('log_name', 'admin')->count();
    ($this->as)()->put('http://localhost/admin/wallet', ['video' => 0] + $this->wallet)->assertSessionHasErrors('video');
    expect(DB::table('activity_log')->where('log_name', 'admin')->count())->toBe($before);
});

it('shows the activity log to the super admin only, with search', function (): void {
    ($this->as)()->put('http://localhost/admin/wallet', $this->wallet);
    ($this->as)()->get('http://localhost/admin/activity?q=wallet')->assertOk()
        ->assertInertia(fn ($p) => $p->component('Admin/Activity')->where('entries.0.by', 'Ops Admin')->where('entries', fn ($e) => collect($e)->every(fn ($row) => str_contains(strtolower(json_encode($row)), 'wallet'))));
    $this->actingAs(User::factory()->create())->get('http://localhost/admin/activity')->assertForbidden();
});

it('keeps wallet prices and packs sensible (beta #54, #55)', function (): void {
    ($this->as)()->put('http://localhost/admin/wallet', ['video' => 900] + $this->wallet)->assertSessionHasErrors('video');
    ($this->as)()->put('http://localhost/admin/wallet', ['packs' => [['amount' => 500, 'bonus' => 0], ['amount' => 500, 'bonus' => 10]]] + $this->wallet)->assertSessionHasErrors('packs.1.amount');
    ($this->as)()->put('http://localhost/admin/wallet', ['packs' => [['amount' => 500, 'bonus' => 600]]] + $this->wallet)->assertSessionHasErrors('packs.0.bonus');
    expect(WalletSettings::get('wallet.price_video_per_minute_cents'))->toBe(250);
});
