<?php

declare(strict_types=1);

namespace App\Domains\Translation\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

/**
 * Platform-wide translation data (Sprint W8a).
 *
 * @property string $code
 * @property string $name
 * @property string $native_name
 * @property bool $enabled
 * @property Carbon|null $enabled_at
 */
class Language extends Model
{
    use CentralConnection;

    protected $table = 'languages';

    protected $primaryKey = 'code';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['enabled' => 'boolean', 'enabled_at' => 'datetime'];
    }
}
