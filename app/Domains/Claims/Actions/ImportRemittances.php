<?php

declare(strict_types=1);

namespace App\Domains\Claims\Actions;

use App\Domains\Billing\Actions\RecordPayment;
use App\Domains\Billing\Enums\PaymentMethod;
use App\Domains\Claims\Contracts\ClaimsSwitch;
use App\Domains\Claims\Models\Claim;
use App\Domains\Claims\Models\Remittance;
use Illuminate\Support\Facades\DB;

/**
 * Applies remittance advice: records the scheme's payment on the invoice,
 * marks the claim paid or part-paid, and flags any shortfall as a patient
 * co-payment. Each remittance line is applied once.
 */
class ImportRemittances
{
    public function __construct(private readonly ClaimsSwitch $switch, private readonly RecordPayment $payments) {}

    /**
     * @return array{applied: int, shortfalls: int}
     */
    public function handle(): array
    {
        $claims = Claim::query()->with('invoice')->where('status', 'accepted')->whereNotNull('switch_reference')->get()->keyBy('switch_reference');
        $outstanding = $claims->map(fn (Claim $c) => ['reference' => (string) $c->switch_reference, 'total_cents' => $c->total_cents, 'member_number' => $c->member_number])->values()->all();

        $applied = 0;
        $shortfalls = 0;

        foreach ($outstanding === [] ? [] : $this->switch->fetchRemittances($outstanding) as $line) {
            $claim = $claims->get($line->switchReference);
            if (! $claim instanceof Claim || Remittance::query()->where('scheme_reference', $line->schemeReference)->exists()) {
                continue;
            }

            DB::transaction(function () use ($claim, $line, &$applied, &$shortfalls): void {
                Remittance::create(['claim_id' => $claim->id, 'scheme_reference' => $line->schemeReference, 'paid_cents' => $line->paidCents, 'message' => $line->message, 'received_at' => now()]);

                $invoice = $claim->invoice;
                $amount = min($line->paidCents, $invoice->balanceCents());
                if ($amount > 0) {
                    $this->payments->handle($invoice, PaymentMethod::MedicalAid, $amount, $line->schemeReference);
                }

                $short = $claim->total_cents - $line->paidCents;
                $claim->forceFill(['status' => $short > 0 ? 'part_paid' : 'paid'])->save();
                if ($short > 0) {
                    $invoice->refresh()->forceFill(['needs_review' => true, 'review_note' => 'Medical aid paid R'.number_format($line->paidCents / 100, 2, '.', ' ').'; co-payment of R'.number_format($short / 100, 2, '.', ' ').' due from the patient.'])->save();
                    $shortfalls++;
                }

                activity('claims')->performedOn($claim)->withProperties(['paid_cents' => $line->paidCents, 'reference' => $line->schemeReference])->log('Remittance applied');
                $applied++;
            });
        }

        return ['applied' => $applied, 'shortfalls' => $shortfalls];
    }
}
