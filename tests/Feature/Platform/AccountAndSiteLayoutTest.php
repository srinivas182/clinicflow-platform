<?php

use App\Domains\Platform\Enums\ProviderType;
use App\Domains\Platform\Models\Provider;
use App\Models\User;
use Database\Seeders\PlatformWebsiteSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\URL;

uses(DatabaseMigrations::class);

afterEach(function (): void {
    tenancy()->end();
    Provider::query()->get()->each->delete();
});

it('gives Find Care and the About page the full website layout', function (): void {
    $this->seed(PlatformWebsiteSeeder::class);
    $this->get('http://localhost/find-care')->assertOk()->assertInertia(fn ($p) => $p->component('Public/Directory')
        ->where('site.menu.1.label', 'Solutions')->where('site.signIn.href', '/login'));
    $about = $this->get('http://localhost/pages/about')->assertOk();
    $about->assertInertia(fn ($p) => $p->where('site.cta.label', 'Start Free Trial'));
});

it('signs out everywhere through a signed central link, and only for the right person', function (): void {
    $user = User::factory()->create();
    $other = User::factory()->create();
    $link = fn (User $u) => URL::temporarySignedRoute('logout.signed', now()->addMinutes(2), ['user' => $u->id], absolute: false);

    // A link made for someone else signs nobody out.
    $this->actingAs($user)->get('http://localhost'.$link($other))->assertRedirect('/login');
    $this->assertAuthenticatedAs($user);
    // A tampered link is refused.
    $this->actingAs($user)->get('http://localhost'.$link($user).'x')->assertForbidden();
    $this->assertAuthenticatedAs($user);
    // The real link ends the central session.
    $this->actingAs($user)->get('http://localhost'.$link($user))->assertRedirect('/login');
    $this->assertGuest();
});

it('signs staff out of a practice and sends them through the central sign-out', function (): void {
    if (config('database.default') !== 'mysql') {
        $this->markTestSkipped('Tenancy tests require MySQL.');
    }
    makeProvider('Sunrise Medical Centre', ProviderType::Clinic, 'sunrise.clinicflow.test');
    $user = User::factory()->create();
    $response = $this->actingAs($user)->withHeaders(['X-Inertia' => 'true'])->post('http://sunrise.clinicflow.test/staff/logout');
    $response->assertStatus(409);
    $location = (string) $response->headers->get('X-Inertia-Location');
    expect($location)->toContain('/logout/everywhere?')->toContain('signature=')->toContain('user='.$user->id);
    $this->assertGuest();
});
