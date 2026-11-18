<?php

declare(strict_types=1);

namespace App\Domains\Platform\Http\Controllers;

use App\Domains\Documents\Support\TemplateRenderer;
use App\Domains\Platform\Enums\ProviderStatus;
use App\Domains\Platform\Models\CmsPage;
use App\Domains\Platform\Models\Provider;
use App\Domains\Platform\Support\Website\SiteData;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Public website: CMS pages and the "find care" directory of verified providers.
 */
class PublicSiteController extends Controller
{
    /** Search engines may index the public website, never the signed-in or admin areas. */
    public function robots(): \Illuminate\Http\Response
    {
        $private = ['/admin', '/login', '/workspaces', '/account', '/translate', '/reseller', '/groups', '/locum', '/auth', '/forgot-password', '/reset-password'];

        return response('User-agent: *'."\n".implode("\n", array_map(fn (string $path) => "Disallow: {$path}", $private))."\n", 200,
            ['Content-Type' => 'text/plain; charset=utf-8', 'Cache-Control' => 'public, max-age=3600']);
    }

    public function home(): Response
    {
        $page = CmsPage::query()->where('slug', 'home')->where('published', true)->first();

        return $page instanceof CmsPage
            ? $this->render($page)
            : Inertia::render('Welcome', ['region' => config('clinicflow.region')]);
    }

    public function page(string $slug): Response|RedirectResponse
    {
        $page = CmsPage::query()->where('slug', $slug)->where('published', true)->first();
        if (! $page instanceof CmsPage) {
            // Old addresses redirect to the page's new one (beta #295).
            $to = DB::connection((string) config('tenancy.database.central_connection'))->table('cms_redirects')->where('from_slug', $slug)->value('to_slug');
            abort_if($to === null, 404);

            return redirect("/pages/{$to}", 301);
        }

        return $this->render($page);
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

        return Inertia::render('Public/Directory', ['providers' => $rows, 'q' => $term, 'type' => $type, 'site' => SiteData::platformSite()]);
    }

    private function render(CmsPage $page): Response
    {
        if ($page->sections !== null && $page->sections !== []) {
            return Inertia::render('Public/Site', SiteData::platform($page));
        }

        return Inertia::render('Public/Page', [
            'site' => SiteData::platformSite(),
            'title' => $page->title,
            'description' => $page->meta_description,
            'html' => TemplateRenderer::sanitise($page->body),
        ]);
    }
}
