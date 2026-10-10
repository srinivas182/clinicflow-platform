<?php

use App\Domains\Platform\Models\CmsPage;
use App\Models\User;
use Database\Seeders\PlatformWebsiteSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed(PlatformWebsiteSeeder::class));

it('publishes a complete default clinicflow.co.za with menus and images', function (): void {
    $this->get('http://localhost/')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('Public/Site')
            ->where('sections.0.type', 'hero')
            ->where('sections.0.image', '/images/site/hero-network.svg')
            ->where('site.menu.0.label', 'About')
            ->where('site.menu.1.label', 'Solutions')
            ->where('site.menu.1.children', fn ($c) => collect($c)->pluck('label')->all() === ['Clinics', 'Individual Doctors', 'Pharmacies & Labs', 'Patients'])
            ->where('site.menu.2.label', 'Find Care')
            ->where('site.menu.3.label', 'Pricing')
            ->where('site.menu.4.label', 'Contact Us')
            ->has('site.menu', 5)
            ->where('site.signIn.href', '/login')
            ->where('site.cta.label', 'Start Free Trial')
            ->where('site.cta.href', '/start'));

    $this->get('http://localhost/pages/for-doctors')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->component('Public/Site'));
    expect(file_exists(public_path('images/site/hero-network.svg')))->toBeTrue();
});

it('keeps legal drafts unpublished until approved', function (): void {
    $this->get('http://localhost/pages/privacy')->assertNotFound();
    // Draft legal pages never appear in the footer until approved.
    $this->get('http://localhost/')->assertInertia(fn (AssertableInertia $page) => $page->where('site.footer', fn ($footer) => ! collect($footer)->pluck('href')->contains('/pages/privacy')
        && ! collect($footer)->pluck('href')->contains('/pages/terms') && collect($footer)->pluck('href')->contains('/login')));
});

it('never overwrites edited pages when the defaults run again', function (): void {
    CmsPage::query()->where('slug', 'home')->update(['title' => 'Edited']);
    $this->seed(PlatformWebsiteSeeder::class);

    expect(CmsPage::query()->where('slug', 'home')->value('title'))->toBe('Edited')
        ->and(CmsPage::query()->count())->toBe(9);
});

it('lets the super admin edit sections and refuses unsafe links', function (): void {
    $admin = User::factory()->create();
    $admin->forceFill(['is_platform_admin' => true])->save();
    $home = CmsPage::query()->where('slug', 'home')->sole();
    $payload = fn (string $href) => ['id' => $home->id, 'slug' => 'home', 'title' => 'Home', 'published' => true,
        'sections' => [['type' => 'hero', 'heading' => 'New headline', 'primary' => ['label' => 'Go', 'href' => $href]]]];

    $this->actingAs($admin)->post('http://localhost/admin/pages', $payload('javascript:alert(1)'))->assertSessionHasErrors();
    $this->actingAs($admin)->post('http://localhost/admin/pages', $payload('/start'))->assertSessionHasNoErrors();

    expect($home->fresh()?->sections[0]['heading'])->toBe('New headline');
});
