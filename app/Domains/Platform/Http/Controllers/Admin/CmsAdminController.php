<?php

declare(strict_types=1);

namespace App\Domains\Platform\Http\Controllers\Admin;

use App\Domains\Documents\Support\TemplateRenderer;
use App\Domains\Platform\Models\CmsPage;
use App\Domains\Platform\Support\Website\SiteSections;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Super admin: website pages (HTML is sanitised on save and on display).
 */
class CmsAdminController extends Controller
{
    /** Pages that cannot be archived or moved. */
    public const PROTECTED = ['home', 'privacy', 'terms'];

    public function index(): Response
    {
        return Inertia::render('Admin/Pages', [
            'pages' => CmsPage::query()->orderByRaw('archived_at is not null')->orderByRaw('menu_order is null')->orderBy('menu_order')->orderBy('slug')
                ->get(['id', 'slug', 'title', 'meta_description', 'body', 'sections', 'menu_label', 'menu_order', 'published', 'archived_at', 'updated_at']),
            'protected' => self::PROTECTED,
        ]);
    }

    public function save(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'id' => ['nullable', 'integer'],
            'slug' => ['required', 'string', 'max:80', 'regex:/^[a-z0-9-]+$/', Rule::unique('cms_pages', 'slug')->ignore($request->integer('id') ?: null)],
            'title' => ['required', 'string', 'max:160'],
            'meta_description' => ['nullable', 'string', 'max:300'],
            'body' => ['nullable', 'string', 'max:100000'],
            'sections' => ['nullable', 'array'],
            'menu_label' => ['nullable', 'string', 'max:40'],
            // Each menu position once, so the order is clear (beta #294).
            'menu_order' => ['nullable', 'integer', 'min:0', 'max:50', Rule::unique('cms_pages', 'menu_order')->ignore($request->integer('id') ?: null)],
            'published' => ['boolean'],
        ], ['menu_order.unique' => 'Another page already uses this menu position.']);
        // Reserved addresses: a message next to the address box, not an error screen (beta #291).
        $reserved = ['admin', 'login', 'logout', 'start', 'pricing', 'workspaces', 'billing', 'api', 'find-care', 'status', 'robots.txt'];
        if (in_array($data['slug'], $reserved, true)) {
            throw ValidationException::withMessages(['slug' => 'That address is used by the platform. Choose another, e.g. '.$data['slug'].'-info.']);
        }

        $old = isset($data['id']) ? CmsPage::query()->whereKey($data['id'])->value('slug') : null;
        if (is_string($old) && in_array($old, self::PROTECTED, true) && $old !== $data['slug']) {
            throw ValidationException::withMessages(['slug' => 'The Home, Privacy and Terms pages keep their address.']);
        }
        CmsPage::query()->updateOrCreate(['id' => $data['id'] ?? null], [
            'slug' => $data['slug'], 'title' => $data['title'], 'meta_description' => $data['meta_description'] ?? null,
            'body' => TemplateRenderer::sanitise((string) ($data['body'] ?? '')), 'published' => (bool) ($data['published'] ?? false), 'updated_by' => $request->user()?->getAuthIdentifier(),
            'sections' => isset($data['sections']) ? SiteSections::clean($data['sections']) : null,
            'menu_label' => $data['menu_label'] ?? null, 'menu_order' => $data['menu_order'] ?? null,
        ]);
        // A changed address keeps working: the old one redirects to the new one (beta #295).
        if (is_string($old) && $old !== $data['slug']) {
            $redirects = DB::connection((string) config('tenancy.database.central_connection'))->table('cms_redirects');
            (clone $redirects)->where('from_slug', $data['slug'])->delete();
            (clone $redirects)->where('to_slug', $old)->update(['to_slug' => $data['slug'], 'updated_at' => now()]);
            (clone $redirects)->updateOrInsert(['from_slug' => $old], ['to_slug' => $data['slug'], 'created_at' => now(), 'updated_at' => now()]);
        }
        activity('platform')->causedBy($request->user())->withProperties(['slug' => $data['slug'], 'old_slug' => $old])->log('Website page saved');

        return back()->with('success', is_string($old) && $old !== $data['slug'] ? "Page saved. /pages/{$old} now redirects here." : 'Page saved.');
    }

    /**
     * Archive (or restore) a page (beta #296): unpublished and out of the menu, kept for history.
     * Home, Privacy and Terms are protected.
     */
    public function archive(Request $request, CmsPage $page): RedirectResponse
    {
        if (in_array($page->slug, self::PROTECTED, true)) {
            return back()->with('error', 'The Home, Privacy and Terms pages cannot be archived.');
        }
        $restore = $page->archived_at !== null;
        $page->forceFill($restore ? ['archived_at' => null] : ['archived_at' => now(), 'published' => false, 'menu_order' => null])->save();
        activity('platform')->causedBy($request->user())->withProperties(['slug' => $page->slug])->log($restore ? 'Website page restored' : 'Website page archived');

        return back()->with('success', $restore ? 'Page restored as a draft.' : 'Page archived. It is no longer on the website.');
    }
}
