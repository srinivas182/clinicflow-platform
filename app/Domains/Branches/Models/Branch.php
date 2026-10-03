<?php

declare(strict_types=1);

namespace App\Domains\Branches\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $name
 * @property string|null $address
 * @property string|null $phone
 * @property bool $is_main
 * @property bool $active
 */
class Branch extends Model
{
    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['is_main' => 'boolean', 'active' => 'boolean'];
    }
}
