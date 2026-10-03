<?php

declare(strict_types=1);

namespace App\Domains\Portal\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Portal pages need a verified cell number in the session (provider domain only).
 */
class EnsurePortalPatient
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! is_string($request->session()->get('portal_cell'))) {
            return redirect()->route('portal.login');
        }

        return $next($request);
    }
}
