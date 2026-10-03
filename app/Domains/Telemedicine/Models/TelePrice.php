<?php

declare(strict_types=1);

namespace App\Domains\Telemedicine\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int|null $staff_id
 * @property string $mode
 * @property int $duration_minutes
 * @property int $price_cents
 */
class TelePrice extends Model
{
    public $timestamps = false;

    protected $table = 'tele_prices';

    protected $guarded = ['id'];
}
