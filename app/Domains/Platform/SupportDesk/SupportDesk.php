<?php

declare(strict_types=1);

namespace App\Domains\Platform\SupportDesk;

use App\Domains\Identity\Models\WorkspaceHandoff;
use App\Domains\Platform\Models\Provider;
use App\Models\User;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Support tickets between practices and Clinic Flow, and support access that
 * only the practice can grant: time-limited, read-only, revocable, and every
 * page the support person opens is recorded in the practice's audit log.
 */
class SupportDesk
{
    public const MAX_HOURS = 72;

    private function db(): ConnectionInterface
    {
        return DB::connection((string) config('tenancy.database.central_connection'));
    }

    public function open(Provider $provider, User $by, string $subject, string $body): int
    {
        if (trim($subject) === '' || trim($body) === '') {
            throw ValidationException::withMessages(['body' => 'Describe the problem.']);
        }
        $id = (int) $this->db()->table('support_tickets')->insertGetId(['tenant_id' => $provider->id, 'user_id' => $by->id, 'subject' => mb_substr(trim($subject), 0, 200), 'status' => 'open', 'created_at' => now(), 'updated_at' => now()]);
        $this->reply($id, $by, 'practice', $body);

        return $id;
    }

    public function reply(int $ticketId, User $by, string $author, string $body): void
    {
        if (trim($body) === '') {
            throw ValidationException::withMessages(['body' => 'Write a message.']);
        }
        $this->db()->table('support_messages')->insert(['support_ticket_id' => $ticketId, 'author' => $author, 'user_id' => $by->id, 'body' => mb_substr(trim($body), 0, 5000), 'created_at' => now()]);
        $this->db()->table('support_tickets')->where('id', $ticketId)->update(['status' => $author === 'support' ? 'answered' : 'open', 'updated_at' => now()]);
    }

    public function close(int $ticketId): void
    {
        $this->db()->table('support_tickets')->where('id', $ticketId)->update(['status' => 'closed', 'updated_at' => now()]);
    }

    public function grant(Provider $provider, User $owner, int $hours, ?int $ticketId): int
    {
        if ($hours < 1 || $hours > self::MAX_HOURS) {
            throw ValidationException::withMessages(['hours' => 'Grant access for 1 to '.self::MAX_HOURS.' hours.']);
        }
        $id = (int) $this->db()->table('support_grants')->insertGetId(['tenant_id' => $provider->id, 'granted_by' => $owner->id, 'support_ticket_id' => $ticketId,
            'expires_at' => now()->addHours($hours), 'created_at' => now(), 'updated_at' => now()]);
        $provider->run(fn () => activity('support')->causedBy($owner)->withProperties(['hours' => $hours, 'grant' => $id])->log('Support access granted'));

        return $id;
    }

    public function revoke(Provider $provider, int $grantId, User $by): void
    {
        $this->db()->table('support_grants')->where('id', $grantId)->where('tenant_id', $provider->id)->whereNull('revoked_at')->update(['revoked_at' => now(), 'updated_at' => now()]);
        $provider->run(fn () => activity('support')->causedBy($by)->withProperties(['grant' => $grantId])->log('Support access ended by the practice'));
    }

    public function activeGrant(int $grantId, ?string $tenantId = null): ?object
    {
        return $this->db()->table('support_grants')->where('id', $grantId)->whereNull('revoked_at')->where('expires_at', '>', now())
            ->when($tenantId !== null, fn ($q) => $q->where('tenant_id', $tenantId))->first();
    }

    /**
     * One-minute sign-in link for a platform admin into the practice, under an active grant.
     */
    public function handoff(int $grantId, User $admin): string
    {
        $grant = $this->activeGrant($grantId);
        if ($grant === null || ! (bool) $admin->getAttribute('is_platform_admin')) {
            throw ValidationException::withMessages(['grant' => 'This practice has not granted support access, or it has ended.']);
        }
        $provider = Provider::query()->findOrFail((string) data_get($grant, 'tenant_id'));
        $token = Str::random(64);
        WorkspaceHandoff::create(['token_hash' => hash('sha256', $token), 'user_id' => $admin->id, 'tenant_id' => $provider->id, 'expires_at' => now()->addSeconds(60), 'support_grant_id' => $grantId]);
        $scheme = parse_url((string) config('app.url'), PHP_URL_SCHEME);

        return (is_string($scheme) ? $scheme : 'https').'://'.$provider->domains()->value('domain')."/auth/support/{$token}";
    }
}
