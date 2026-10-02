<?php

declare(strict_types=1);

namespace App\Domains\Lab\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $lab_order_id
 * @property string $test_code
 * @property string $name
 * @property string $unit
 * @property string|null $reference
 * @property string|null $value
 * @property string|null $flag
 */
class LabResult extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];
}
