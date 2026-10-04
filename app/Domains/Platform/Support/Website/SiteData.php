<?php

declare(strict_types=1);

namespace App\Domains\Platform\Support\Website;

use App\Domains\Documents\Support\PracticeData;
use App\Domains\Platform\Models\CmsPage;
use App\Domains\Platform\Models\Setting;
use App\Domains\Platform\Models\SitePage;
use App\Domains\Website\Actions\Feedback;

/**
 * Menus, brand and contact details for rendering the two kinds of site.
 */
final class SiteData
{
    /**
     * @return array<string, string>
     */
    public static function providerTokens(): array
    {
        $practice = PracticeData::get();
        $get = fn (string $group, string $key, string $default): string => is_scalar($v = Setting::get($group, $key, $default)) ? (string) $v : $default;

        return [
            'name' => $practice['name'],
            'phone' => $practice['phone'],
            'address' => $practice['address'],
            'email' => $get('branding', 'email', ''),
            'hours' => $get('website', 'hours', 'Mon–Fri 08:00–17:00 · Sat 08:00–12:00'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function provider(SitePage $page): array
    {
        $tokens = self::providerTokens();
        $menu = SitePage::query()->where('published', true)->whereNotNull('menu_order')->orderBy('menu_order')->get()
            ->map(fn (SitePage $p) => ['label' => $p->menu_label ?? $p->title, 'href' => $p->slug === 'home' ? '/' : "/p/{$p->slug}"])->values()->all();

        return [
            'page' => ['title' => str_replace('{name}', $tokens['name'], $page->title), 'description' => str_replace('{name}', $tokens['name'], (string) $page->meta_description)],
            'sections' => self::withoutEmptyPhoneButtons(SiteSections::resolve($page->sections, [...$tokens, 'phone' => $tokens['phone'] !== '' ? $tokens['phone'] : 'the practice'])),
            'site' => [
                'name' => $tokens['name'], 'colour' => PracticeData::get()['colour'], 'menu' => $menu,
                'cta' => ['label' => 'Patient portal', 'href' => '/portal'],
                'contact' => ['phone' => $tokens['phone'], 'email' => $tokens['email'], 'address' => $tokens['address'], 'hours' => $tokens['hours']],
                'footer' => [['label' => 'Staff sign-in', 'href' => '/workspace']],
                'poweredBy' => true,
                'reviews' => Feedback::publicReviews(),
                'ogImage' => Setting::get('website', 'og_image'),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function platform(CmsPage $page): array
    {
        $menu = CmsPage::query()->where('published', true)->whereNotNull('menu_order')->orderBy('menu_order')->get()
            ->map(fn (CmsPage $p) => ['label' => $p->menu_label ?? $p->title, 'href' => "/pages/{$p->slug}"])->values()->all();

        return [
            'page' => ['title' => $page->title, 'description' => (string) $page->meta_description],
            'sections' => $page->sections ?? [],
            'site' => [
                'name' => 'Clinic Flow', 'colour' => '#0F7C74',
                'menu' => [...$menu, ['label' => 'Pricing', 'href' => '/pricing'], ['label' => 'Find care', 'href' => '/find-care']],
                'cta' => ['label' => 'Start free trial', 'href' => '/start'],
                'contact' => [
                    'phone' => (string) config('clinicflow.website.phone'), 'email' => (string) config('clinicflow.website.email'),
                    'address' => (string) config('clinicflow.website.address'), 'hours' => 'Mon–Fri 08:00–17:00',
                ],
                'footer' => [
                    ...CmsPage::query()->where('published', true)->whereIn('slug', ['privacy', 'terms'])->get()->map(fn (CmsPage $p) => ['label' => $p->title, 'href' => "/pages/{$p->slug}"])->values()->all(),
                    ['label' => 'Sign in', 'href' => '/login'],
                ],
                'poweredBy' => false,
            ],
        ];
    }

    /**
     * Hide "Call" buttons until the provider has entered a phone number.
     *
     * @param  list<array<string, mixed>>  $sections
     * @return list<array<string, mixed>>
     */
    private static function withoutEmptyPhoneButtons(array $sections): array
    {
        foreach ($sections as $i => $section) {
            foreach (['primary', 'secondary'] as $button) {
                $href = $section[$button]['href'] ?? null;
                if (is_string($href) && str_starts_with($href, 'tel:') && preg_match('/\d/', $href) !== 1) {
                    unset($sections[$i][$button]);
                }
            }
        }

        return $sections;
    }
}
