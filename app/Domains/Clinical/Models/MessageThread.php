<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string|null $patient_id
 * @property string $subject
 * @property string|null $context_type
 * @property string|null $context_id
 * @property string|null $other_tenant_id
 * @property string|null $other_thread_id
 * @property list<int> $local_staff_ids
 * @property bool $urgent
 * @property Carbon|null $urgent_due_at
 * @property Carbon|null $escalated_at
 */
class MessageThread extends Model
{
    use HasUlids;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['local_staff_ids' => 'array', 'urgent' => 'boolean', 'urgent_due_at' => 'datetime', 'escalated_at' => 'datetime'];
    }

    /**
     * @return HasMany<ThreadMessage, $this>
     */
    public function messages(): HasMany
    {
        return $this->hasMany(ThreadMessage::class);
    }
}
