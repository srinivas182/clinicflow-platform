<?php

declare(strict_types=1);

namespace App\Domains\Identity\Actions;

use App\Domains\Identity\Enums\MembershipStatus;
use App\Domains\Identity\Enums\StaffRole;
use App\Domains\Identity\Models\Membership;
use App\Domains\Identity\Models\Staff;
use App\Domains\Messaging\Actions\SendMessage;
use App\Domains\Platform\Models\Provider;
use App\Models\User;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Practices manage their own staff: invite, accept, change role and branches, suspend and reactivate.
 * Only owners and practice admins; a practice admin cannot touch the owner or grant owner rights,
 * and nobody can change or suspend themselves. Everything is audited.
 */
class StaffManagement
{
    public const INVITE_DAYS = 7;

    public function __construct(private readonly AddStaffMember $add) {}

    private function central(): ConnectionInterface
    {
        return DB::connection((string) config('tenancy.database.central_connection'));
    }

    public function canManage(Provider $provider, User $actor): bool
    {
        return in_array($this->roleOf($provider, $actor->id), [StaffRole::Owner, StaffRole::PracticeAdmin], true);
    }

    /**
     * @param  list<int>  $branchIds
     */
    public function invite(Provider $provider, User $actor, string $name, ?string $email, ?string $phone, StaffRole $role, array $branchIds): int
    {
        $this->guardActor($provider, $actor);
        if ($role === StaffRole::Owner) {
            throw ValidationException::withMessages(['role' => 'The owner role cannot be given by invitation.']);
        }
        if (! in_array($role, StaffRole::forProviderType($provider->type), true)) {
            throw ValidationException::withMessages(['role' => "{$role->label()} is not a role for a {$provider->type->label()}."]);
        }
        $email = $email === null || trim($email) === '' ? null : strtolower(trim($email));
        $phone = $phone === null || trim($phone) === '' ? null : preg_replace('/\D+/', '', $phone);
        if ($email === null && $phone === null) {
            throw ValidationException::withMessages(['email' => 'Enter an email address or a mobile number.']);
        }
        $existing = User::query()->when($email !== null, fn ($q) => $q->where('email', $email))->when($email === null, fn ($q) => $q->where('phone', $phone))->first();
        if ($existing instanceof User && $this->roleOf($provider, $existing->id) !== null) {
            throw ValidationException::withMessages(['email' => 'This person is already on your staff.']);
        }
        $token = Str::random(48);
        $id = (int) $this->central()->table('staff_invitations')->insertGetId([
            'tenant_id' => $provider->id, 'name' => trim($name), 'email' => $email, 'phone' => $phone, 'role' => $role->value,
            'branch_ids' => json_encode(array_map('intval', $branchIds)), 'token_hash' => hash('sha256', $token), 'invited_by' => $actor->id,
            'expires_at' => now()->addDays(self::INVITE_DAYS), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->send($provider, $email, $phone, $name, $role, $token);
        $provider->run(fn () => activity('staff')->causedBy($actor)->withProperties(['invitation' => $id, 'role' => $role->value])->log('Staff member invited'));

        return $id;
    }

    public function resend(Provider $provider, User $actor, int $invitationId): void
    {
        $this->guardActor($provider, $actor);
        $inv = $this->pending($provider, $invitationId);
        $token = Str::random(48);
        $this->central()->table('staff_invitations')->where('id', $inv->id)->update(['token_hash' => hash('sha256', $token), 'expires_at' => now()->addDays(self::INVITE_DAYS), 'updated_at' => now()]);
        $this->send($provider, $inv->email, $inv->phone, (string) $inv->name, StaffRole::from((string) $inv->role), $token);
    }

    public function revoke(Provider $provider, User $actor, int $invitationId): void
    {
        $this->guardActor($provider, $actor);
        $inv = $this->pending($provider, $invitationId);
        $this->central()->table('staff_invitations')->where('id', $inv->id)->update(['revoked_at' => now(), 'updated_at' => now()]);
        $provider->run(fn () => activity('staff')->causedBy($actor)->withProperties(['invitation' => $inv->id])->log('Staff invitation revoked'));
    }

    /** The invitation behind a link, if it can still be used. */
    public function findByToken(string $token): ?\stdClass
    {
        return $this->central()->table('staff_invitations')->where('token_hash', hash('sha256', $token))
            ->whereNull('accepted_at')->whereNull('revoked_at')->where('expires_at', '>', now())->first();
    }

    /** Accepts an invitation for a signed-in or newly created user whose email or mobile matches it. */
    public function accept(string $token, User $user): Provider
    {
        $inv = $this->findByToken($token);
        if ($inv === null) {
            throw ValidationException::withMessages(['token' => 'This invitation has expired or was already used.']);
        }
        $matches = ($inv->email !== null && strtolower((string) $user->email) === $inv->email) || ($inv->phone !== null && preg_replace('/\D+/', '', (string) $user->phone) === $inv->phone);
        if (! $matches) {
            throw ValidationException::withMessages(['token' => 'This invitation was sent to a different email address or mobile number.']);
        }
        $provider = Provider::query()->whereKey((string) $inv->tenant_id)->first();
        if (! $provider instanceof Provider) {
            abort(404);
        }
        $this->add->handle($provider, $user, StaffRole::from((string) $inv->role));
        $this->setBranches($provider, $user->id, (array) json_decode((string) $inv->branch_ids, true));
        $this->central()->table('staff_invitations')->where('id', $inv->id)->update(['accepted_at' => now(), 'accepted_user_id' => $user->id, 'updated_at' => now()]);

        return $provider;
    }

    /**
     * @param  list<int>  $branchIds
     */
    public function update(Provider $provider, User $actor, int $userId, StaffRole $role, array $branchIds): void
    {
        $current = $this->guardTarget($provider, $actor, $userId);
        if ($role === StaffRole::Owner || ! in_array($role, StaffRole::forProviderType($provider->type), true)) {
            throw ValidationException::withMessages(['role' => 'Choose one of the roles for this practice.']);
        }
        if ($role !== $current) {
            $this->add->handle($provider, User::query()->findOrFail($userId), $role);
        }
        $this->setBranches($provider, $userId, $branchIds);
        $provider->run(fn () => activity('staff')->causedBy($actor)->withProperties(['user_id' => $userId, 'from' => $current->value, 'to' => $role->value, 'branches' => $branchIds])
            ->log('Staff role or branches changed'));
    }

    public function setActive(Provider $provider, User $actor, int $userId, bool $active): void
    {
        $this->guardTarget($provider, $actor, $userId);
        Membership::query()->where('tenant_id', $provider->id)->where('user_id', $userId)
            ->update(['status' => $active ? MembershipStatus::Active->value : MembershipStatus::Suspended->value]);
        $provider->run(fn () => activity('staff')->causedBy($actor)->withProperties(['user_id' => $userId])->log($active ? 'Staff member reactivated' : 'Staff member suspended'));
    }

    private function roleOf(Provider $provider, int $userId): ?StaffRole
    {
        $role = Membership::query()->where('tenant_id', $provider->id)->where('user_id', $userId)->value('role');

        return $role instanceof StaffRole ? $role : StaffRole::tryFrom((string) $role);
    }

    private function guardActor(Provider $provider, User $actor): void
    {
        if (! $this->canManage($provider, $actor)) {
            abort(403, 'Only the practice owner or a practice admin can manage staff.');
        }
    }

    /** Checks the actor may change this person, and returns their current role. */
    private function guardTarget(Provider $provider, User $actor, int $userId): StaffRole
    {
        $this->guardActor($provider, $actor);
        $role = $this->roleOf($provider, $userId);
        if ($role === null) {
            abort(404);
        }
        if ($userId === $actor->id) {
            throw ValidationException::withMessages(['staff' => 'You cannot change your own access. Ask another owner or practice admin.']);
        }
        if ($role === StaffRole::Owner) {
            throw ValidationException::withMessages(['staff' => 'The practice owner cannot be changed here.']);
        }

        return $role;
    }

    /**
     * @param  array<int, mixed>  $branchIds
     */
    private function setBranches(Provider $provider, int $userId, array $branchIds): void
    {
        $provider->run(function () use ($userId, $branchIds): void {
            $valid = DB::table('branches')->whereIn('id', array_map('intval', $branchIds))->pluck('id');
            DB::table('branch_staff')->where('staff_id', $userId)->delete();
            foreach ($valid as $branchId) {
                DB::table('branch_staff')->insert(['branch_id' => $branchId, 'staff_id' => $userId]);
            }
        });
    }

    private function pending(Provider $provider, int $id): \stdClass
    {
        $inv = $this->central()->table('staff_invitations')->where('id', $id)->where('tenant_id', $provider->id)->whereNull('accepted_at')->whereNull('revoked_at')->first();
        if ($inv === null) {
            abort(404);
        }

        return $inv;
    }

    private function send(Provider $provider, ?string $email, ?string $phone, string $name, StaffRole $role, string $token): void
    {
        $url = rtrim((string) config('app.url'), '/').'/invitations/'.$token;
        $text = "Hello {$name}, {$provider->name} has invited you to join them on Dr Business Flow as {$role->label()}. Accept within ".self::INVITE_DAYS." days: {$url}";
        if ($email !== null) {
            app(SendMessage::class)->handle('email', $email, $text."\n\nIf you were not expecting this, ignore this message.", "Join {$provider->name} on Dr Business Flow");
        }
        if ($phone !== null) {
            app(SendMessage::class)->handle('sms', $phone, $text);
        }
    }
}
