<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Saving a payment, video, SMS/email or WhatsApp supplier in Live mode moves real money or reaches
 * real people, so it needs a recent identity confirmation (beta #46, #67, #278). Test mode does not.
 */
class RequireStepUpForLive
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->input('mode') !== 'live') {
            return $next($request);
        }

        return app(RequireRecentConfirmation::class)->handle($request, $next);
    }
}
