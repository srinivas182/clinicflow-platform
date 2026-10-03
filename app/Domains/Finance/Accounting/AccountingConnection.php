<?php

declare(strict_types=1);

namespace App\Domains\Finance\Accounting;

use Illuminate\Database\Eloquent\Model;

/**
 * A provider's link to its own accounting app (provider database). One enabled at a time.
 */
class AccountingConnection extends Model
{
    use HasAccountingTokens;

    protected $guarded = ['id'];

    protected $hidden = ['access_token', 'refresh_token'];
}
