<?php

declare(strict_types=1);

namespace App\Domains\Identity\Models;

use App\Domains\Identity\Enums\MembershipStatus;
use App\Domains\Identity\Enums\StaffRole;
use App\Domains\Platform\Models\Provider;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

/**
 * A person's access to one provider workspace (Platform database).
 *
 * @property int $id
 * @property int $user_id
 * @property string $tenant_id
 * @property StaffRole $role
 * @property MembershipStatus $status
 * @property Carbon|null $expires_at
 * @property-read Provider $provider
 * @property-read User $user
 */
class Membership extends Model
{
    use CentralConnection;

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'role' => StaffRole::class,
            'status' => MembershipStatus::class,
            'expires_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Provider, $this>
     */
    public function provider(): BelongsTo
    {
        return $this->belongsTo(Provider::class, 'tenant_id');
    }

    /**
     * Active and not past a locum expiry date.
     *
     * @param  Builder<Membership>  $query
     */
    public function scopeUsable(Builder $query): void
    {
        $query->where('status', MembershipStatus::Active->value)
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()));
    }

    public function isUsable(): bool
    {
        return $this->status === MembershipStatus::Active
            && ($this->expires_at === null || $this->expires_at->isFuture());
    }
}
