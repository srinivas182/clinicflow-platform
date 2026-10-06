<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domains\Identity\Actions\Authenticator;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * Super admins, owners and practice admins must set up an authenticator app before using
 * anything else (only the security page and sign-out stay available until then).
 */
class RequireAuthenticator
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::guard('web')->user();
        if (! $user instanceof User || $user->totp_confirmed_at !== null || $request->routeIs('account.security*', 'logout', 'login*')
            || ! app(Authenticator::class)->required($user)) {
            return $next($request);
        }
        $url = rtrim((string) config('app.url'), '/').'/account/security';

        return $request->header('X-Inertia') !== null ? Inertia::location($url) : redirect()->away($url);
    }
}
