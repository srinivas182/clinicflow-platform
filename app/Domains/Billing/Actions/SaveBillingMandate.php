<?php

declare(strict_types=1);

namespace App\Domains\Billing\Actions;

use App\Domains\Billing\Enums\Gateway;
use App\Domains\Billing\Enums\GatewayMode;
use App\Domains\Billing\Gateways\MandateDetails;
use App\Domains\Billing\Models\BillingMandate;
use App\Domains\Billing\Models\SubscriptionInvoice;
use Illuminate\Support\Facades\DB;

/**
 * Stores the saved card returned after a payment the owner agreed to save.
 * A new card replaces the previous active one.
 */
class SaveBillingMandate
{
    public function handle(SubscriptionInvoice $invoice, Gateway $gateway, GatewayMode $mode, MandateDetails $details): BillingMandate
    {
        return $this->forTenant($invoice->tenant_id, $gateway, $mode, $details, $invoice->save_card_consented_by, $invoice->save_card_consent_ip);
    }

    /** Saves a card with the owner's recorded consent (from an invoice payment or a R1 card check). */
    public function forTenant(string $tenantId, Gateway $gateway, GatewayMode $mode, MandateDetails $details, ?int $consentedBy, ?string $consentIp): BillingMandate
    {
        return DB::transaction(function () use ($tenantId, $gateway, $mode, $details, $consentedBy, $consentIp): BillingMandate {
            $existing = BillingMandate::query()->where('tenant_id', $tenantId)
                ->where('token_hash', BillingMandate::hashToken($details->token))->first();
            if ($existing instanceof BillingMandate) {
                return $existing;
            }

            BillingMandate::query()->where('tenant_id', $tenantId)->where('status', BillingMandate::ACTIVE)
                ->update(['status' => BillingMandate::REVOKED, 'revoked_at' => now(), 'revoke_note' => 'Replaced by a new card']);

            $mandate = BillingMandate::create([
                'tenant_id' => $tenantId,
                'gateway' => $gateway,
                'mode' => $mode,
                'token' => $details->token,
                'token_hash' => BillingMandate::hashToken($details->token),
                'email' => $details->email,
                'card_brand' => $details->brand,
                'card_last4' => $details->last4,
                'card_expiry' => $details->expiry,
                'status' => BillingMandate::ACTIVE,
                'consented_by' => $consentedBy,
                'consent_ip' => $consentIp,
                'consented_at' => now(),
            ]);

            activity('platform')->performedOn($mandate->provider)->withProperties(['gateway' => $gateway->value, 'card' => $mandate->label()])
                ->log('Automatic payment switched on');

            return $mandate;
        });
    }
}
