<?php

declare(strict_types=1);

namespace App\Domains\Platform\Http\Middleware;

use App\Domains\Platform\Models\Provider;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Read-only and suspended providers can view everything but change nothing.
 */
class EnsureProviderWritable
{
    public function handle(Request $request, Closure $next): Response
    {
        $provider = tenant();

        if (! $request->isMethodSafe() && $provider instanceof Provider && ! $provider->status->canWrite()) {
            abort(423, 'This workspace is read-only. Renew the subscription to make changes.');
        }

        return $next($request);
    }
}
