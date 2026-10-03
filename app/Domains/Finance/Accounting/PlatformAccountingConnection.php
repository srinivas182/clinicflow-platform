<?php

declare(strict_types=1);

namespace App\Domains\Finance\Accounting;

use Illuminate\Database\Eloquent\Model;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

/**
 * The platform's own books (subscriptions, wallet top-ups) — super admin.
 */
class PlatformAccountingConnection extends Model
{
    use CentralConnection;
    use HasAccountingTokens;

    protected $guarded = ['id'];

    protected $hidden = ['access_token', 'refresh_token'];
}
