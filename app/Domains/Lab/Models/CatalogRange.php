<?php

declare(strict_types=1);

namespace App\Domains\Lab\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $lab_catalog_test_id
 * @property string|null $sex
 * @property int $age_min_months
 * @property int $age_max_months
 * @property string|null $ref_low
 * @property string|null $ref_high
 * @property string|null $critical_low
 * @property string|null $critical_high
 */
class CatalogRange extends Model
{
    public $timestamps = false;

    protected $table = 'lab_catalog_ranges';

    protected $guarded = ['id'];
}
