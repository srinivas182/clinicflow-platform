<?php

declare(strict_types=1);

namespace App\Domains\Api\Http\Middleware;

use App\Domains\Api\Actions\ApiKeys;
use App\Domains\Platform\Models\Provider;
use App\Domains\Platform\Models\Subscription;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

/**
 * Public API guard: valid key for this practice, the required scope, the
 * package's API feature, the key's IP allowlist, and a per-key rate limit.
 * Every request is logged against the key.
 */
class AuthenticateApiKey
{
    public function handle(Request $request, Closure $next, string $scope): Response
    {
        $provider = tenant();
        $package = $provider instanceof Provider ? Subscription::query()->where('tenant_id', $provider->id)->latest('id')->first()?->package : null;
        if ($package === null || ! $package->hasFeature('api')) {
            return $this->error(403, 'API access is not included in this practice\'s package.');
        }
        $key = app(ApiKeys::class)->authenticate((string) $request->bearerToken(), $request->ip());
        if ($key === null) {
            return $this->error(401, 'Missing, invalid, expired or revoked API key, or this IP address is not allowed.');
        }
        if (! ApiKeys::allows($key, $scope)) {
            return $this->log($key, $request, $this->error(403, "This key does not have the {$scope} permission."));
        }
        $limit = (int) config('clinicflow.api.per_minute', 60);
        if (! RateLimiter::attempt('api-key:'.tenant('id').':'.$key->id, $limit, fn () => true, 60)) {
            return $this->log($key, $request, $this->error(429, "Rate limit: {$limit} requests per minute."));
        }

        return $this->log($key, $request, $next($request));
    }

    private function log(\stdClass $key, Request $request, Response $response): Response
    {
        DB::table('api_requests')->insert(['api_key_id' => $key->id, 'method' => $request->method(), 'path' => mb_substr('/'.$request->path(), 0, 255),
            'status' => $response->getStatusCode(), 'ip' => $request->ip(), 'created_at' => now()]);
        DB::table('api_keys')->where('id', $key->id)->update(['last_used_at' => now()]);

        return $response;
    }

    private function error(int $status, string $message): Response
    {
        return response()->json(['error' => ['status' => $status, 'message' => $message]], $status);
    }
}
