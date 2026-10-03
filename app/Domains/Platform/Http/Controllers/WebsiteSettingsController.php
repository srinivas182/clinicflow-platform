<?php

declare(strict_types=1);

namespace App\Domains\Platform\Http\Controllers;

use App\Domains\Identity\Enums\Permission;
use App\Domains\Platform\Models\Setting;
use App\Domains\Platform\Models\SitePage;
use App\Domains\Platform\Support\Website\SiteData;
use App\Domains\Platform\Support\Website\SiteSections;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Settings → Website: the provider edits its pages and public details.
 */
class WebsiteSettingsController extends Controller
{
    public function index(): Response
    {
        $this->authorize(Permission::TEMPLATES_MANAGE);

        return Inertia::render('Settings/Website', [
            'pages' => SitePage::query()->orderByRaw('menu_order is null')->orderBy('menu_order')->get()
                ->map(fn (SitePage $p) => $p->only(['id', 'slug', 'title', 'meta_description', 'sections', 'published', 'menu_label', 'menu_order']))->values(),
            'details' => SiteData::providerTokens(),
            'tokens' => ['{name}', '{phone}', '{email}', '{address}', '{hours}'],
        ]);
    }

    public function updatePage(Request $request, SitePage $page): RedirectResponse
    {
        $this->authorize(Permission::TEMPLATES_MANAGE);
        $data = $request->validate([
            'title' => ['required', 'string', 'max:160'],
            'meta_description' => ['nullable', 'string', 'max:300'],
            'menu_label' => ['nullable', 'string', 'max:40'],
            'published' => ['boolean'],
            'sections' => ['required', 'array'],
        ]);
        abort_if($page->slug === 'home' && ! ($data['published'] ?? true), 422, 'The home page cannot be unpublished.');

        $page->forceFill([
            'title' => $data['title'], 'meta_description' => $data['meta_description'] ?? null, 'menu_label' => $data['menu_label'] ?? null,
            'published' => (bool) ($data['published'] ?? true), 'sections' => SiteSections::clean($data['sections']), 'updated_by' => $request->user()?->getAuthIdentifier(),
        ])->save();
        activity('website')->causedBy($request->user())->withProperties(['page' => $page->slug])->log('Website page saved');

        return back()->with('success', "{$page->menu_label} saved.");
    }

    public function updateDetails(Request $request): RedirectResponse
    {
        $this->authorize(Permission::TEMPLATES_MANAGE);
        $data = $request->validate([
            'phone' => ['nullable', 'string', 'max:20'], 'email' => ['nullable', 'email', 'max:120'],
            'address' => ['nullable', 'string', 'max:255'], 'hours' => ['nullable', 'string', 'max:160'],
        ]);
        Setting::put('branding', 'phone', (string) ($data['phone'] ?? ''));
        Setting::put('branding', 'email', (string) ($data['email'] ?? ''));
        Setting::put('branding', 'address', (string) ($data['address'] ?? ''));
        Setting::put('website', 'hours', (string) ($data['hours'] ?? ''));

        return back()->with('success', 'Contact details saved. They update every page that uses them.');
    }
}
