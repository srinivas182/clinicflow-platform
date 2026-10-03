<?php

declare(strict_types=1);

namespace App\Domains\Telemedicine\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $chat_thread_id
 * @property string $sender
 * @property string $body
 * @property Carbon $created_at
 */
class ChatMessage extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }
}
