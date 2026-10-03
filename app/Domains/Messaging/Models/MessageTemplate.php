<?php

declare(strict_types=1);

namespace App\Domains\Messaging\Models;

use Illuminate\Database\Eloquent\Model;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

/**
 * Platform default wording (super admin) for one message, channel and language.
 *
 * @property int $id
 * @property string $key
 * @property string $channel
 * @property string $language
 * @property string|null $subject
 * @property string $body
 */
class MessageTemplate extends Model
{
    use CentralConnection;

    protected $guarded = ['id'];
}
