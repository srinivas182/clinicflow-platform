<?php

declare(strict_types=1);

namespace App\Http\Controllers\Provider;

use App\Http\Controllers\Controller;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Home page of a provider's own address (subdomain or custom domain).
 * Runs inside the provider's tenancy context.
 */
class ProviderHomeController extends Controller
{
    public function __invoke(): Response
    {
        return Inertia::render('Provider/Home');
    }
}
