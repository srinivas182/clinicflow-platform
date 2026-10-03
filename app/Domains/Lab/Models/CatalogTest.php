<?php

declare(strict_types=1);

namespace App\Domains\Lab\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A test in this lab's own catalogue (its result template).
 *
 * @property int $id
 * @property string $code
 * @property string $name
 * @property string|null $loinc
 * @property string $sample_type
 * @property string $result_type numeric|choice|text
 * @property list<string>|null $choices
 * @property string|null $unit
 * @property int $decimals
 * @property string|null $plausible_min
 * @property string|null $plausible_max
 * @property int $price_cents
 * @property int $turnaround_hours
 * @property bool $home_collection
 * @property bool $active
 * @property int $version
 */
class CatalogTest extends Model
{
    protected $table = 'lab_catalog_tests';

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['choices' => 'array', 'home_collection' => 'boolean', 'active' => 'boolean', 'version' => 'integer', 'decimals' => 'integer'];
    }

    /**
     * @return HasMany<CatalogRange, $this>
     */
    public function ranges(): HasMany
    {
        return $this->hasMany(CatalogRange::class, 'lab_catalog_test_id');
    }
}
