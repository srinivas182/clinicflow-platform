<?php

declare(strict_types=1);

namespace App\Domains\Finance\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * Double-entry journal line (provider database). Append-only.
 *
 * @property int $id
 * @property Carbon $occurred_at
 * @property string $account
 * @property int $debit_cents
 * @property int $credit_cents
 * @property string $source_type
 * @property string $source_id
 * @property string $description
 */
class LedgerEntry extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['occurred_at' => 'datetime', 'debit_cents' => 'integer', 'credit_cents' => 'integer'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Ledger entries are append-only.'));
        static::deleting(fn () => throw new LogicException('Ledger entries are append-only.'));
    }
}
