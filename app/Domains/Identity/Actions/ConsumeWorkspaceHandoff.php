<?php

declare(strict_types=1);

namespace App\Domains\Identity\Actions;

use App\Domains\Identity\Models\WorkspaceHandoff;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * Runs on the provider domain: exchanges a handoff token for the user, once.
 */
class ConsumeWorkspaceHandoff
{
    public function handle(string $token, string $providerId): User
    {
        $handoff = WorkspaceHandoff::query()->where('token_hash', hash('sha256', $token))->first();

        if (! $handoff instanceof WorkspaceHandoff
            || $handoff->used_at !== null
            || $handoff->expires_at->isPast()
            || $handoff->tenant_id !== $providerId) {
            throw new AuthorizationException('This sign-in link is no longer valid.');
        }

        $handoff->forceFill(['used_at' => now()])->save();

        return User::query()->findOrFail($handoff->user_id);
    }
}
