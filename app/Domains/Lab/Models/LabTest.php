<?php

declare(strict_types=1);

namespace App\Domains\Lab\Models;

use Illuminate\Database\Eloquent\Model;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

/**
 * Lab test catalogue entry (Platform database). DEMO reference ranges.
 *
 * @property string $code
 * @property string $name
 * @property string $unit
 * @property string|null $ref_low
 * @property string|null $ref_high
 * @property string|null $critical_low
 * @property string|null $critical_high
 * @property int $price_cents
 */
class LabTest extends Model
{
    use CentralConnection;

    public $incrementing = false;

    public $timestamps = false;

    protected $primaryKey = 'code';

    protected $keyType = 'string';

    protected $guarded = [];

    public function referenceLabel(): string
    {
        return match (true) {
            $this->ref_low !== null && $this->ref_high !== null => "{$this->ref_low}–{$this->ref_high}",
            $this->ref_high !== null => "< {$this->ref_high}",
            $this->ref_low !== null => "> {$this->ref_low}",
            default => '',
        };
    }

    /**
     * critical_low, low, normal, high or critical_high.
     */
    public function flag(float $value): string
    {
        return match (true) {
            $this->critical_low !== null && $value <= (float) $this->critical_low => 'critical_low',
            $this->critical_high !== null && $value >= (float) $this->critical_high => 'critical_high',
            $this->ref_low !== null && $value < (float) $this->ref_low => 'low',
            $this->ref_high !== null && $value > (float) $this->ref_high => 'high',
            default => 'normal',
        };
    }
}
