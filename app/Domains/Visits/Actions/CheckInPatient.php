<?php

declare(strict_types=1);

namespace App\Domains\Visits\Actions;

use App\Domains\Billing\Actions\AddInvoiceLine;
use App\Domains\Billing\Actions\OpenInvoice;
use App\Domains\Billing\Enums\LineKind;
use App\Domains\Billing\Support\BillingSettings;
use App\Domains\Patients\Models\Patient;
use App\Domains\Scheduling\Enums\AppointmentStatus;
use App\Domains\Scheduling\Models\Appointment;
use App\Domains\Visits\Enums\PayerType;
use App\Domains\Visits\Enums\VisitStage;
use App\Domains\Visits\Events\VisitStageChanged;
use App\Domains\Visits\Models\Visit;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Opens today's visit: issues a ticket, opens the invoice with the consult fee,
 * and checks in the appointment if there is one.
 */
class CheckInPatient
{
    public function __construct(
        private readonly IssueTicket $tickets,
        private readonly OpenInvoice $openInvoice,
        private readonly AddInvoiceLine $addLine,
    ) {}

    public function handle(Patient $patient, ?PayerType $payer = null, ?Appointment $appointment = null, ?int $preferredStaffId = null, string $channel = 'reception', ?User $by = null): Visit
    {
        if ($patient->getAttribute('needs_consent') === true) {
            throw ValidationException::withMessages(['patient_id' => "Capture {$patient->fullName()}'s POPIA and treatment consent before check-in."]);
        }

        $payer ??= $patient->medical_aid_scheme !== null ? PayerType::MedicalAid : PayerType::Cash;
        $today = now()->startOfDay();

        $open = Visit::query()->where('patient_id', $patient->id)->onDate('visit_date', $today)
            ->whereNotIn('stage', [VisitStage::Done->value, VisitStage::Left->value])->exists();

        if ($open) {
            throw ValidationException::withMessages(['patient_id' => "{$patient->fullName()} is already in today's queue."]);
        }

        if ($appointment !== null) {
            if ($appointment->patient_id !== $patient->id || $appointment->status !== AppointmentStatus::Booked || ! $appointment->starts_at->isSameDay($today)) {
                throw ValidationException::withMessages(['appointment_id' => 'This booking cannot be checked in today.']);
            }
            $preferredStaffId ??= $appointment->staff_id;
        }

        return DB::transaction(function () use ($patient, $payer, $appointment, $preferredStaffId, $channel, $by, $today): Visit {
            $visit = Visit::create([
                'patient_id' => $patient->id,
                'appointment_id' => $appointment?->id,
                'visit_date' => $today,
                'ticket' => $this->tickets->handle($today),
                'stage' => VisitStage::CheckedIn,
                'payer_type' => $payer,
                'preferred_staff_id' => $preferredStaffId,
                'check_in_channel' => $channel,
                'stage_changed_at' => now(),
            ]);

            $visit->events()->create(['from_stage' => null, 'to_stage' => VisitStage::CheckedIn, 'by_staff_id' => $by?->id, 'occurred_at' => now()]);

            $invoice = $this->openInvoice->handle($visit, $payer);
            $this->addLine->handle($invoice, LineKind::Consultation, 'GP consultation', BillingSettings::consultFeeCents(), 1, BillingSettings::consultCode());

            $appointment?->forceFill(['status' => AppointmentStatus::CheckedIn])->save();

            activity('visits')->performedOn($visit)->causedBy($by)->withProperties(['ticket' => $visit->ticket, 'channel' => $channel])->log('Checked in');
            event(VisitStageChanged::fromVisit($visit));

            return $visit;
        });
    }
}
