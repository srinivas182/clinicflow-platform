<?php

declare(strict_types=1);

namespace App\Domains\Identity\Actions;

use App\Domains\Identity\Models\Membership;
use App\Domains\Identity\Models\WorkspaceHandoff;
use App\Domains\Platform\Models\Provider;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Issues a single-use, 60-second token that signs the user in on the provider's
 * own domain, and returns the URL to redirect to.
 */
class CreateWorkspaceHandoff
{
    public function handle(User $user, Provider $provider): string
    {
        $membership = Membership::query()
            ->where('user_id', $user->id)
            ->where('tenant_id', $provider->id)
            ->first();

        if (! $membership instanceof Membership || ! $membership->isUsable()) {
            throw new AuthorizationException('You do not have access to this workspace.');
        }

        $domain = $provider->domains()->value('domain');

        if (! is_string($domain)) {
            throw new RuntimeException('This workspace has no address yet.');
        }

        $token = Str::random(64);

        WorkspaceHandoff::create([
            'token_hash' => hash('sha256', $token),
            'user_id' => $user->id,
            'tenant_id' => $provider->id,
            'expires_at' => now()->addSeconds(60),
        ]);

        activity('auth')->causedBy($user)->withProperties(['provider' => $provider->id])->log('Opened workspace');

        $scheme = parse_url((string) config('app.url'), PHP_URL_SCHEME);

        return (is_string($scheme) ? $scheme : 'https')."://{$domain}/auth/handoff/{$token}";
    }
}
