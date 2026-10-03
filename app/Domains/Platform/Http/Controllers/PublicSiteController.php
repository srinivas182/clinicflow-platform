<?php

declare(strict_types=1);

namespace App\Domains\Platform\Http\Controllers;

use App\Domains\Documents\Support\TemplateRenderer;
use App\Domains\Platform\Enums\ProviderStatus;
use App\Domains\Platform\Models\CmsPage;
use App\Domains\Platform\Models\Provider;
use App\Domains\Platform\Support\Website\SiteData;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Public website: CMS pages and the "find care" directory of verified providers.
 */
class PublicSiteController extends Controller
{
    public function home(): Response
    {
        $page = CmsPage::query()->where('slug', 'home')->where('published', true)->first();

        return $page instanceof CmsPage
            ? $this->render($page)
            : Inertia::render('Welcome', ['region' => config('clinicflow.region')]);
    }

    public function page(string $slug): Response
    {
        return $this->render(CmsPage::query()->where('slug', $slug)->where('published', true)->firstOrFail());
    }

    public function directory(Request $request): Response
    {
        $term = trim($request->string('q')->toString());
        $type = $request->string('type')->toString();
        $rows = [];

        $query = Provider::query()->whereIn('status', [ProviderStatus::Trial->value, ProviderStatus::Active->value])
            ->when($term !== '', fn ($q) => $q->where('name', 'like', "%{$term}%"))
            ->when($type !== '', fn ($q) => $q->where('type', $type))
            ->orderBy('name')->limit(100)->get();

        foreach ($query as $provider) {
            if ($provider instanceof Provider) {
                $rows[] = ['name' => $provider->name, 'type' => $provider->type->label(), 'address' => $provider->domains()->value('domain')];
            }
        }

        return Inertia::render('Public/Directory', ['providers' => $rows, 'q' => $term, 'type' => $type]);
    }

    private function render(CmsPage $page): Response
    {
        if ($page->sections !== null && $page->sections !== []) {
            return Inertia::render('Public/Site', SiteData::platform($page));
        }

        return Inertia::render('Public/Page', [
            'title' => $page->title,
            'description' => $page->meta_description,
            'html' => TemplateRenderer::sanitise($page->body),
        ]);
    }
}
