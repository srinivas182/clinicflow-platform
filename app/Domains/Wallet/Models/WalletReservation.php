<?php

declare(strict_types=1);

namespace App\Domains\Wallet\Models;

use Illuminate\Database\Eloquent\Model;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

/**
 * @property int $id
 * @property int $wallet_id
 * @property string $reference
 * @property string $consult_type
 * @property int $amount_cents
 * @property string $status
 * @property int $captured_cents
 */
class WalletReservation extends Model
{
    use CentralConnection;

    protected $guarded = ['id'];
}
