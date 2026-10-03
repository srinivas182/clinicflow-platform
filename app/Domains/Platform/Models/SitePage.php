<?php

declare(strict_types=1);

namespace App\Domains\Platform\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A page of the provider's own website (provider database).
 *
 * @property int $id
 * @property string $slug
 * @property string $title
 * @property string|null $meta_description
 * @property list<array<string, mixed>> $sections
 * @property bool $published
 * @property string|null $menu_label
 * @property int|null $menu_order
 */
class SitePage extends Model
{
    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['sections' => 'array', 'published' => 'boolean'];
    }
}
