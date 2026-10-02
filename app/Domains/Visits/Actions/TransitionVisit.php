<?php

declare(strict_types=1);

namespace App\Domains\Visits\Actions;

use App\Domains\Billing\Enums\PaymentTiming;
use App\Domains\Billing\Models\Invoice;
use App\Domains\Billing\Support\BillingSettings;
use App\Domains\Scheduling\Enums\AppointmentStatus;
use App\Domains\Scheduling\Models\Appointment;
use App\Domains\Visits\Enums\PayerType;
use App\Domains\Visits\Enums\VisitStage;
use App\Domains\Visits\Events\VisitStageChanged;
use App\Domains\Visits\Models\Visit;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * The only way a visit changes stage. Enforces the lifecycle on the server
 * (not just on screens) and records every move with a timestamp.
 */
class TransitionVisit
{
    public function handle(Visit $visit, VisitStage $to, ?User $by = null): Visit
    {
        if (! $visit->stage->canMoveTo($to)) {
            throw ValidationException::withMessages(['stage' => "A visit can't move from {$visit->stage->label()} to {$to->label()}."]);
        }

        if ($visit->stage === VisitStage::CheckedIn && $to === VisitStage::Triage && $this->consultFeeOutstanding($visit)) {
            throw ValidationException::withMessages(['stage' => 'Take the consultation fee before triage (clinic rule for cash patients).']);
        }

        $from = $visit->stage;
        $visit->forceFill(['stage' => $to, 'stage_changed_at' => now()])->save();
        $visit->events()->create(['from_stage' => $from, 'to_stage' => $to, 'by_staff_id' => $by?->id, 'occurred_at' => now()]);

        if ($to === VisitStage::Done && $visit->appointment_id !== null) {
            Appointment::query()->whereKey($visit->appointment_id)->update(['status' => AppointmentStatus::Completed->value]);
        }

        activity('visits')->performedOn($visit)->causedBy($by)->withProperties(['from' => $from->value, 'to' => $to->value])->log('Stage changed');
        event(VisitStageChanged::fromVisit($visit));

        return $visit;
    }

    private function consultFeeOutstanding(Visit $visit): bool
    {
        if ($visit->payer_type !== PayerType::Cash || BillingSettings::paymentTiming() !== PaymentTiming::AtCheckIn) {
            return false;
        }

        $invoice = Invoice::query()->where('visit_id', $visit->id)->first();

        if ($invoice === null) {
            return false;
        }

        $consult = (int) $invoice->lines()->where('kind', 'consultation')->sum('total_cents');

        return $invoice->paid_cents < $consult;
    }
}
