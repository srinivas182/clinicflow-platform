<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $consultation_id
 * @property string $icd10_code
 * @property string $description
 * @property bool $is_primary
 */
class ConsultationDiagnosis extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['is_primary' => 'boolean'];
    }
}
