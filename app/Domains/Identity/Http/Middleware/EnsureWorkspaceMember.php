<?php

declare(strict_types=1);

namespace App\Domains\Identity\Http\Middleware;

use App\Domains\Identity\Models\Membership;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Provider routes: the signed-in user must hold a usable membership for this
 * provider (active, and not past a locum expiry).
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

        if (! $usable) {
            Auth::guard('web')->logout();

            abort(403, 'You do not have access to this workspace.');
        }

        return $next($request);
    }
}
