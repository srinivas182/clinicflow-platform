<?php

declare(strict_types=1);

namespace App\Domains\Identity\Http\Middleware;

use App\Domains\Identity\Models\Membership;
use App\Domains\Platform\SupportDesk\SupportDesk;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Provider routes: the signed-in user must hold a usable membership for this
 * provider (active, and not past a locum expiry) — or be Clinic Flow support
 * under an active grant from this provider.
 */
class EnsureWorkspaceMember
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $provider = tenant();

        $usable = $user instanceof User && $provider !== null && Membership::query()
            ->where('user_id', $user->id)
            ->where('tenant_id', $provider->getTenantKey())
            ->usable()
            ->exists();

        // Clinic Flow support under a grant this practice gave (unexpired, not revoked, for this practice only).
        // SupportSessionGuard keeps such sessions read-only and logs every page.
        $grantId = $request->hasSession() ? $request->session()->get('support_grant_id') : null;
        $support = ! $usable && $user instanceof User && $provider !== null && (bool) $user->getAttribute('is_platform_admin') && is_numeric($grantId)
            && app(SupportDesk::class)->activeGrant((int) $grantId, (string) $provider->getTenantKey()) !== null;

        if (! $usable && ! $support) {
            Auth::guard('web')->logout();

            abort(403, 'You do not have access to this workspace.');
        }

        return $next($request);
    }
}
