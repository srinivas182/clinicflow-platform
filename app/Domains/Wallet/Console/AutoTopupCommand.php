<?php

declare(strict_types=1);

namespace App\Domains\Wallet\Console;

use App\Domains\Wallet\Actions\StartTopup;
use App\Domains\Wallet\Models\Wallet;
use Illuminate\Console\Command;

class AutoTopupCommand extends Command
{
    protected $signature = 'wallet:auto-topup';

    protected $description = 'Top up wallets below their threshold from the saved card, for providers who switched auto top-up on';

    public function handle(StartTopup $topups): int
    {
        $done = 0;
        Wallet::query()->where('auto_topup', true)->whereNotNull('auto_topup_pack_cents')->each(function (Wallet $wallet) use ($topups, &$done): void {
            if ($wallet->acceptsOnlineBookings()) {
                return;
            }
            $topup = $topups->create($wallet, (int) $wallet->auto_topup_pack_cents, 'saved_card');
            $done += $topups->chargeSavedCard($topup) ? 1 : 0;
        });
        $this->info("{$done} wallet(s) topped up.");

        return self::SUCCESS;
    }
}
