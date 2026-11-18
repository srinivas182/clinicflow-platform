<?php

declare(strict_types=1);

namespace App\Domains\Platform\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

/**
 * @property int $id
 * @property string $slug
 * @property string $title
 * @property string|null $meta_description
 * @property string $body
 * @property list<array<string, mixed>>|null $sections
 * @property string|null $menu_label
 * @property int|null $menu_order
 * @property bool $published
 * @property Carbon|null $archived_at
 */
class CmsPage extends Model
{
    use CentralConnection;

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['published' => 'boolean',
            'archived_at' => 'datetime', 'sections' => 'array'];
    }
}
