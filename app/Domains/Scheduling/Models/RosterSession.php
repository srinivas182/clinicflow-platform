<?php

declare(strict_types=1);

namespace App\Domains\Scheduling\Models;

use App\Domains\Identity\Models\Staff;
use App\Domains\Scheduling\Enums\SessionType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A block of time a staff member works, optionally in a room.
 *
 * @property int $id
 * @property int $staff_id
 * @property int|null $room_id
 * @property SessionType $session_type
 * @property Carbon $starts_at
 * @property Carbon $ends_at
 * @property int $slot_minutes
 * @property string|null $notes
 * @property-read Staff $staff
 * @property-read Room|null $room
 */
class RosterSession extends Model
{
    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'session_type' => SessionType::class,
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'slot_minutes' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Staff, $this>
     */
    public function staff(): BelongsTo
    {
        return $this->belongsTo(Staff::class);
    }

    /**
     * @return BelongsTo<Room, $this>
     */
    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }
}
