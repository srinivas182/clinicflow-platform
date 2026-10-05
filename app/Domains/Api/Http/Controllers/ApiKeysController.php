<?php

declare(strict_types=1);

namespace App\Domains\Api\Http\Controllers;

use App\Domains\Api\Actions\ApiKeys;
use App\Domains\Api\Webhooks\Webhooks;
use App\Domains\Identity\Enums\Permission;
use App\Domains\Identity\Enums\StaffRole;
use App\Domains\Identity\Models\Membership;
use App\Domains\Platform\Models\Provider;
use App\Domains\Platform\Models\Subscription;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Settings → API: keys (owner and practice admin only) and recent requests.
 */
class ApiKeysController extends Controller
{
    public function index(): Response
    {
        $this->authorize(Permission::SETTINGS_MANAGE);
        $provider = tenant();
        $package = $provider instanceof Provider ? Subscription::query()->where('tenant_id', $provider->id)->latest('id')->first()?->package : null;

        return Inertia::render('Settings/Api', [
            'enabled' => $package !== null && $package->hasFeature('api'),
            'scopes' => ApiKeys::SCOPES,
            'keys' => DB::table('api_keys')->orderByDesc('id')->get()->map(fn ($k) => ['id' => $k->id, 'name' => $k->name, 'prefix' => $k->prefix, 'scopes' => json_decode((string) $k->scopes, true),
                'ips' => $k->allowed_ips === null ? [] : json_decode((string) $k->allowed_ips, true), 'expires' => $k->expires_at, 'lastUsed' => $k->last_used_at, 'revoked' => $k->revoked_at !== null])->values(),
            'requests' => DB::table('api_requests')->join('api_keys', 'api_keys.id', '=', 'api_requests.api_key_id')->orderByDesc('api_requests.id')->limit(50)
                ->get(['api_requests.*', 'api_keys.name'])->map(fn ($r) => ['key' => $r->name, 'method' => $r->method, 'path' => $r->path, 'status' => $r->status, 'ip' => $r->ip, 'at' => $r->created_at])->values(),
            'events' => Webhooks::EVENTS,
            'endpoints' => DB::table('webhook_endpoints')->orderByDesc('id')->get()->map(fn ($e) => ['id' => $e->id, 'url' => $e->url, 'events' => json_decode((string) $e->events, true),
                'active' => (bool) $e->active, 'failures' => (int) $e->consecutive_failures, 'disabledAt' => $e->disabled_at])->values(),
            'deliveries' => DB::table('webhook_deliveries')->join('webhook_endpoints', 'webhook_endpoints.id', '=', 'webhook_deliveries.webhook_endpoint_id')->orderByDesc('webhook_deliveries.id')->limit(50)
                ->get(['webhook_deliveries.id', 'webhook_deliveries.event', 'webhook_deliveries.status', 'webhook_deliveries.attempts', 'webhook_deliveries.response_status', 'webhook_deliveries.last_error', 'webhook_deliveries.created_at', 'webhook_endpoints.url'])
                ->map(fn ($d) => ['id' => $d->id, 'event' => $d->event, 'status' => $d->status, 'attempts' => (int) $d->attempts, 'response' => $d->response_status, 'error' => $d->last_error, 'at' => $d->created_at, 'url' => $d->url])->values(),
            'docs' => url('/api/v1/openapi.json'),
            'base' => url('/api/v1'),
        ]);
    }

    public function store(Request $request, ApiKeys $keys): RedirectResponse
    {
        $this->authorizeKeyAdmin($request);
        $data = $request->validate(['name' => ['required', 'string', 'max:80'], 'scopes' => ['required', 'array', 'min:1'], 'scopes.*' => ['string'],
            'allowed_ips' => ['nullable', 'string', 'max:500'], 'expires_at' => ['nullable', 'date']]);
        $ips = array_values(array_filter(array_map('trim', preg_split('/[\s,]+/', (string) ($data['allowed_ips'] ?? '')) ?: [])));
        $created = $keys->create($data['name'], array_values($data['scopes']), $ips, $data['expires_at'] ?? null, (int) $request->user()?->getAuthIdentifier());

        // Shown once only; it is never stored in readable form.
        return back()->with('success', 'API key created. Copy it now — it will not be shown again.')->with('new_api_key', $created['key']);
    }

    public function revoke(Request $request, int $key, ApiKeys $keys): RedirectResponse
    {
        $this->authorizeKeyAdmin($request);
        $keys->revoke($key);

        return back()->with('success', 'API key revoked.');
    }

    public function webhook(Request $request, string $action, Webhooks $webhooks): RedirectResponse
    {
        $this->authorizeKeyAdmin($request);
        if ($action === 'add') {
            $data = $request->validate(['url' => ['required', 'url', 'max:500'], 'events' => ['required', 'array', 'min:1'], 'events.*' => ['string']]);
            $created = $webhooks->addEndpoint($data['url'], array_values($data['events']), (int) $request->user()?->getAuthIdentifier());

            return back()->with('success', 'Webhook added. Copy the signing secret now — it will not be shown again.')->with('new_api_key', $created['secret']);
        }
        $id = $request->integer('endpoint_id');
        abort_unless(DB::table('webhook_endpoints')->where('id', $id)->exists(), 404);
        match ($action) {
            'on' => $webhooks->setActive($id, true),
            'off' => $webhooks->setActive($id, false),
            'test' => DB::table('webhook_endpoints')->where('id', $id)->where('active', true)->exists()
                ? $webhooks->dispatch('webhook.test', ['message' => 'Test from Clinic Flow'])
                : abort(422, 'Switch the webhook on first.'),
            default => abort(404),
        };

        return back()->with('success', $action === 'test' ? 'Test queued; it is sent within a minute.' : 'Webhook updated.');
    }

    private function authorizeKeyAdmin(Request $request): void
    {
        $this->authorize(Permission::SETTINGS_MANAGE);
        $role = Membership::query()->where('tenant_id', tenant('id'))->where('user_id', $request->user()?->getAuthIdentifier())->value('role');
        abort_unless(in_array($role instanceof StaffRole ? $role : StaffRole::tryFrom((string) $role), [StaffRole::Owner, StaffRole::PracticeAdmin], true), 403, 'Only the owner or a practice admin can manage API keys.');
    }
}
