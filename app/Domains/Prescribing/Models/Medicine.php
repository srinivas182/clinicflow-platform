<?php

declare(strict_types=1);

namespace App\Domains\Prescribing\Models;

use Illuminate\Database\Eloquent\Model;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

/**
 * Medicine catalogue entry (Platform database). Demo data until the licensed
 * drug database is connected.
 *
 * @property int $id
 * @property string $nappi_code
 * @property string $name
 * @property string $strength
 * @property string $form
 * @property string $schedule
 * @property list<string> $ingredients
 * @property list<string> $allergy_classes
 * @property string|null $default_dose
 * @property int $price_cents
 */
class Medicine extends Model
{
    use CentralConnection;

    public $timestamps = false;

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['ingredients' => 'array', 'allergy_classes' => 'array', 'price_cents' => 'integer'];
    }

    public function label(): string
    {
        return "{$this->name} {$this->strength} {$this->form}";
    }
}
