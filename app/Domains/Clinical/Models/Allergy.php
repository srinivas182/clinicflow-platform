<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Recorded allergy. Never deleted: removal keeps the row with a reason.
 *
 * @property int $id
 * @property string $patient_id
 * @property string $substance
 * @property string|null $reaction
 * @property string $status
 * @property string|null $removed_reason
 */
class Allergy extends Model
{
    protected $guarded = ['id'];
}
