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
        return DB::transaction(function () use ($invoice, $gateway, $mode, $details): BillingMandate {
            $existing = BillingMandate::query()->where('tenant_id', $invoice->tenant_id)
                ->where('token_hash', BillingMandate::hashToken($details->token))->first();
            if ($existing instanceof BillingMandate) {
                return $existing;
            }

            BillingMandate::query()->where('tenant_id', $invoice->tenant_id)->where('status', BillingMandate::ACTIVE)
                ->update(['status' => BillingMandate::REVOKED, 'revoked_at' => now(), 'revoke_note' => 'Replaced by a new card']);

            $mandate = BillingMandate::create([
                'tenant_id' => $invoice->tenant_id,
                'gateway' => $gateway,
                'mode' => $mode,
                'token' => $details->token,
                'token_hash' => BillingMandate::hashToken($details->token),
                'email' => $details->email,
                'card_brand' => $details->brand,
                'card_last4' => $details->last4,
                'card_expiry' => $details->expiry,
                'status' => BillingMandate::ACTIVE,
                'consented_by' => $invoice->save_card_consented_by,
                'consent_ip' => $invoice->save_card_consent_ip,
                'consented_at' => now(),
            ]);

            activity('platform')->performedOn($invoice->provider)->withProperties(['gateway' => $gateway->value, 'card' => $mandate->label()])
                ->log('Automatic payment switched on');

            return $mandate;
        });
    }
}
