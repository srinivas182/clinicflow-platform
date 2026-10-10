<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domains\Branches\Support\BranchContext;
use App\Domains\Platform\Branding\Brands;
use App\Domains\Platform\Models\Provider;
use App\Domains\Platform\Security\BotProtection;
use App\Domains\Wallet\Support\WalletSettings;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Middleware;

/**
 * Shares app-wide props with every Inertia page.
 */
class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    /**
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $provider = tenant();
        $user = $request->user();

        return [
            ...parent::share($request),
            'app' => [
                'name' => config('app.name'),
                'version' => config('clinicflow.version'),
            ],
            // Developer credit, shown in every footer; practices cannot change it.
            'credit' => ['text' => (string) WalletSettings::get('platform.credit_text'), 'url' => (string) WalletSettings::get('platform.credit_url')],
            'centralUrl' => rtrim((string) config('app.url'), '/'),
            'auth' => [
                'user' => $user instanceof User ? ['name' => $user->name, 'email' => $user->email] : null,
            ],
            'flash' => [
                'success' => $request->session()->get('success'),
                'error' => $request->session()->get('error'),
                // A newly created API key, shown once (never stored readable).
                'newApiKey' => $request->session()->get('new_api_key'),
            ],
            // White-label: the practice's brand, or the brand from a brand sign-up link (null = Dr Business Flow).
            // Real-time updates (Reverb); null when broadcasting is off, so pages fall back to polling.
            'realtime' => config('broadcasting.default') === 'reverb' && $provider instanceof Provider ? ['key' => (string) config('broadcasting.connections.reverb.key'),
                'host' => (string) config('broadcasting.connections.reverb.options.host'), 'port' => (int) config('broadcasting.connections.reverb.options.port'),
                'tls' => (bool) config('broadcasting.connections.reverb.options.useTLS'), 'provider' => (string) $provider->id,
                // Staff, patients (portal) and the waiting-room display each sign in to channels differently.
                'auth' => $request->is('my', 'my/*') ? '/my/broadcasting/auth'
                    : ($request->routeIs('display') ? '/display/'.$request->route('token').'/broadcasting/auth' : '/broadcasting/auth')] : null,
            // Cloudflare Turnstile on public forms (Admin → Security).
            'botProtection' => BotProtection::enabled() ? ['siteKey' => BotProtection::siteKey()] : null,
            'brand' => app(Brands::class)->forDisplay($provider instanceof Provider ? $provider : null,
                is_string($request->cookie(Brands::COOKIE)) ? (string) $request->cookie(Brands::COOKIE) : null),
            'provider' => $provider instanceof Provider ? [
                'id' => $provider->id,
                'name' => $provider->name,
                'type' => $provider->type->value,
                'typeLabel' => $provider->type->label(),
            ] : null,
            // Branch switcher: only shown once a practice has more than one branch.
            'branches' => $provider instanceof Provider && $user instanceof User && BranchContext::filterId() !== null
                ? ['options' => BranchContext::options(), 'current' => BranchContext::current()]
                : null,
        ];
    }
}
