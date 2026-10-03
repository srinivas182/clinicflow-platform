<?php

declare(strict_types=1);

namespace App\Domains\Messaging\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A provider's own wording for a package-included message (provider database).
 *
 * @property int $id
 * @property string $key
 * @property string $channel
 * @property string $language
 * @property string|null $subject
 * @property string $body
 */
class MessageTemplateOverride extends Model
{
    protected $guarded = ['id'];
}
