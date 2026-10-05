<?php

declare(strict_types=1);

namespace App\Domains\Api\Webhooks;

use App\Domains\Billing\Enums\InvoiceStatus;
use App\Domains\Billing\Models\Invoice;
use App\Domains\Patients\Models\Patient;
use App\Domains\Scheduling\Enums\AppointmentStatus;
use App\Domains\Scheduling\Models\Appointment;

/**
 * Turns practice events into webhook deliveries (IDs and status only).
 */
final class WebhookEvents
{
    public static function register(): void
    {
        Appointment::created(fn (Appointment $a) => self::send('appointment.booked', self::appointment($a)));
        Appointment::updated(function (Appointment $a): void {
            if (! $a->wasChanged('status')) {
                return;
            }
            match ($a->status) {
                AppointmentStatus::Cancelled => self::send('appointment.cancelled', self::appointment($a)),
                AppointmentStatus::CheckedIn => self::send('appointment.checked_in', self::appointment($a)),
                default => null,
            };
        });
        Invoice::updated(function (Invoice $i): void {
            if ($i->wasChanged('status') && $i->status === InvoiceStatus::Paid) {
                self::send('invoice.paid', ['invoice_id' => $i->id, 'number' => $i->number, 'patient_id' => $i->patient_id, 'total' => $i->total_cents / 100]);
            }
        });
        Patient::created(fn (Patient $p) => self::send('patient.registered', ['patient_id' => $p->id]));
    }

    /**
     * @return array<string, mixed>
     */
    private static function appointment(Appointment $a): array
    {
        return ['appointment_id' => $a->id, 'patient_id' => $a->patient_id, 'staff_id' => $a->staff_id, 'starts_at' => $a->starts_at->toIso8601String(), 'status' => $a->status->value];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function send(string $event, array $data): void
    {
        if (tenant() !== null) {
            app(Webhooks::class)->dispatch($event, $data);
        }
    }
}
