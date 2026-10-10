<?php

use App\Domains\Platform\Support\Website\RolePages;
use Database\Seeders\PlatformWebsiteSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;

uses(DatabaseMigrations::class);

it('gives every Solutions page a detailed, feature-led layout with clear calls to action', function (): void {
    $this->seed(PlatformWebsiteSeeder::class);
    foreach (['for-clinics' => '/start', 'for-doctors' => '/start', 'for-pharmacies-and-labs' => '/start', 'for-patients' => '/find-care'] as $slug => $action) {
        $this->get("http://localhost/pages/{$slug}")->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->where('sections', function ($sections) use ($action) {
                $s = collect($sections);
                $features = $s->firstWhere('type', 'features');

                return $s->count() >= 7
                    && $s->first()['type'] === 'hero' && $s->first()['primary']['href'] === $action
                    && count($features['items']) === 6 && collect($features['items'])->every(fn ($i) => ($i['icon'] ?? '') !== '')
                    && count($s->firstWhere('type', 'faq')['items']) >= 5
                    && $s->last()['type'] === 'cta';
            }));
    }
});

it('refreshes unedited Solutions pages and never touches edited ones', function (): void {
    $old = json_encode([['type' => 'hero', 'heading' => 'Old']]);
    DB::table('cms_pages')->insert([
        ['slug' => 'for-clinics', 'title' => 'For clinics', 'body' => '', 'published' => true, 'sections' => $old, 'created_at' => now(), 'updated_at' => now()],
        ['slug' => 'for-doctors', 'title' => 'My edited page', 'body' => '', 'published' => true, 'sections' => $old, 'created_at' => now()->subDays(3), 'updated_at' => now()],
    ]);
    (require base_path('database/migrations/2026_11_17_000001_refresh_solutions_pages.php'))->up();

    expect(DB::table('cms_pages')->where('slug', 'for-clinics')->value('title'))->toBe('Dr Business Flow for Clinics')
        ->and(count(json_decode((string) DB::table('cms_pages')->where('slug', 'for-clinics')->value('sections'), true)))->toBe(count(RolePages::all()[0]['sections']))
        ->and(DB::table('cms_pages')->where('slug', 'for-doctors')->value('title'))->toBe('My edited page')
        ->and(json_decode((string) DB::table('cms_pages')->where('slug', 'for-doctors')->value('sections'), true))->toBe(json_decode($old, true));
});
