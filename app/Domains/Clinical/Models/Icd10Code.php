<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Models;

use Illuminate\Database\Eloquent\Model;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

/**
 * ICD-10 reference code (Platform database, shared by every provider).
 *
 * @property string $code
 * @property string $description
 * @property bool $valid_primary
 */
class Icd10Code extends Model
{
    use CentralConnection;

    public $incrementing = false;

    public $timestamps = false;

    protected $primaryKey = 'code';

    protected $keyType = 'string';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['valid_primary' => 'boolean'];
    }
}
