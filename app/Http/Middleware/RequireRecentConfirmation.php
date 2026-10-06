<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * Step-up: sensitive actions (exports, API keys, break-glass, support access grants) need the
 * password or an authenticator code re-entered in the last 15 minutes.
 * Usage: ->middleware('step-up') or ->middleware('step-up:grant') to apply only to that {action}.
 */
class RequireRecentConfirmation
{
    public const SESSION_KEY = 'auth.stepped_up_at';

    public const MINUTES = 15;

    public function handle(Request $request, Closure $next, ?string $onlyAction = null): Response
    {
        if ($onlyAction !== null && (string) $request->route('action') !== $onlyAction) {
            return $next($request);
        }
        $at = (int) $request->session()->get(self::SESSION_KEY, 0);
        if ($request->user() === null || $at >= now()->subMinutes(self::MINUTES)->getTimestamp()) {
            return $next($request);
        }
        $intended = $request->isMethod('GET') ? $request->fullUrl() : (string) url()->previous();
        $request->session()->put('url.step_up_intended', $intended);
        $url = url('/confirm-identity');
        if ($request->expectsJson() && $request->header('X-Inertia') === null) {
            return response()->json(['message' => 'Please confirm it is you first.', 'confirm' => $url], 423);
        }

        return $request->header('X-Inertia') !== null ? Inertia::location($url) : redirect($url);
    }
}
