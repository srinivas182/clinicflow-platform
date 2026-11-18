<?php

declare(strict_types=1);

namespace App\Domains\Wallet\Console;

use App\Domains\Identity\Models\Membership;
use App\Domains\Wallet\Actions\StartTopup;
use App\Domains\Wallet\Models\Wallet;
use App\Domains\Wallet\Notifications\AutoTopupPackChanged;
use App\Domains\Wallet\Support\WalletSettings;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

/**
 * Nightly. Each wallet is handled on its own: one practice's problem (a removed pack, a gateway
 * error) is logged and never stops the others from being topped up.
 */
class AutoTopupCommand extends Command
{
    protected $signature = 'wallet:auto-topup';

    protected $description = 'Top up wallets below their threshold from the saved card, for providers who switched auto top-up on';

    public function handle(StartTopup $topups): int
    {
        [$done, $failed] = [0, 0];
        Wallet::query()->where('auto_topup', true)->whereNotNull('auto_topup_pack_cents')->each(function (Wallet $wallet) use ($topups, &$done, &$failed): void {
            try {
                if ($wallet->acceptsOnlineBookings()) {
                    return;
                }
                $pack = $this->packFor($wallet);
                if ($pack === null) {
                    return;
                }
                $topup = $topups->create($wallet, $pack, 'saved_card');
                $done += $topups->chargeSavedCard($topup) ? 1 : 0;
            } catch (\Throwable $e) {
                $failed++;
                Log::error('Automatic wallet top-up failed for one practice; others continue', ['tenant' => $wallet->tenant_id, 'error' => $e->getMessage()]);
                report($e);
            }
        });
        $this->info("{$done} wallet(s) topped up.".($failed > 0 ? " {$failed} could not be processed (see the log)." : ''));

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * The wallet's chosen pack, or the closest pack still offered when it was removed
     * (the owner is told). Null when no packs are offered: automatic top-up is paused.
     */
    private function packFor(Wallet $wallet): ?int
    {
        $chosen = (int) $wallet->auto_topup_pack_cents;
        $amounts = array_column(WalletSettings::packs(), 'amount');
        if (in_array($chosen, $amounts, true)) {
            return $chosen;
        }
        $closest = null;
        foreach ($amounts as $amount) {
            if ($closest === null || abs($amount - $chosen) < abs($closest - $chosen) || (abs($amount - $chosen) === abs($closest - $chosen) && $amount < $closest)) {
                $closest = $amount;
            }
        }
        $wallet->forceFill($closest === null ? ['auto_topup' => false] : ['auto_topup_pack_cents' => $closest])->save();
        activity('wallet')->performedOn($wallet)->withProperties(['from' => $chosen, 'to' => $closest])->log($closest === null ? 'Automatic top-up paused: no packs offered' : 'Automatic top-up pack replaced');
        $owners = User::query()->whereIn('id', Membership::query()->where('tenant_id', $wallet->tenant_id)->where('role', 'owner')->pluck('user_id'))->get();
        Notification::send($owners, new AutoTopupPackChanged($this->rand($chosen), $closest === null ? null : $this->rand($closest)));

        return $closest;
    }

    private function rand(int $cents): string
    {
        return 'R'.number_format($cents / 100, 2, '.', ' ');
    }
}
