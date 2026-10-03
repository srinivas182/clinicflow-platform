<?php

declare(strict_types=1);

namespace App\Domains\Telemedicine\Actions;

use App\Domains\Scheduling\Enums\AppointmentStatus;
use App\Domains\Telemedicine\Models\TeleSession;
use App\Domains\Wallet\Actions\WalletLedger;
use App\Domains\Wallet\Models\WalletReservation;
use Carbon\CarbonImmutable;

/**
 * Applies LiveKit room events. Minutes are counted only while both the doctor
 * and the patient are connected; the wallet is charged once when the call
 * ends, or the reservation is released if they never connected.
 */
class TrackTeleSession
{
    public function __construct(private readonly WalletLedger $ledger) {}

    public function handle(TeleSession $session, string $event, ?string $identity, CarbonImmutable $at): TeleSession
    {
        if ($session->ended_at !== null) {
            return $session;
        }

        $who = $identity !== null && str_starts_with($identity, 'doctor-') ? 'doctor' : ($identity !== null && str_starts_with($identity, 'patient-') ? 'patient' : null);

        if ($event === 'participant_joined' && $who !== null) {
            $session->forceFill(["{$who}_joined_at" => $session->getAttribute("{$who}_joined_at") ?? $at, 'status' => 'waiting'])->save();
            if ($session->doctor_joined_at !== null && $session->patient_joined_at !== null && $session->connected_at === null) {
                $session->forceFill(['connected_at' => $at, 'status' => 'live'])->save();
            }

            return $session;
        }

        if (($event === 'participant_left' && $session->connected_at !== null) || $event === 'room_finished') {
            $this->end($session, $at);
        }

        return $session;
    }

    private function end(TeleSession $session, CarbonImmutable $at): void
    {
        $reservation = WalletReservation::query()->where('reference', $session->wallet_reference)->first();

        if ($session->connected_at === null) {
            $session->forceFill(['status' => 'failed', 'ended_at' => $at])->save();
            if ($reservation instanceof WalletReservation) {
                $this->ledger->release($reservation, 'Consult never connected');
            }

            return;
        }

        $seconds = max(0, (int) $session->connected_at->diffInSeconds($at));
        $minutes = max(1, (int) ceil($seconds / 60));
        $session->forceFill(['status' => 'ended', 'ended_at' => $at, 'connected_seconds' => $seconds, 'charged_minutes' => $minutes])->save();
        $session->appointment->forceFill(['status' => AppointmentStatus::Completed])->save();

        if ($reservation instanceof WalletReservation && $reservation->status === 'held') {
            $this->ledger->capture($reservation, $minutes);
        }

        activity('telemedicine')->performedOn($session->appointment)->withProperties(['seconds' => $seconds, 'minutes' => $minutes])->log('Online consult ended');
    }
}
