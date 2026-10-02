<?php

declare(strict_types=1);

namespace App\Domains\Visits\Support;

use App\Domains\Billing\Models\Invoice;
use App\Domains\Claims\Models\Claim;
use App\Domains\Visits\Enums\PayerType;
use App\Domains\Visits\Models\Visit;

/**
 * What the patient must still pay before leaving. For medical aid patients
 * with a submitted or accepted claim, the scheme's share is not due from the
 * patient at the counter; any shortfall becomes a co-payment after remittance.
 */
final class DischargeGate
{
    public static function patientDueCents(Visit $visit): int
    {
        $invoice = Invoice::query()->where('visit_id', $visit->id)->first();
        if (! $invoice instanceof Invoice) {
            return 0;
        }

        if ($visit->payer_type === PayerType::MedicalAid
            && Claim::query()->where('invoice_id', $invoice->id)->whereIn('status', ['submitted', 'accepted'])->exists()) {
            return 0;
        }

        return $invoice->balanceCents();
    }
}
