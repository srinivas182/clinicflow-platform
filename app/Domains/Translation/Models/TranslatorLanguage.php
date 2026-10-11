<?php

declare(strict_types=1);

namespace App\Domains\Translation\Models;

use Illuminate\Database\Eloquent\Model;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

/**
 * Platform-wide translation data (Sprint W8a).
 *
 * @property int $id
 * @property int $user_id
 * @property string $language
 * @property bool $clinical
 */
class TranslatorLanguage extends Model
{
    use CentralConnection;

    protected $table = 'translator_languages';

    protected $guarded = ['id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['clinical' => 'boolean'];
    }
}
