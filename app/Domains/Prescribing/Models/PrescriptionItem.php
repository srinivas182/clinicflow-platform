<?php

declare(strict_types=1);

namespace App\Domains\Prescribing\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $prescription_id
 * @property int $medicine_id
 * @property string $nappi_code
 * @property string $description
 * @property string $schedule
 * @property string $dose
 * @property int $quantity
 * @property int $repeats
 * @property string|null $override_reason
 */
class PrescriptionItem extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];
}
