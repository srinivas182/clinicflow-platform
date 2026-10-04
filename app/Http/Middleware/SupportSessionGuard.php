<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domains\Platform\SupportDesk\SupportDesk;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Inside a practice under a support grant: read-only, logged page by page, and
 * ended as soon as the grant expires or the practice revokes it.
 */
class SupportSessionGuard
{
    public function handle(Request $request, Closure $next): Response
    {
        $grantId = $request->hasSession() ? $request->session()->get('support_grant_id') : null;
        if (! is_numeric($grantId)) {
            return $next($request);
        }
        $provider = tenant();
        if ($provider === null || app(SupportDesk::class)->activeGrant((int) $grantId, (string) $provider->getTenantKey()) === null) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();

            return redirect('/')->with('error', 'Support access has ended.');
        }
        if (! in_array($request->method(), ['GET', 'HEAD'], true)) {
            abort(403, 'Support access is read-only.');
        }
        activity('support')->causedBy($request->user())->withProperties(['path' => $request->path(), 'grant' => (int) $grantId])->log('Support viewed a page');

        return $next($request);
    }
}
