<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domains\Platform\Security\BotProtection;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Requires a passed Cloudflare Turnstile check on public forms (sign-in, sign-up, forgot password,
 * patient portal sign-in) when bot protection is switched on.
 */
class VerifyHuman
{
    public const FIELD = 'cf-turnstile-response';

    public function handle(Request $request, Closure $next): Response
    {
        if (! BotProtection::enabled()) {
            return $next($request);
        }
        $token = (string) $request->input(self::FIELD, '');
        if ($token === '' || BotProtection::verify($token, $request->ip()) === false) {
            throw ValidationException::withMessages(['human' => 'Please complete the security check and try again.']);
        }

        return $next($request);
    }
}
