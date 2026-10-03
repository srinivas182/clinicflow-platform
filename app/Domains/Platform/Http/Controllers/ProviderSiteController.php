<?php

declare(strict_types=1);

namespace App\Domains\Platform\Http\Controllers;

use App\Domains\Platform\Models\SitePage;
use App\Domains\Platform\Support\Website\SiteData;
use App\Http\Controllers\Controller;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The provider's public website on its own address.
 */
class ProviderSiteController extends Controller
{
    public function show(string $slug = 'home'): Response
    {
        $page = SitePage::query()->where('slug', $slug)->where('published', true)->firstOrFail();

        return Inertia::render('Site/Show', SiteData::provider($page));
    }
}
