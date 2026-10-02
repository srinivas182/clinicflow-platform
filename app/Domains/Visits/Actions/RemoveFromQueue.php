<?php

declare(strict_types=1);

namespace App\Domains\Visits\Actions;

use App\Domains\Billing\Enums\RefundRule;
use App\Domains\Billing\Models\Invoice;
use App\Domains\Billing\Support\BillingSettings;
use App\Domains\Scheduling\Enums\AppointmentStatus;
use App\Domains\Scheduling\Models\Appointment;
use App\Domains\Visits\Enums\LeftReason;
use App\Domains\Visits\Enums\VisitStage;
use App\Domains\Visits\Events\VisitStageChanged;
use App\Domains\Visits\Models\Visit;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * Removes a waiting patient with one of four reasons. A prepaid consult fee is
 * flagged for billing according to the clinic's refund rule.
 */
class RemoveFromQueue
{
    public function handle(Visit $visit, LeftReason $reason, ?string $note = null, ?User $by = null): Visit
    {
        if (! $visit->stage->isWaiting()) {
            throw ValidationException::withMessages(['stage' => 'Only patients who are still waiting can be removed from the queue.']);
        }

        $from = $visit->stage;
        $visit->forceFill(['stage' => VisitStage::Left, 'left_reason' => $reason, 'left_note' => $note, 'stage_changed_at' => now()])->save();
        $visit->events()->create(['from_stage' => $from, 'to_stage' => VisitStage::Left, 'by_staff_id' => $by?->id, 'occurred_at' => now()]);

        if ($visit->appointment_id !== null && in_array($reason, [LeftReason::LeftBeforeSeen, LeftReason::NoAnswerWhenCalled], true)) {
            Appointment::query()->whereKey($visit->appointment_id)->update(['status' => AppointmentStatus::NoShow->value]);
        }

        $invoice = Invoice::query()->where('visit_id', $visit->id)->first();

        if ($invoice !== null && $invoice->paid_cents > 0) {
            $note = match (BillingSettings::refundRule()) {
                RefundRule::Refund => 'Patient left before being seen — refund due (clinic rule).',
                RefundRule::Credit => 'Patient left before being seen — credit for the next visit (clinic rule).',
                RefundRule::NoRefund => null,
            };

            if ($note !== null) {
                $invoice->forceFill(['needs_review' => true, 'review_note' => $note])->save();
            }
        }

        activity('visits')->performedOn($visit)->causedBy($by)->withProperties(['reason' => $reason->value])->log('Removed from queue');
        event(VisitStageChanged::fromVisit($visit));

        return $visit;
    }
}
