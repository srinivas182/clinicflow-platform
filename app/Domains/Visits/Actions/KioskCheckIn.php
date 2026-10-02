<?php

declare(strict_types=1);

namespace App\Domains\Visits\Actions;

use App\Domains\Patients\Models\Patient;
use App\Domains\Scheduling\Enums\AppointmentStatus;
use App\Domains\Scheduling\Models\Appointment;
use App\Domains\Visits\Models\Visit;
use Illuminate\Validation\ValidationException;

/**
 * Self check-in at the door for patients with a booking today.
 * Walk-ins and new patients are sent to reception.
 */
class KioskCheckIn
{
    public function __construct(private readonly CheckInPatient $checkIn) {}

    public function handle(string $cell): Visit
    {
        $cell = preg_replace('/\D/', '', $cell) ?? '';
        $patientIds = Patient::query()->where('cell', $cell)->pluck('id');

        $appointment = Appointment::query()
            ->whereIn('patient_id', $patientIds)
            ->where('status', AppointmentStatus::Booked->value)
            ->whereDate('starts_at', now()->toDateString())
            ->orderBy('starts_at')
            ->first();

        if (! $appointment instanceof Appointment) {
            throw ValidationException::withMessages(['cell' => "We couldn't find a booking for today. Please see reception."]);
        }

        return $this->checkIn->handle($appointment->patient, null, $appointment, null, 'kiosk');
    }
}
