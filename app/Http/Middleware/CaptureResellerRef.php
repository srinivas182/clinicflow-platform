<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domains\Platform\Branding\Brands;
use App\Domains\Platform\Resellers\ResellerProgramme;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Remembers a reseller's ?ref=CODE for 30 days so a later sign-up is credited to them.
 */
class CaptureResellerRef
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $code = $request->query('ref');
        if (is_string($code) && app(ResellerProgramme::class)->validCode($code) !== null) {
            $response->headers->setCookie(cookie(ResellerProgramme::COOKIE, strtoupper($code), 60 * 24 * 30));
        }
        // White-label sign-up link: ?brand=partnerhealth
        $brand = $request->query('brand');
        if (is_string($brand) && app(Brands::class)->bySlug($brand) !== null) {
            $response->headers->setCookie(cookie(Brands::COOKIE, strtolower($brand), 60 * 24 * 30));
        }

        return $response;
    }
}
