<?php

declare(strict_types=1);

namespace App\Domains\Api\Http\Controllers;

use App\Domains\Api\Actions\ApiKeys;
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

    private function authorizeKeyAdmin(Request $request): void
    {
        $this->authorize(Permission::SETTINGS_MANAGE);
        $role = Membership::query()->where('tenant_id', tenant('id'))->where('user_id', $request->user()?->getAuthIdentifier())->value('role');
        abort_unless(in_array($role instanceof StaffRole ? $role : StaffRole::tryFrom((string) $role), [StaffRole::Owner, StaffRole::PracticeAdmin], true), 403, 'Only the owner or a practice admin can manage API keys.');
    }
}
