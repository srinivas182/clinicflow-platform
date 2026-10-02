<?php

declare(strict_types=1);

namespace App\Domains\Identity\Actions;

use App\Domains\Identity\Enums\MembershipStatus;
use App\Domains\Identity\Enums\StaffRole;
use App\Domains\Identity\Models\Membership;
use App\Domains\Platform\Models\Provider;
use App\Models\User;
use DateTimeInterface;
use InvalidArgumentException;

/**
 * Gives a person access to a provider workspace in a role.
 */
class AddStaffMember
{
    public function handle(Provider $provider, User $user, StaffRole $role, ?DateTimeInterface $expiresAt = null): Membership
    {
        if (! in_array($role, StaffRole::forProviderType($provider->type), true)) {
            throw new InvalidArgumentException("{$role->label()} is not a role for a {$provider->type->label()}.");
        }

        $membership = Membership::query()->updateOrCreate(
            ['user_id' => $user->id, 'tenant_id' => $provider->id],
            ['role' => $role, 'status' => MembershipStatus::Active, 'expires_at' => $expiresAt],
        );

        $provider->run(function () use ($user, $role): void {
            $user->unsetRelation('roles')->unsetRelation('permissions');
            $user->syncRoles([$role->value]);
            activity('staff')->withProperties(['user_id' => $user->id, 'role' => $role->value])->log('Staff member added');
        });

        return $membership;
    }
}
