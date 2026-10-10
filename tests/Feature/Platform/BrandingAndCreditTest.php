<?php

use App\Domains\Platform\Enums\ProviderType;
use App\Domains\Platform\Models\Provider;
use App\Models\User;
use Database\Seeders\PlatformWebsiteSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;

uses(DatabaseMigrations::class);

afterEach(function (): void {
    tenancy()->end();
    Provider::query()->get()->each->delete();
});

$credit = fn ($p) => $p->where('credit.text', 'Developed & Maintained by Mayura Consultancy Services')->where('credit.url', 'https://www.mayuraconsultancy.com');

it('shows the developer credit on the website and in the admin area, and the pulse logo on the main website', function () use ($credit): void {
    $this->seed(PlatformWebsiteSeeder::class);
    $this->get('http://localhost/')->assertInertia(fn ($p) => $credit($p)->where('site.platform', true)->where('site.name', 'Dr Business Flow'));
    $this->get('http://localhost/login')->assertInertia($credit);
    $page = (string) $this->get('http://localhost/login')->getContent();
    expect($page)->toContain('/favicon.svg')->and(file_exists(public_path('favicon.svg')))->toBeTrue();
    $admin = User::factory()->create();
    $admin->forceFill(['is_platform_admin' => true])->save();
    $this->actingAs($admin)->get('http://localhost/admin/pages')->assertOk()->assertInertia($credit);
});

it('shows the same credit on every practice website and the patient portal, which practices cannot change', function () use ($credit): void {
    if (config('database.default') !== 'mysql') {
        $this->markTestSkipped('Tenancy tests require MySQL.');
    }
    makeProvider('Sunrise Medical Centre', ProviderType::Clinic, 'sunrise.clinicflow.test');
    $this->get('http://sunrise.clinicflow.test/')->assertInertia(fn ($p) => $credit($p)->missing('site.platform'));
    $this->get('http://sunrise.clinicflow.test/my/login')->assertInertia($credit);
});

it('renames the product in website pages already created', function (): void {
    DB::table('cms_pages')->insert(['slug' => 'home', 'title' => 'Clinic Flow — healthcare network software', 'body' => '', 'meta_description' => 'Run your clinic on Clinic Flow.',
        'published' => true, 'sections' => json_encode([['type' => 'hero', 'heading' => 'Clinic Flow connects care']]), 'created_at' => now(), 'updated_at' => now()]);
    (require base_path('database/migrations/2026_11_18_000001_rename_product_in_website_pages.php'))->up();
    $row = DB::table('cms_pages')->where('slug', 'home')->first();
    expect($row->title)->toBe('Dr Business Flow — healthcare network software')->and($row->meta_description)->toBe('Run your clinic on Dr Business Flow.')
        ->and((string) $row->sections)->toContain('Dr Business Flow connects care')->not->toContain('Clinic Flow');
});
