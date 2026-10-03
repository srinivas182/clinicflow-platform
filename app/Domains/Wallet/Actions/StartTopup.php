<?php

declare(strict_types=1);

namespace App\Domains\Wallet\Actions;

use App\Domains\Billing\Contracts\RecurringPaymentGateway;
use App\Domains\Billing\Gateways\GatewayFactory;
use App\Domains\Billing\Models\BillingMandate;
use App\Domains\Billing\Models\PlatformGatewayConfig;
use App\Domains\Wallet\Models\Wallet;
use App\Domains\Wallet\Models\WalletTopup;
use App\Domains\Wallet\Support\WalletSettings;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Top-ups use the platform's payment gateway (pay link) or the provider's
 * saved card from subscription auto-debit (Paystack, Peach). VAT is added.
 */
class StartTopup
{
    public function __construct(private readonly WalletLedger $ledger) {}

    public function create(Wallet $wallet, int $amountCents, string $method = 'pay_link'): WalletTopup
    {
        $pack = collect(WalletSettings::packs())->firstWhere('amount', $amountCents);
        if ($pack === null) {
            throw ValidationException::withMessages(['amount' => 'Choose one of the top-up packs.']);
        }

        return WalletTopup::create([
            'wallet_id' => $wallet->id,
            'amount_cents' => $pack['amount'],
            'bonus_cents' => $pack['bonus'],
            'vat_cents' => (int) round($pack['amount'] * (float) config('clinicflow.payments.vat_rate', 0.15)),
            'status' => 'pending',
            'method' => $method,
            'checkout_token' => Str::random(48),
        ]);
    }

    /**
     * Charge the saved card; returns false when no chargeable card is on file.
     */
    public function chargeSavedCard(WalletTopup $topup): bool
    {
        $wallet = $topup->wallet;
        $mandate = BillingMandate::activeFor($wallet->tenant_id);
        if (! $mandate instanceof BillingMandate || $mandate->gateway->chargesMandateItself()) {
            return false;
        }

        $config = PlatformGatewayConfig::query()->where('gateway', $mandate->gateway->value)->where('enabled', true)->first();
        $gateway = $config instanceof PlatformGatewayConfig ? GatewayFactory::fromConfig($config) : null;
        if (! $gateway instanceof RecurringPaymentGateway) {
            return false;
        }

        $result = $gateway->chargeMandate($mandate->token, (string) $mandate->email, $topup->amount_cents + $topup->vat_cents, $topup->checkout_token);
        if (! $result->ok) {
            $topup->forceFill(['status' => 'failed'])->save();

            return false;
        }

        $this->ledger->creditTopup($topup, $mandate->gateway->value, $result->gatewayReference);

        return true;
    }
}
