<?php

declare(strict_types=1);

namespace App\Domains\Telemedicine\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $staff_id
 * @property string $mode
 * @property int $weekday
 * @property string $start_time
 * @property string $end_time
 */
class TeleAvailability extends Model
{
    public $timestamps = false;

    protected $table = 'tele_availability';

    protected $guarded = ['id'];
}
