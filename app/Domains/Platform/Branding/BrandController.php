<?php

declare(strict_types=1);

namespace App\Domains\Platform\Branding;

use App\Domains\Platform\Models\Provider;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Super admin: white-label brands and which practices belong to them.
 */
class BrandController extends Controller
{
    public function index(): Response
    {
        $central = DB::connection((string) config('tenancy.database.central_connection'));

        return Inertia::render('Admin/Brands', [
            'brands' => $central->table('brands')->orderBy('name')->get()->map(fn ($b) => [
                'id' => $b->id, 'name' => $b->name, 'slug' => $b->slug, 'logo' => $b->logo, 'primary' => $b->primary_color, 'accent' => $b->accent_color,
                'supportEmail' => $b->support_email, 'supportPhone' => $b->support_phone, 'footer' => $b->footer_text, 'poweredBy' => (bool) $b->powered_by,
                'practiceDomain' => $b->practice_domain, 'resellerId' => $b->reseller_id, 'active' => (bool) $b->active,
                'signupLink' => rtrim((string) config('app.url'), '/').'/?brand='.$b->slug,
                'practices' => Provider::query()->where('brand_id', $b->id)->count(),
            ])->values(),
            'resellers' => $central->table('resellers')->orderBy('name')->get(['id', 'name']),
            'providers' => Provider::query()->orderBy('name')->limit(500)->get()->map(fn ($p) => ['id' => (string) $p->getKey(), 'name' => (string) $p->getAttribute('name'), 'brandId' => $p->getAttribute('brand_id')])->values(),
        ]);
    }

    public function save(Request $request, Brands $brands): RedirectResponse
    {
        $data = $request->validate([
            'id' => ['nullable', 'integer'], 'name' => ['required', 'string', 'max:80'], 'slug' => ['required', 'string', 'max:40'],
            'primary_color' => ['required', 'string'], 'accent_color' => ['required', 'string'], 'support_email' => ['nullable', 'email'], 'support_phone' => ['nullable', 'string', 'max:20'],
            'footer_text' => ['nullable', 'string', 'max:300'], 'powered_by' => ['boolean'], 'practice_domain' => ['nullable', 'string', 'max:120'], 'reseller_id' => ['nullable', 'integer'],
            'active' => ['boolean'], 'logo' => ['nullable', 'file', 'max:200'],
        ]);
        $logo = $request->file('logo');
        $brands->save(isset($data['id']) ? (int) $data['id'] : null, [
            'name' => $data['name'], 'slug' => $data['slug'], 'primary_color' => $data['primary_color'], 'accent_color' => $data['accent_color'],
            'support_email' => $data['support_email'] ?? null, 'support_phone' => $data['support_phone'] ?? null, 'footer_text' => $data['footer_text'] ?? null,
            'powered_by' => (bool) ($data['powered_by'] ?? true), 'practice_domain' => $data['practice_domain'] ?? null,
            'reseller_id' => isset($data['reseller_id']) ? (int) $data['reseller_id'] : null, 'active' => (bool) ($data['active'] ?? true),
        ], $logo instanceof UploadedFile ? $logo : null);

        return back()->with('success', 'Brand saved.');
    }

    public function assign(Request $request, Brands $brands): RedirectResponse
    {
        $data = $request->validate(['provider_id' => ['required', 'string'], 'brand_id' => ['nullable', 'integer']]);
        $brands->assign($data['provider_id'], isset($data['brand_id']) ? (int) $data['brand_id'] : null);

        return back()->with('success', 'Practice brand updated. Its web address stays the same.');
    }
}
