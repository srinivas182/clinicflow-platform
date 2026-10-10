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
                'signIn' => ['label' => 'Staff Sign In', 'href' => rtrim((string) config('app.url'), '/').'/login?practice='.tenant('id')],
                'cta' => ['label' => 'Patient portal', 'href' => '/portal'],
                'contact' => ['phone' => $tokens['phone'], 'email' => $tokens['email'], 'address' => $tokens['address'], 'hours' => $tokens['hours']],
                'footer' => [['label' => 'Staff Sign In', 'href' => rtrim((string) config('app.url'), '/').'/login?practice='.tenant('id')]],
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
        return [
            'page' => ['title' => $page->title, 'description' => (string) $page->meta_description],
            'sections' => $page->sections ?? [],
            'site' => self::platformSite(),
        ];
    }

    /**
     * The platform website's header, menu and footer (shared by every public page, including pricing).
     *
     * @return array<string, mixed>
     */
    public static function platformSite(): array
    {
        $pages = CmsPage::query()->where('published', true)->get(['slug', 'title'])->keyBy('slug');
        $solutions = collect([['for-clinics', 'Clinics'], ['for-doctors', 'Individual Doctors'], ['for-pharmacies-and-labs', 'Pharmacies & Labs'], ['for-patients', 'Patients']])
            ->filter(fn (array $p) => $pages->has($p[0]))->map(fn (array $p) => ['label' => $p[1], 'href' => "/pages/{$p[0]}"])->values()->all();
        $page = fn (string $slug, string $label) => $pages->has($slug) ? ['label' => $label, 'href' => "/pages/{$slug}"] : null;

        return [
            'name' => 'Dr Business Flow', 'colour' => '#0F7C74', 'platform' => true,
            'menu' => array_values(array_filter([
                $page('about', 'About'),
                $solutions === [] ? null : ['label' => 'Solutions', 'href' => $solutions[0]['href'], 'children' => $solutions],
                ['label' => 'Find Care', 'href' => '/find-care'],
                ['label' => 'Pricing', 'href' => '/pricing'],
                $page('contact', 'Contact Us'),
            ])),
            'signIn' => ['label' => 'Sign In', 'href' => '/login'],
            'cta' => ['label' => 'Start Free Trial', 'href' => '/start'],
            'contact' => [
                'phone' => (string) config('clinicflow.website.phone'), 'email' => (string) config('clinicflow.website.email'),
                'address' => (string) config('clinicflow.website.address'), 'hours' => 'Mon–Fri 08:00–17:00',
            ],
            'footer' => array_values(array_filter([
                ...$solutions,
                $page('about', 'About'), $page('contact', 'Contact Us'), $page('privacy', 'Privacy'), $page('terms', 'Terms'),
                ['label' => 'Sign In', 'href' => '/login'],
            ])),
            'poweredBy' => false,
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
