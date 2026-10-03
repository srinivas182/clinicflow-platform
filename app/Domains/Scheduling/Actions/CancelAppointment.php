<?php

declare(strict_types=1);

namespace App\Domains\Scheduling\Actions;

use App\Domains\Scheduling\Enums\AppointmentStatus;
use App\Domains\Scheduling\Models\Appointment;
use App\Domains\Telemedicine\Models\TeleSession;
use App\Domains\Wallet\Actions\WalletLedger;
use App\Domains\Wallet\Models\WalletReservation;
use App\Models\User;
use Illuminate\Validation\ValidationException;

class CancelAppointment
{
    public function handle(Appointment $appointment, string $reason, ?User $by = null): Appointment
    {
        if (trim($reason) === '') {
            throw ValidationException::withMessages(['reason' => 'Give a reason for cancelling.']);
        }

        if ($appointment->status !== AppointmentStatus::Booked) {
            throw ValidationException::withMessages(['appointment' => 'Only booked appointments can be cancelled.']);
        }

        $appointment->forceFill(['status' => AppointmentStatus::Cancelled, 'cancelled_reason' => trim($reason)])->save();

        $tele = TeleSession::query()->where('appointment_id', $appointment->id)->first();
        if ($tele instanceof TeleSession && $tele->ended_at === null) {
            $tele->forceFill(['status' => 'cancelled', 'ended_at' => now()])->save();
            $reservation = WalletReservation::query()->where('reference', $tele->wallet_reference)->first();
            if ($reservation instanceof WalletReservation) {
                app(WalletLedger::class)->release($reservation, 'Appointment cancelled');
            }
        }

        activity('scheduling')->performedOn($appointment)->causedBy($by)->withProperties(['reason' => $reason])->log('Appointment cancelled');

        return $appointment;
    }
}
