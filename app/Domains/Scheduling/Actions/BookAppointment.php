<?php

declare(strict_types=1);

namespace App\Domains\Scheduling\Actions;

use App\Domains\Identity\Models\Staff;
use App\Domains\Patients\Models\Patient;
use App\Domains\Scheduling\Enums\AppointmentStatus;
use App\Domains\Scheduling\Enums\ConsultType;
use App\Domains\Scheduling\Enums\SessionType;
use App\Domains\Scheduling\Models\Appointment;
use App\Domains\Scheduling\Models\RosterSession;
use App\Domains\Telemedicine\Models\TeleSession;
use App\Domains\Telemedicine\Support\Telemedicine;
use App\Domains\Wallet\Actions\WalletLedger;
use App\Domains\Wallet\Models\Wallet;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Books a patient into a free slot of a doctor's roster session.
 */
class BookAppointment
{
    public function handle(Patient $patient, Staff $doctor, CarbonInterface $startsAt, ConsultType $type = ConsultType::InPerson, ?string $reason = null, ?User $bookedBy = null): Appointment
    {
        $startsAt = CarbonImmutable::parse($startsAt);

        if (! $doctor->hasAnyRole(['doctor', 'locum_doctor'])) {
            throw ValidationException::withMessages(['staff_id' => 'Appointments can only be booked with a doctor.']);
        }

        $provider = tenant();
        if ($type === ConsultType::Chat) {
            throw ValidationException::withMessages(['consult_type' => 'Chat consults open with secure chat.']);
        }
        if ($type->isRemote() && ($provider === null || ! Telemedicine::enabledFor((string) $provider->getTenantKey()))) {
            throw ValidationException::withMessages(['consult_type' => 'Online consults need the Telemedicine add-on.']);
        }

        if ($startsAt->isPast()) {
            throw ValidationException::withMessages(['starts_at' => 'Choose a time in the future.']);
        }

        return DB::transaction(function () use ($patient, $doctor, $startsAt, $type, $reason, $bookedBy): Appointment {
            $session = RosterSession::query()
                ->where('staff_id', $doctor->id)
                ->where('session_type', ($type->isRemote() ? SessionType::Telemedicine : SessionType::InPerson)->value)
                ->where('starts_at', '<=', $startsAt)
                ->where('ends_at', '>', $startsAt)
                ->lockForUpdate()
                ->first();

            if (! $session instanceof RosterSession) {
                throw ValidationException::withMessages(['starts_at' => "{$doctor->name} is not working at this time."]);
            }

            $offset = (int) CarbonImmutable::parse($session->starts_at)->diffInMinutes($startsAt);
            $endsAt = $startsAt->addMinutes($session->slot_minutes);

            if ($offset % $session->slot_minutes !== 0 || $endsAt->greaterThan($session->ends_at)) {
                throw ValidationException::withMessages(['starts_at' => 'Choose one of the available slot times.']);
            }

            $clash = fn (string $column, int|string $value) => Appointment::query()
                ->where($column, $value)
                ->whereIn('status', AppointmentStatus::occupying())
                ->where('starts_at', '<', $endsAt)
                ->where('ends_at', '>', $startsAt)
                ->exists();

            if ($clash('staff_id', $doctor->id)) {
                throw ValidationException::withMessages(['starts_at' => 'This slot has just been taken. Choose another.']);
            }

            if ($clash('patient_id', $patient->id)) {
                throw ValidationException::withMessages(['patient_id' => 'This patient already has an appointment at this time.']);
            }

            $appointment = Appointment::create([
                'patient_id' => $patient->id,
                'staff_id' => $doctor->id,
                'roster_session_id' => $session->id,
                'consult_type' => $type,
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
                'status' => AppointmentStatus::Booked,
                'reason' => $reason,
                'booked_by' => $bookedBy?->id,
            ]);

            if ($type->isRemote() && $provider !== null) {
                // Reserve the expected cost in the provider's wallet; fails (and rolls back) below the minimum.
                $reference = 'appt-'.$appointment->id;
                app(WalletLedger::class)->reserve(Wallet::for((string) $provider->getTenantKey()), $reference, $type->value, $session->slot_minutes);
                TeleSession::create([
                    'appointment_id' => $appointment->id,
                    'room_name' => Telemedicine::roomName((string) $provider->getTenantKey(), $appointment->id),
                    'wallet_reference' => $reference,
                ]);
            }

            activity('scheduling')->performedOn($appointment)->causedBy($bookedBy)->log('Appointment booked');

            return $appointment;
        });
    }
}
