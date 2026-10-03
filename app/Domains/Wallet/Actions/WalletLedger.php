<?php

declare(strict_types=1);

namespace App\Domains\Wallet\Actions;

use App\Domains\Identity\Models\Membership;
use App\Domains\Messaging\Actions\SendMessage;
use App\Domains\Wallet\Models\Wallet;
use App\Domains\Wallet\Models\WalletReservation;
use App\Domains\Wallet\Models\WalletTopup;
use App\Domains\Wallet\Models\WalletTransaction;
use App\Domains\Wallet\Support\WalletSettings;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Wallet movements. Booking an online consult reserves its expected cost;
 * the call is never cut off — the actual usage is charged when it ends (a
 * small overdraft is recovered from the next top-up); failed or cancelled
 * consults release the reservation.
 */
class WalletLedger
{
    public function reserve(Wallet $wallet, string $reference, string $consultType, int $minutes): WalletReservation
    {
        return DB::connection($wallet->getConnectionName())->transaction(function () use ($wallet, $reference, $consultType, $minutes): WalletReservation {
            $locked = Wallet::query()->lockForUpdate()->findOrFail($wallet->id);
            if (! $locked->acceptsOnlineBookings()) {
                throw ValidationException::withMessages(['wallet' => 'Online consults are unavailable: the telemedicine wallet is below its minimum balance.']);
            }

            $amount = WalletSettings::priceCents($consultType, $minutes);
            $locked->increment('reserved_cents', $amount);
            $reservation = WalletReservation::create(['wallet_id' => $locked->id, 'reference' => $reference, 'consult_type' => $consultType, 'amount_cents' => $amount, 'status' => 'held']);
            $this->record($locked->refresh(), 'reserve', 0, $reference, 'Reserved R'.number_format($amount / 100, 2)." for a {$consultType} consult");

            return $reservation;
        });
    }

    public function capture(WalletReservation $reservation, int $usedMinutes): WalletReservation
    {
        return DB::connection($reservation->getConnectionName())->transaction(function () use ($reservation, $usedMinutes): WalletReservation {
            $res = WalletReservation::query()->lockForUpdate()->findOrFail($reservation->id);
            if ($res->status !== 'held') {
                throw ValidationException::withMessages(['reservation' => 'This reservation is already settled.']);
            }

            $wallet = Wallet::query()->lockForUpdate()->findOrFail($res->wallet_id);
            $charge = WalletSettings::priceCents($res->consult_type, $usedMinutes);
            $wallet->decrement('reserved_cents', $res->amount_cents);
            $wallet->decrement('balance_cents', $charge);
            $res->forceFill(['status' => 'captured', 'captured_cents' => $charge])->save();
            $this->record($wallet->refresh(), 'charge', -$charge, $res->reference, ucfirst($res->consult_type).' consult'.($res->consult_type === 'chat' ? '' : " · {$usedMinutes} min"));
            $this->checkLowBalance($wallet);

            return $res;
        });
    }

    public function release(WalletReservation $reservation, string $why = 'Consult cancelled'): WalletReservation
    {
        return DB::connection($reservation->getConnectionName())->transaction(function () use ($reservation, $why): WalletReservation {
            $res = WalletReservation::query()->lockForUpdate()->findOrFail($reservation->id);
            if ($res->status !== 'held') {
                return $res;
            }
            $wallet = Wallet::query()->lockForUpdate()->findOrFail($res->wallet_id);
            $wallet->decrement('reserved_cents', $res->amount_cents);
            $res->forceFill(['status' => 'released'])->save();
            $this->record($wallet->refresh(), 'release', 0, $res->reference, $why.' — reservation released');

            return $res;
        });
    }

    public function creditTopup(WalletTopup $topup, ?string $gateway, ?string $gatewayReference): void
    {
        DB::connection($topup->getConnectionName())->transaction(function () use ($topup, $gateway, $gatewayReference): void {
            $locked = WalletTopup::query()->lockForUpdate()->findOrFail($topup->id);
            if ($locked->status === 'paid') {
                return;
            }
            $locked->forceFill(['status' => 'paid', 'paid_at' => now(), 'gateway' => $gateway, 'gateway_reference' => $gatewayReference])->save();

            $wallet = Wallet::query()->lockForUpdate()->findOrFail($locked->wallet_id);
            $wallet->increment('balance_cents', $locked->amount_cents + $locked->bonus_cents);
            $wallet->forceFill(['low_balance_notified_at' => null])->save();
            $this->record($wallet->refresh(), 'topup', $locked->amount_cents + $locked->bonus_cents, 'topup-'.$locked->id,
                'Top-up R'.number_format($locked->amount_cents / 100, 2).($locked->bonus_cents > 0 ? ' + bonus R'.number_format($locked->bonus_cents / 100, 2) : ''));
        });
    }

    /**
     * Charges a usage fee (e.g. a WhatsApp message) straight from the available balance.
     */
    public function chargeUsage(Wallet $wallet, int $cents, string $reference, string $description): bool
    {
        return DB::connection($wallet->getConnectionName())->transaction(function () use ($wallet, $cents, $reference, $description): bool {
            $locked = Wallet::query()->lockForUpdate()->findOrFail($wallet->id);
            if ($locked->availableCents() < $cents) {
                return false;
            }
            $locked->decrement('balance_cents', $cents);
            $this->record($locked->refresh(), 'charge', -$cents, $reference, $description);
            $this->checkLowBalance($locked);

            return true;
        });
    }

    private function record(Wallet $wallet, string $type, int $amount, ?string $reference, string $description): void
    {
        WalletTransaction::create([
            'wallet_id' => $wallet->id, 'type' => $type, 'amount_cents' => $amount, 'balance_after_cents' => $wallet->balance_cents,
            'reference' => $reference, 'description' => $description, 'created_at' => now(),
        ]);
    }

    /**
     * Email the owner once each time the wallet drops below its threshold.
     */
    private function checkLowBalance(Wallet $wallet): void
    {
        if ($wallet->acceptsOnlineBookings() || $wallet->low_balance_notified_at !== null) {
            return;
        }
        $wallet->forceFill(['low_balance_notified_at' => now()])->save();

        $send = function () use ($wallet): void {
            $owners = User::query()->whereIn('id', Membership::query()->where('tenant_id', $wallet->tenant_id)->where('role', 'owner')->pluck('user_id'))->get();
            foreach ($owners as $owner) {
                app(SendMessage::class)->template('wallet.low_balance', 'email', $owner->email, [
                    'practice' => $wallet->provider->name,
                    'balance' => 'R'.number_format($wallet->availableCents() / 100, 2, '.', ' '),
                    'threshold' => 'R'.number_format($wallet->thresholdCents() / 100, 2, '.', ' '),
                ]);
            }
        };

        // A platform message: sent outside the provider's context so it is not counted against its allowance.
        tenancy()->initialized ? tenancy()->central($send) : $send();
    }
}
