<?php

declare(strict_types=1);

namespace App\Domains\Telemedicine\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $staff_id
 * @property Carbon $date
 * @property string $type
 * @property string|null $mode
 * @property string|null $start_time
 * @property string|null $end_time
 * @property string|null $note
 */
class TeleException extends Model
{
    public $timestamps = false;

    protected $table = 'tele_exceptions';

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['date' => 'date'];
    }
}
