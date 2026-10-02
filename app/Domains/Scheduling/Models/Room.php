<?php

declare(strict_types=1);

namespace App\Domains\Scheduling\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $name
 * @property bool $is_active
 */
class Room extends Model
{
    protected $guarded = ['id'];

    /** @var array<string, mixed> */
    protected $attributes = ['is_active' => true];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}
