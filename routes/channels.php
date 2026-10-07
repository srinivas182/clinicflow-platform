<?php

declare(strict_types=1);

use App\Domains\Identity\Models\Membership;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

/*
 * Real-time channels. Every channel is private and scoped to one practice: only active staff of
 * that practice may listen, and only on that practice's own domain.
 */
$isStaff = fn (User $user, string $providerId): bool => (string) tenant('id') === $providerId
    && Membership::query()->where('tenant_id', $providerId)->where('user_id', $user->id)->usable()->exists();

Broadcast::channel('provider.{providerId}.queue', fn (User $user, string $providerId) => $isStaff($user, $providerId));

Broadcast::channel('provider.{providerId}.chat.{threadId}', fn (User $user, string $providerId, string $threadId) => $isStaff($user, $providerId));

Broadcast::channel('provider.{providerId}.call.{appointmentId}', fn (User $user, string $providerId, string $appointmentId) => $isStaff($user, $providerId));
