<?php

declare(strict_types=1);

namespace App\Domains\Translation\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

/**
 * Platform-wide translation data (Sprint W8a).
 *
 * @property int $id
 * @property string $name
 * @property string|null $email
 * @property string|null $phone
 * @property list<string> $languages
 * @property bool $clinical
 * @property Carbon $expires_at
 * @property Carbon|null $accepted_at
 */
class TranslatorInvitation extends Model
{
    use CentralConnection;

    protected $table = 'translator_invitations';

    protected $guarded = ['id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['languages' => 'array', 'clinical' => 'boolean', 'expires_at' => 'datetime', 'accepted_at' => 'datetime'];
    }
}
