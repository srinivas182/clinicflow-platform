<?php

declare(strict_types=1);

namespace App\Domains\Platform\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

/**
 * A chain or group of practices. Each practice keeps its own database; the
 * group sees totals only. billing: "separate" (default) or "combined".
 *
 * @property int $id
 * @property string $name
 * @property string $billing
 */
class ProviderGroup extends Model
{
    use CentralConnection;

    protected $guarded = ['id'];

    /**
     * @return Collection<int, Provider>
     */
    public function members(): Collection
    {
        /** @var Collection<int, Provider> $members */
        $members = collect(Provider::query()->whereIn('id', DB::table('provider_group_members')->where('provider_group_id', $this->id)->pluck('tenant_id'))->orderBy('name')->get()->all());

        return $members;
    }

    public function isAdmin(int $userId): bool
    {
        return DB::table('provider_group_admins')->where('provider_group_id', $this->id)->where('user_id', $userId)->exists();
    }
}
