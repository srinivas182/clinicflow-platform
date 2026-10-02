<?php

declare(strict_types=1);

namespace App\Domains\Documents\Models;

use App\Domains\Documents\Support\DocumentType;
use Illuminate\Database\Eloquent\Model;

/**
 * A version of a provider's template. Publishing creates a new version;
 * issued documents keep the version they were made with.
 *
 * @property int $id
 * @property DocumentType $type
 * @property int $version
 * @property bool $is_active
 * @property string $body
 * @property string $paper
 */
class DocumentTemplate extends Model
{
    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['type' => DocumentType::class, 'is_active' => 'boolean', 'version' => 'integer'];
    }

    public static function active(DocumentType $type): self
    {
        return static::query()->where('type', $type->value)->where('is_active', true)->firstOrFail();
    }
}
