<?php

use App\Domains\Identity\Actions\AddStaffMember;
use App\Domains\Identity\Enums\StaffRole;
use App\Domains\Platform\Enums\ProviderType;
use App\Domains\Platform\Models\Provider;
use App\Domains\Platform\Models\SitePage;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Inertia\Testing\AssertableInertia;

uses(DatabaseMigrations::class);

beforeEach(function (): void {
    if (config('database.default') !== 'mysql') {
        $this->markTestSkipped('Tenancy tests require MySQL.');
    }
    $this->clinic = makeProvider('Sunrise Medical Centre', ProviderType::Clinic, 'sunrise.clinicflow.test');
    $this->owner = User::factory()->create();
    $this->receptionist = User::factory()->create();
    app(AddStaffMember::class)->handle($this->clinic, $this->owner, StaffRole::Owner);
    app(AddStaffMember::class)->handle($this->clinic, $this->receptionist, StaffRole::Receptionist);
});

afterEach(function (): void {
    tenancy()->end();
    Provider::query()->get()->each->delete();
});

it('gives every new provider a default website suited to its type', function (): void {
    $pharmacy = makeProvider('Vilakazi Pharmacy', ProviderType::Pharmacy, 'vilakazi.clinicflow.test');

    $this->clinic->run(fn () => expect(SitePage::query()->orderBy('menu_order')->pluck('slug')->all())->toBe(['home', 'services', 'about', 'contact']));

    $this->get('http://sunrise.clinicflow.test/')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('Site/Show')
            ->where('sections.0.heading', 'Welcome to Sunrise Medical Centre')
            ->where('sections.0.primary.href', '/portal')
            ->where('site.menu.1.href', '/p/services')
            // Staff sign in goes to the central sign-in for this practice (it opens the practice afterwards).
            ->where('site.footer.0.href', fn ($href) => str_contains((string) $href, '/login?practice=')));

    $this->get('http://vilakazi.clinicflow.test/')
        ->assertInertia(fn (AssertableInertia $page) => $page->where('sections.0.primary.label', 'Track my medicine'));
});

it('fills contact details into every page that uses them', function (): void {
    $this->actingAs($this->owner)->put('http://sunrise.clinicflow.test/settings/website/details', [
        'phone' => '011 555 0100', 'email' => 'hello@sunrise.test', 'address' => '12 Vilakazi St, Orlando West', 'hours' => 'Mon–Sat 07:00–19:00',
    ])->assertSessionHasNoErrors();

    $this->get('http://sunrise.clinicflow.test/p/contact')
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('site.contact.phone', '011 555 0100')
            ->where('sections.1.text', 'Book online or call us on 011 555 0100.')
            ->where('sections.0.note', 'Opening hours: Mon–Sat 07:00–19:00'));
});

it('lets the owner edit pages but not reception, and keeps the home page published', function (): void {
    $home = $this->clinic->run(fn () => SitePage::query()->where('slug', 'home')->sole());
    $about = $this->clinic->run(fn () => SitePage::query()->where('slug', 'about')->sole());
    $sections = [['type' => 'hero', 'heading' => 'Care close to home', 'text' => 'Walk-ins welcome.']];

    $this->actingAs($this->receptionist)->put("http://sunrise.clinicflow.test/settings/website/pages/{$home->id}", ['title' => 'x', 'sections' => $sections])->assertForbidden();
    $this->actingAs($this->owner)->put("http://sunrise.clinicflow.test/settings/website/pages/{$home->id}", ['title' => '{name}', 'menu_label' => 'Home', 'published' => false, 'sections' => $sections])->assertStatus(422);
    $this->actingAs($this->owner)->put("http://sunrise.clinicflow.test/settings/website/pages/{$home->id}", ['title' => '{name}', 'menu_label' => 'Home', 'published' => true, 'sections' => $sections])->assertSessionHasNoErrors();
    $this->actingAs($this->owner)->put("http://sunrise.clinicflow.test/settings/website/pages/{$about->id}", ['title' => 'About', 'menu_label' => 'About', 'published' => false, 'sections' => $sections])->assertSessionHasNoErrors();

    auth()->logout();
    $this->get('http://sunrise.clinicflow.test/')->assertInertia(fn (AssertableInertia $page) => $page->where('sections.0.heading', 'Care close to home'));
    $this->get('http://sunrise.clinicflow.test/p/about')->assertNotFound();
});

it('keeps the staff workspace on /workspace', function (): void {
    $this->actingAs($this->owner)->get('http://sunrise.clinicflow.test/workspace')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->component('Provider/Home'));
});

it('adds missing default pages to existing providers without touching edits', function (): void {
    $this->clinic->run(function (): void {
        SitePage::query()->where('slug', 'services')->delete();
        SitePage::query()->where('slug', 'home')->update(['title' => 'Kept']);
    });

    $this->artisan('websites:seed-defaults')->assertSuccessful();

    $this->clinic->run(fn () => expect(SitePage::query()->count())->toBe(4)->and(SitePage::query()->where('slug', 'home')->value('title'))->toBe('Kept'));
});
