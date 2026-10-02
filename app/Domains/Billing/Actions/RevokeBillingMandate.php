<?php

declare(strict_types=1);

namespace App\Domains\Billing\Actions;

use App\Domains\Billing\Contracts\RecurringPaymentGateway;
use App\Domains\Billing\Gateways\GatewayFactory;
use App\Domains\Billing\Models\BillingMandate;
use App\Domains\Billing\Models\PlatformGatewayConfig;
use App\Models\User;

/**
 * Owner switches automatic payment off. The card is removed at the gateway
 * where possible and is never charged again by Clinic Flow either way.
 * Returns a warning when the gateway did not confirm.
 */
class RevokeBillingMandate
{
    public function handle(BillingMandate $mandate, ?User $by = null): ?string
    {
        $warning = null;
        $config = PlatformGatewayConfig::query()->where('gateway', $mandate->gateway->value)->first();
        $gateway = $config instanceof PlatformGatewayConfig ? GatewayFactory::fromConfig($config) : null;

        if ($gateway instanceof RecurringPaymentGateway) {
            $result = $gateway->revokeMandate($mandate->token, (string) $mandate->email);
            $warning = $result->ok ? null : $result->error;
        }

        $mandate->forceFill(['status' => BillingMandate::REVOKED, 'revoked_at' => now(), 'revoke_note' => $warning ?? 'Switched off by owner'])->save();
        activity('platform')->causedBy($by)->performedOn($mandate->provider)->withProperties(['card' => $mandate->label()])->log('Automatic payment switched off');

        return $warning;
    }
}
