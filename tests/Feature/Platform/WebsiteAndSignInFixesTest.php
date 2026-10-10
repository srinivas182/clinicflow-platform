<?php

use App\Domains\Identity\Actions\AddStaffMember;
use App\Domains\Identity\Enums\StaffRole;
use App\Domains\Platform\Enums\ProviderType;
use App\Domains\Platform\Models\Provider;
use App\Domains\Wallet\Support\WalletSettings;
use App\Models\User;
use Database\Seeders\PackageSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;

uses(DatabaseMigrations::class);

beforeEach(function (): void {
    WalletSettings::put('security.two_factor_enabled', false);
});

afterEach(function (): void {
    tenancy()->end();
    Provider::query()->get()->each->delete();
});

it('takes a super admin with no practice straight to the admin area', function (): void {
    $admin = User::factory()->create(['email' => 'admin@drbusinessflow.com', 'password' => 'correct-horse-battery']);
    $admin->forceFill(['is_platform_admin' => true])->save();
    $this->post('http://localhost/login', ['login' => 'admin@drbusinessflow.com', 'password' => 'correct-horse-battery'])->assertRedirect('/admin/providers');
    $this->get('http://localhost/admin')->assertRedirect('http://localhost/admin/providers');
    $this->get('http://localhost/workspaces')->assertInertia(fn ($p) => $p->where('isPlatformAdmin', true));
});

it('signs staff in from a practice address and opens that practice', function (): void {
    if (config('database.default') !== 'mysql') {
        $this->markTestSkipped('Tenancy tests require MySQL.');
    }
    $clinic = makeProvider('Sunrise Medical Centre', ProviderType::Clinic, 'sunrise.clinicflow.test');
    $nurse = User::factory()->create(['email' => 'nurse@drbusinessflow.com', 'password' => 'correct-horse-battery']);
    app(AddStaffMember::class)->handle($clinic, $nurse, StaffRole::Nurse);
    $outsider = User::factory()->create(['email' => 'other@drbusinessflow.com', 'password' => 'correct-horse-battery']);

    $this->get("http://localhost/login?practice={$clinic->id}")->assertInertia(fn ($p) => $p->where('practice', 'Sunrise Medical Centre'));
    $this->post('http://localhost/login', ['login' => 'nurse@drbusinessflow.com', 'password' => 'correct-horse-battery'])->assertRedirect("/workspaces?open={$clinic->id}");
    $this->get("http://localhost/workspaces?open={$clinic->id}")->assertInertia(fn ($p) => $p->where('autoOpen', $clinic->id));

    $this->post('http://localhost/logout');
    $this->get("http://localhost/login?practice={$clinic->id}");
    $this->post('http://localhost/login', ['login' => 'other@drbusinessflow.com', 'password' => 'correct-horse-battery'])->assertRedirect('/workspaces');
    $this->get("http://localhost/workspaces?open={$clinic->id}")->assertInertia(fn ($p) => $p->where('autoOpen', null));
});

it('shows the pricing page with the site menu, sign-in and sign-up', function (): void {
    $this->seed(PackageSeeder::class);
    $this->get('http://localhost/pricing')->assertOk()->assertInertia(fn ($p) => $p->component('Public/Pricing')
        ->where('site.cta.href', '/start')
        ->where('site.signIn.href', '/login')
        ->where('site.menu', fn ($menu) => collect($menu)->pluck('href')->contains('/pricing')));
});

it('updates the hosting wording on pages already created', function (): void {
    DB::table('cms_pages')->insert(['slug' => 'for-doctors', 'title' => 'For doctors', 'body' => '', 'published' => true,
        'sections' => json_encode([['type' => 'split', 'bullets' => ['Hosted in AWS Cape Town (af-south-1)']]]), 'created_at' => now(), 'updated_at' => now()]);
    (require base_path('database/migrations/2026_11_16_000001_update_website_hosting_wording.php'))->up();
    expect((string) DB::table('cms_pages')->where('slug', 'for-doctors')->value('sections'))->toContain('Hosted in South Africa')->not->toContain('AWS Cape Town');
});

it('keeps text out of the shield illustration and locks label widths in the others', function (): void {
    expect(file_get_contents(public_path('images/site/secure-data.svg')))->not->toContain('<text');
    foreach (['hero-network', 'patient-app', 'pharmacy'] as $name) {
        $svg = (string) file_get_contents(public_path("images/site/{$name}.svg"));
        expect(substr_count($svg, '<text'))->toBe(substr_count($svg, 'textLength='));
    }
});
