<?php

declare(strict_types=1);

namespace App\Domains\Platform\Http\Controllers\Admin;

use App\Domains\Documents\Support\TemplateRenderer;
use App\Domains\Platform\Models\CmsPage;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Super admin: website pages (HTML is sanitised on save and on display).
 */
class CmsAdminController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Admin/Pages', [
            'pages' => CmsPage::query()->orderBy('slug')->get(['id', 'slug', 'title', 'meta_description', 'body', 'published', 'updated_at']),
        ]);
    }

    public function save(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'id' => ['nullable', 'integer'],
            'slug' => ['required', 'string', 'max:80', 'regex:/^[a-z0-9-]+$/', Rule::unique('cms_pages', 'slug')->ignore($request->integer('id') ?: null)],
            'title' => ['required', 'string', 'max:160'],
            'meta_description' => ['nullable', 'string', 'max:300'],
            'body' => ['required', 'string', 'max:100000'],
            'published' => ['boolean'],
        ]);
        $reserved = ['admin', 'login', 'logout', 'start', 'pricing', 'workspaces', 'billing', 'api', 'find-care'];
        abort_if(in_array($data['slug'], $reserved, true), 422, 'That address is reserved.');

        CmsPage::query()->updateOrCreate(['id' => $data['id'] ?? null], [
            'slug' => $data['slug'], 'title' => $data['title'], 'meta_description' => $data['meta_description'] ?? null,
            'body' => TemplateRenderer::sanitise($data['body']), 'published' => (bool) ($data['published'] ?? false), 'updated_by' => $request->user()?->getAuthIdentifier(),
        ]);
        activity('platform')->causedBy($request->user())->withProperties(['slug' => $data['slug']])->log('Website page saved');

        return back()->with('success', 'Page saved.');
    }
}
