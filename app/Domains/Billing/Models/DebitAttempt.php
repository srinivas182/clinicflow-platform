<?php

declare(strict_types=1);

namespace App\Domains\Billing\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

/**
 * @property int $id
 * @property int $subscription_invoice_id
 * @property int $billing_mandate_id
 * @property string $reference
 * @property string $outcome
 * @property string|null $message
 * @property Carbon $attempted_at
 */
class DebitAttempt extends Model
{
    use CentralConnection;

    public $timestamps = false;

    protected $table = 'subscription_debit_attempts';

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['attempted_at' => 'datetime'];
    }
}
