<?php

declare(strict_types=1);

namespace App\Domains\Documents\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $type
 * @property int $document_template_id
 * @property int $template_version
 * @property string $subject_type
 * @property string $subject_id
 * @property string $file_path
 * @property string $sha256
 */
class IssuedDocument extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];
}
