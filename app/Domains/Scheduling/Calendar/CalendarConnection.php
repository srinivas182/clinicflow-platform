<?php

declare(strict_types=1);

namespace App\Domains\Scheduling\Calendar;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A doctor's calendar link (provider database). Without a driver it is just the iCal feed.
 *
 * @property int $id
 * @property int $staff_id
 * @property string|null $driver
 * @property string|null $access_token
 * @property string|null $refresh_token
 * @property Carbon|null $token_expires_at
 * @property bool $show_initials
 * @property bool $import_busy
 * @property string $ical_token
 * @property string|null $last_error
 */
class CalendarConnection extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['access_token', 'refresh_token'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['access_token' => 'encrypted', 'refresh_token' => 'encrypted', 'token_expires_at' => 'datetime', 'show_initials' => 'boolean', 'import_busy' => 'boolean'];
    }
}
