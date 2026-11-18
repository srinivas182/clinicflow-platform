<?php

/*
 * Beta testing, sprint T1a: practice screens that were blank or misleading, and the AI scribe draft
 * that any staff member could read.
 */

use App\Domains\Identity\Actions\AddStaffMember;
use App\Domains\Identity\Enums\StaffRole;
use App\Domains\Messaging\Contracts\MessageSender;
use App\Domains\Messaging\Support\LogMessageSender;
use App\Domains\Platform\Enums\ProviderType;
use App\Domains\Platform\Enums\SubscriptionStatus;
use App\Domains\Platform\Models\Package;
use App\Domains\Platform\Models\Provider;
use App\Domains\Platform\Models\Subscription;
use App\Domains\Scribe\Actions\AiScribe;
use App\Domains\Scribe\Models\AiProvider;
use App\Domains\Visits\Enums\PayerType;
use App\Models\User;
use Database\Seeders\ClinicalReferenceSeeder;
use Database\Seeders\PackageSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Inertia\Testing\AssertableInertia;

uses(DatabaseMigrations::class);

beforeEach(function (): void {
    if (config('database.default') !== 'mysql') {
        $this->markTestSkipped('Tenancy tests require MySQL.');
    }
    $this->artisan('migrate:fresh', ['--database' => 'hub', '--path' => 'database/migrations/hub'])->assertSuccessful();
    $this->seed(ClinicalReferenceSeeder::class);
    $this->app->instance(MessageSender::class, new LogMessageSender);
    $this->clinic = makeProvider('Sunrise Medical Centre', ProviderType::Clinic, 'sunrise.clinicflow.test');
    $add = app(AddStaffMember::class);
    foreach (['owner' => StaffRole::Owner, 'doctor' => StaffRole::Doctor, 'otherDoctor' => StaffRole::Doctor, 'receptionist' => StaffRole::Receptionist] as $key => $role) {
        $this->{$key} = User::factory()->create();
        $add->handle($this->clinic, $this->{$key}, $role);
    }
});

afterEach(function (): void {
    tenancy()->end();
    Provider::query()->get()->each->delete();
});

it('links practice pages to the built front end, not to the practice file store', function (): void {
    // With asset tenancy on, every practice page loaded its scripts from /tenancy/assets and rendered blank in production.
    expect(config('tenancy.filesystem.asset_helper_tenancy'))->toBeFalse();
    $this->clinic->run(function (): void {
        expect(asset('build/assets/app.js'))->toEndWith('/build/assets/app.js')->not->toContain('tenancy/assets');
    });
});

it('keeps the branch switcher apart from the branch lists that pages send', function (): void {
    // Staff, Reports, Branches and Locums send their own `branches` list, which used to replace the layout's switcher and crash the page.
    foreach (['/staff', '/reports', '/settings/branches'] as $path) {
        $this->actingAs($this->owner)->get("http://sunrise.clinicflow.test{$path}")->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->has('branchSwitcher')->where('branches', fn ($b) => is_iterable($b)));
    }
});

it('tells the menu what each person may open, so the owner is not offered doctor-only screens', function (): void {
    $permissions = fn (User $user) => $this->actingAs($user)->get('http://sunrise.clinicflow.test/workspace')
        ->assertOk()->viewData('page')['props']['auth']['permissions'];

    expect($permissions($this->owner))->toContain('staff.manage', 'finance.view')->not->toContain('consults.write')
        ->and($permissions($this->doctor))->toContain('consults.write')->not->toContain('staff.manage')
        ->and($permissions($this->receptionist))->toContain('visits.manage', 'patients.register')->not->toContain('consults.write', 'finance.view');

    // The screens the menu now hides from the owner really are closed to them.
    $this->actingAs($this->owner)->get('http://sunrise.clinicflow.test/doctor')->assertForbidden();
    $this->actingAs($this->owner)->get('http://sunrise.clinicflow.test/results')->assertForbidden();
});

it('lets only the doctor who started an AI scribe session read its draft note', function (): void {
    $this->seed(PackageSeeder::class);
    Subscription::create(['tenant_id' => $this->clinic->id, 'package_id' => Package::query()->where('code', 'clinic-pro')->value('id'),
        'status' => SubscriptionStatus::Active, 'current_period_ends_at' => now()->addMonth(), 'addons' => ['ai_scribe']]);
    AiProvider::query()->create(['driver' => 'deepgram', 'kind' => 'speech', 'enabled' => true, 'credentials' => ['api_key' => 'dg-test']]);
    AiProvider::query()->create(['driver' => 'anthropic', 'kind' => 'notes', 'enabled' => true, 'credentials' => ['api_key' => 'sk-test']]);
    $session = $this->clinic->run(function (): string {
        $consultation = seenByDoctor($this, registerTestPatient('Thandi', '880412'), PayerType::Cash);

        return (string) app(AiScribe::class)->start($consultation, $this->doctor->id, true);
    });
    $url = "http://sunrise.clinicflow.test/scribe/{$session}";

    $this->actingAs($this->receptionist)->getJson($url)->assertForbidden();
    $this->actingAs($this->otherDoctor)->getJson($url)->assertForbidden();
    $this->actingAs($this->owner)->getJson($url)->assertForbidden();
    $this->actingAs($this->doctor)->getJson($url)->assertOk()->assertJsonPath('session', $session);
});

it('keeps search engines out of the patient, staff and admin areas', function (): void {
    expect(file_exists(public_path('robots.txt')))->toBeFalse();
    $this->get('http://localhost/robots.txt')->assertOk()->assertSee('Disallow: /admin')->assertSee('Disallow: /workspaces');
    $this->get('http://sunrise.clinicflow.test/robots.txt')->assertOk()->assertSee('Disallow: /my')->assertSee('Disallow: /workspace');
});
