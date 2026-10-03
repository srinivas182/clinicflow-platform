<?php

declare(strict_types=1);

namespace App\Domains\Lab\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $code
 * @property string $name
 * @property int $price_cents
 * @property list<string> $test_codes
 * @property bool $active
 */
class Panel extends Model
{
    protected $table = 'lab_panels';

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['test_codes' => 'array', 'active' => 'boolean'];
    }
}
