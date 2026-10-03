<?php

declare(strict_types=1);

namespace App\Domains\Platform\Models;

use Illuminate\Database\Eloquent\Model;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

/**
 * @property int $id
 * @property string $slug
 * @property string $title
 * @property string|null $meta_description
 * @property string $body
 * @property bool $published
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
        return ['published' => 'boolean'];
    }
}
