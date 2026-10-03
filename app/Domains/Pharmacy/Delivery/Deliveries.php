<?php

declare(strict_types=1);

namespace App\Domains\Pharmacy\Delivery;

use App\Domains\Billing\Actions\AddInvoiceLine;
use App\Domains\Billing\Enums\LineKind;
use App\Domains\Billing\Models\Invoice;
use App\Domains\Clinical\Models\Consultation;
use App\Domains\Messaging\Actions\SendMessage;
use App\Domains\Patients\Models\Patient;
use App\Domains\Platform\Models\Setting;
use App\Domains\Prescribing\Models\Prescription;
use App\Domains\Prescribing\Models\PrescriptionItem;
use App\Domains\Visits\Models\Visit;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * Medicine delivery: courier choice (manual, or a courier the super admin
 * enabled and the practice linked), who pays (the practice's rule), the S5/S6
 * guard, booking and tracking, and a proof-of-delivery code sent to the patient.
 */
class Deliveries
{
    public const STATUSES = ['requested', 'booked', 'collected', 'in_transit', 'delivered', 'failed'];

    /**
     * @return array{mode: string, fee_cents: int, threshold_cents: int, below_payer: string, above_payer: string, allow_scheduled: bool}
     */
    public static function rules(): array
    {
        return [
            'mode' => (string) Setting::get('delivery', 'mode', 'threshold'), 'fee_cents' => (int) Setting::get('delivery', 'fee_cents', 6500),
            'threshold_cents' => (int) Setting::get('delivery', 'threshold_cents', 50000), 'below_payer' => (string) Setting::get('delivery', 'below_payer', 'patient'),
            'above_payer' => (string) Setting::get('delivery', 'above_payer', 'practice'), 'allow_scheduled' => (bool) Setting::get('delivery', 'allow_scheduled', false),
        ];
    }

    public static function payerFor(int $orderValueCents): string
    {
        $r = self::rules();

        return match ($r['mode']) {
            'patient' => 'patient',
            'practice' => 'practice',
            default => $orderValueCents >= $r['threshold_cents'] ? $r['above_payer'] : $r['below_payer'],
        };
    }

    /**
     * @return array<string, string> drivers this practice can use
     */
    public static function available(): array
    {
        $linked = CourierAccount::query()->where('enabled', true)->pluck('driver')->all();
        $enabled = CourierPartner::query()->where('enabled', true)->whereIn('driver', $linked)->pluck('driver')->all();

        return ['manual' => 'Manual courier'] + array_intersect_key(CourierPartner::LABELS, array_flip($enabled));
    }

    public function request(Visit $visit, string $driver, string $address): Delivery
    {
        if (! array_key_exists($driver, self::available())) {
            throw ValidationException::withMessages(['driver' => 'Choose manual courier or a courier your practice has linked.']);
        }
        if (trim($address) === '') {
            throw ValidationException::withMessages(['address' => 'Enter the delivery address.']);
        }
        $scheduled = PrescriptionItem::query()->whereIn('prescription_id', Prescription::query()->whereIn('consultation_id', Consultation::query()->where('visit_id', $visit->id)->select('id'))->where('status', Prescription::SIGNED)->select('id'))
            ->whereIn('schedule', ['S5', 'S6', 'S7', 'S8'])->exists();
        if ($scheduled && ! self::rules()['allow_scheduled']) {
            throw ValidationException::withMessages(['driver' => 'This script contains Schedule 5 or higher medicine. It must be collected unless your pharmacy is approved for delivery of scheduled medicine (Settings → Delivery).']);
        }

        $invoice = Invoice::query()->where('visit_id', $visit->id)->firstOrFail();
        $orderValue = (int) $invoice->lines()->where('kind', LineKind::Medicine->value)->sum('total_cents');
        $payer = self::payerFor($orderValue);
        $fee = self::rules()['fee_cents'];
        $code = (string) random_int(1000, 9999);

        $delivery = Delivery::create([
            'patient_id' => $visit->patient_id, 'visit_id' => $visit->id, 'driver' => $driver, 'address' => trim($address), 'status' => 'requested',
            'order_value_cents' => $orderValue, 'fee_cents' => $fee, 'payer' => $payer, 'proof_code_hash' => Hash::make($code),
        ]);
        if ($payer === 'patient' && $fee > 0) {
            app(AddInvoiceLine::class)->handle($invoice, LineKind::Other, 'Delivery', $fee, 1, 'DELIVERY');
        }

        $patient = Patient::query()->find($visit->patient_id);
        if ($patient instanceof Patient && filled($patient->cell)) {
            app(SendMessage::class)->handle('sms', (string) $patient->cell, "Your medicine is on its way. Give the courier this code when it arrives: {$code}. Do not share it before then.", null, 'delivery', (string) $delivery->id);
        }
        activity('pharmacy')->performedOn($delivery)->withProperties(['driver' => $driver, 'payer' => $payer])->log('Delivery requested');

        return $delivery;
    }

    public function book(Delivery $delivery, string $tracking): Delivery
    {
        if ($delivery->status !== 'requested' || trim($tracking) === '') {
            throw ValidationException::withMessages(['tracking_number' => 'Enter the courier\'s tracking or waybill number.']);
        }
        $delivery->forceFill(['status' => 'booked', 'tracking_number' => trim($tracking)])->save();

        return $delivery;
    }

    public function update(Delivery $delivery, string $status, ?string $reason = null): Delivery
    {
        if (! in_array($status, ['collected', 'in_transit', 'failed'], true) || in_array($delivery->status, ['delivered', 'failed'], true)) {
            throw ValidationException::withMessages(['status' => 'This delivery cannot move to that status.']);
        }
        if ($status === 'failed' && trim((string) $reason) === '') {
            throw ValidationException::withMessages(['reason' => 'Say why the delivery failed.']);
        }
        $delivery->forceFill(['status' => $status, 'failure_reason' => $reason])->save();

        return $delivery;
    }

    /**
     * Delivered only when the courier enters the code the patient received.
     */
    public function confirm(Delivery $delivery, string $code): Delivery
    {
        if (in_array($delivery->status, ['delivered', 'failed'], true) || ! Hash::check(trim($code), (string) $delivery->proof_code_hash)) {
            throw ValidationException::withMessages(['code' => 'That code does not match. Ask the patient for the code on their phone.']);
        }
        $delivery->forceFill(['status' => 'delivered', 'delivered_at' => now(), 'proof_code_hash' => null])->save();
        activity('pharmacy')->performedOn($delivery)->log('Delivered (code confirmed)');

        return $delivery;
    }
}
