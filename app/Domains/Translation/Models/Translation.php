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
 * @property string $language
 * @property string $unit
 * @property string $source
 * @property string|null $text
 * @property string $status
 * @property bool $medical
 * @property int|null $approved_by
 * @property Carbon|null $approved_at
 * @property int|null $clinical_approved_by
 * @property Carbon|null $clinical_approved_at
 */
class Translation extends Model
{
    use CentralConnection;

    protected $table = 'translations';

    protected $guarded = ['id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['medical' => 'boolean', 'approved_at' => 'datetime', 'clinical_approved_at' => 'datetime'];
    }

    /** Live: approved by a native speaker and, for medical wording, by a clinical reviewer — and still matching the current English. */
    public function isLive(string $currentSource): bool
    {
        return $this->status === 'approved' && ($this->medical === false || $this->clinical_approved_at !== null) && $this->source === $currentSource;
    }
}
