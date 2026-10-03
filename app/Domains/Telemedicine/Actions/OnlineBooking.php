<?php

declare(strict_types=1);

namespace App\Domains\Telemedicine\Actions;

use App\Domains\Billing\Actions\AddInvoiceLine;
use App\Domains\Billing\Actions\OpenInvoice;
use App\Domains\Billing\Actions\RecordPayment;
use App\Domains\Billing\Actions\RefundPayment;
use App\Domains\Billing\Enums\Gateway;
use App\Domains\Billing\Enums\InvoiceStatus;
use App\Domains\Billing\Enums\LineKind;
use App\Domains\Billing\Enums\PaymentMethod;
use App\Domains\Billing\Enums\PaymentStatus;
use App\Domains\Billing\Models\Invoice;
use App\Domains\Billing\Models\Payment;
use App\Domains\Clinical\Models\Consultation;
use App\Domains\Identity\Models\Staff;
use App\Domains\Messaging\Actions\SendMessage;
use App\Domains\Patients\Models\Patient;
use App\Domains\Platform\Models\Provider;
use App\Domains\Scheduling\Enums\AppointmentStatus;
use App\Domains\Scheduling\Enums\ConsultType;
use App\Domains\Scheduling\Models\Appointment;
use App\Domains\Telemedicine\Models\ChatThread;
use App\Domains\Telemedicine\Models\RefundTask;
use App\Domains\Telemedicine\Models\TeleSession;
use App\Domains\Telemedicine\Support\OnlineSlots;
use App\Domains\Telemedicine\Support\Telemedicine;
use App\Domains\Telemedicine\Support\TeleSettings;
use App\Domains\Visits\Enums\PayerType;
use App\Domains\Visits\Enums\VisitStage;
use App\Domains\Visits\Models\Visit;
use App\Domains\Wallet\Actions\WalletLedger;
use App\Domains\Wallet\Models\Wallet;
use App\Domains\Wallet\Models\WalletReservation;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Paid online consults: book (slot held while the patient pays), confirm on
 * payment, expire unpaid holds, cancel with the practice's refund policy,
 * refund doctor no-shows, extend during a call and close finished consults.
 * Each consult has a virtual visit so notes, prescriptions, invoices and
 * finance reports work exactly as for in-person visits.
 */
class OnlineBooking
{
    public function __construct(
        private readonly WalletLedger $wallet,
        private readonly OpenInvoice $invoices,
        private readonly AddInvoiceLine $lines,
        private readonly RecordPayment $payments,
        private readonly RefundPayment $refunds,
        private readonly SendMessage $messages,
    ) {}

    public function book(Patient $patient, Staff $doctor, ConsultType $mode, int $duration, CarbonImmutable $start, ?User $by = null): Appointment
    {
        $provider = $this->provider();
        if (! $mode->isRemote()) {
            throw ValidationException::withMessages(['mode' => 'Choose video, audio or chat.']);
        }
        if (! Telemedicine::enabledFor($provider->id)) {
            throw ValidationException::withMessages(['mode' => 'Online consults need the Telemedicine add-on.']);
        }
        $price = OnlineSlots::priceCents($doctor->id, $mode->value, $duration);
        if ($price === null) {
            throw ValidationException::withMessages(['duration' => 'This duration is not offered for '.$mode->value.' consults.']);
        }
        if (! OnlineSlots::isAvailable($doctor->id, $mode->value, $duration, $start)) {
            throw ValidationException::withMessages(['starts_at' => 'That time is not available. Choose another.']);
        }

        return DB::transaction(function () use ($patient, $doctor, $mode, $duration, $start, $by, $price, $provider): Appointment {
            $seq = Visit::query()->whereDate('visit_date', $start)->where('ticket', 'like', 'V%')->lockForUpdate()->count() + 1;
            $visit = Visit::create([
                'patient_id' => $patient->id, 'visit_date' => $start->toDateString(), 'ticket' => 'V'.str_pad((string) $seq, 3, '0', STR_PAD_LEFT),
                'stage' => VisitStage::Done, 'payer_type' => PayerType::Cash, 'doctor_id' => $doctor->id,
                'check_in_channel' => 'online', 'stage_changed_at' => now(),
            ]);
            $invoice = $this->invoices->handle($visit, PayerType::Cash);
            $this->lines->handle($invoice, LineKind::Consultation, ucfirst($mode->value)." consult · {$duration} min", $price, 1, 'ONLINE')
                ->forceFill(['attributed_staff_id' => $doctor->id])->save();

            $appointment = Appointment::create([
                'patient_id' => $patient->id, 'staff_id' => $doctor->id, 'consult_type' => $mode,
                'starts_at' => $start, 'ends_at' => $start->addMinutes($duration), 'status' => AppointmentStatus::Booked,
                'duration_minutes' => $duration, 'price_cents' => $price, 'payment_status' => 'pending',
                'hold_expires_at' => now()->addMinutes(TeleSettings::get('hold_minutes')), 'visit_id' => $visit->id, 'booked_by' => $by?->id,
            ]);
            $visit->forceFill(['appointment_id' => $appointment->id])->save();
            Consultation::create(['visit_id' => $visit->id, 'patient_id' => $patient->id, 'doctor_staff_id' => $doctor->id]);

            $reference = 'appt-'.$appointment->id;
            $this->wallet->reserve(Wallet::for($provider->id), $reference, $mode->value, $duration);
            TeleSession::create(['appointment_id' => $appointment->id, 'room_name' => Telemedicine::roomName($provider->id, $appointment->id), 'wallet_reference' => $reference]);
            activity('telemedicine')->performedOn($appointment)->causedBy($by)->withProperties(['mode' => $mode->value, 'duration' => $duration])->log('Online consult booked (awaiting payment)');

            return $appointment;
        });
    }

    /**
     * Pay link for the consult fee through the practice's own gateway.
     */
    public function payLink(Appointment $appointment): Payment
    {
        if ($appointment->getAttribute('payment_status') !== 'pending') {
            throw ValidationException::withMessages(['appointment' => 'This consult does not need payment.']);
        }

        return $this->payments->handle($this->invoice($appointment), PaymentMethod::PayLink, (int) $appointment->getAttribute('price_cents'));
    }

    /**
     * Called when a payment on the consult's invoice succeeds.
     */
    public function paymentSucceeded(Payment $payment): void
    {
        $visitId = Invoice::query()->whereKey($payment->invoice_id)->value('visit_id');
        $appointment = $visitId === null ? null : Appointment::query()->where('visit_id', $visitId)->first();
        if (! $appointment instanceof Appointment) {
            return;
        }

        $session = TeleSession::query()->where('appointment_id', $appointment->id)->first();
        if ($session instanceof TeleSession && (string) $session->getAttribute('pending_extension_payment') === (string) $payment->id) {
            $this->applyExtension($appointment, $session);

            return;
        }

        if ($appointment->getAttribute('payment_status') !== 'pending' || $appointment->status !== AppointmentStatus::Booked) {
            return;
        }

        $appointment->forceFill(['payment_status' => 'paid', 'hold_expires_at' => null])->save();
        if ($appointment->consult_type === ConsultType::Chat) {
            ChatThread::create([
                'appointment_id' => $appointment->id, 'patient_id' => $appointment->patient_id, 'doctor_staff_id' => $appointment->staff_id,
                'kind' => 'consult', 'opens_at' => $appointment->starts_at, 'closes_at' => $appointment->ends_at->copy()->addMinutes(TeleSettings::get('grace_minutes')),
            ]);
        }

        $patient = Patient::query()->find($appointment->patient_id);
        if ($patient instanceof Patient && filled($patient->cell)) {
            $this->messages->template('booking.confirmation', 'sms', (string) $patient->cell, [
                'patient' => $patient->first_names, 'date' => $appointment->starts_at->format('j M'), 'time' => $appointment->starts_at->format('H:i'),
                'doctor' => (string) Staff::query()->whereKey($appointment->staff_id)->value('name'),
            ], 'en', 'appointment', $appointment->id);
        }
        activity('telemedicine')->performedOn($appointment)->log('Online consult paid and confirmed');
    }

    public function expireHolds(): int
    {
        $count = 0;
        Appointment::query()->where('payment_status', 'pending')->where('hold_expires_at', '<', now())->each(function (Appointment $a) use (&$count): void {
            $this->close($a, 'Payment not completed in time', 'expired');
            $count++;
        });

        return $count;
    }

    /**
     * Patient or practice cancels. Refund policy: full when the practice
     * cancels or the patient cancels before the cut-off; none inside it.
     */
    public function cancel(Appointment $appointment, string $by, string $reason, ?User $user = null): Appointment
    {
        if ($appointment->status !== AppointmentStatus::Booked) {
            throw ValidationException::withMessages(['appointment' => 'Only upcoming consults can be cancelled.']);
        }
        if (trim($reason) === '') {
            throw ValidationException::withMessages(['reason' => 'Give a reason for cancelling.']);
        }

        if ($appointment->getAttribute('payment_status') === 'pending') {
            $this->close($appointment, trim($reason), 'expired');

            return $appointment;
        }

        $cutoff = $appointment->starts_at->copy()->subMinutes(TeleSettings::get('cancel_cutoff_minutes'));
        $refund = $by === 'practice' || now()->lt($cutoff);
        $this->close($appointment, trim($reason), $refund ? 'refunded' : 'paid');
        if ($refund) {
            $this->refund($appointment, $by === 'practice' ? 'Cancelled by the practice' : 'Cancelled by the patient before the cut-off', $user);
        }
        activity('telemedicine')->performedOn($appointment)->causedBy($user)->withProperties(['by' => $by, 'refund' => $refund])->log('Online consult cancelled');

        return $appointment;
    }

    /**
     * Doctor did not join within the fixed no-show time: full refund.
     */
    public function refundDoctorNoShows(): int
    {
        $count = 0;
        Appointment::query()->where('payment_status', 'paid')->where('status', AppointmentStatus::Booked->value)
            ->where('starts_at', '<', now()->subMinutes(TeleSettings::DOCTOR_NO_SHOW_MINUTES))->get()
            ->each(function (Appointment $a) use (&$count): void {
                $session = TeleSession::query()->where('appointment_id', $a->id)->first();
                if (! $session instanceof TeleSession || $session->doctor_joined_at !== null || $session->ended_at !== null) {
                    return;
                }
                if ($a->consult_type === ConsultType::Chat && ChatThread::query()->where('appointment_id', $a->id)->whereHas('messages', fn ($q) => $q->where('sender', 'doctor'))->exists()) {
                    return;
                }
                $this->cancel($a, 'practice', 'The doctor did not join');
                $count++;
            });

        return $count;
    }

    /**
     * Doctor adds time during a call; the patient pays the extension first.
     */
    public function extend(Appointment $appointment): Payment
    {
        $session = TeleSession::query()->where('appointment_id', $appointment->id)->firstOrFail();
        if ($session->ended_at !== null || $appointment->getAttribute('payment_status') !== 'paid') {
            throw ValidationException::withMessages(['appointment' => 'Only a running, paid consult can be extended.']);
        }
        if ($session->getAttribute('pending_extension_payment') !== null) {
            throw ValidationException::withMessages(['appointment' => 'An extension is already waiting for payment.']);
        }

        $ext = TeleSettings::get('extension_minutes');
        $mode = $appointment->consult_type->value;
        $current = (int) $appointment->getAttribute('duration_minutes');
        $longer = OnlineSlots::priceCents($appointment->staff_id, $mode, $current + $ext);
        $now = OnlineSlots::priceCents($appointment->staff_id, $mode, $current);
        $price = $longer !== null && $now !== null ? max(0, $longer - $now) : OnlineSlots::priceCents($appointment->staff_id, $mode, $ext);
        if ($price === null) {
            throw ValidationException::withMessages(['appointment' => 'Set a price for a '.$ext.'-minute '.$mode.' consult first.']);
        }

        return DB::transaction(function () use ($appointment, $session, $ext, $mode, $price): Payment {
            $invoice = $this->invoice($appointment);
            $this->lines->handle($invoice, LineKind::Consultation, ucfirst($mode)." consult extension · {$ext} min", $price, 1, 'ONLINE-EXT')
                ->forceFill(['attributed_staff_id' => $appointment->staff_id])->save();
            $payment = $this->payments->handle($invoice, PaymentMethod::PayLink, $price);
            $session->forceFill(['pending_extension_payment' => $payment->id])->save();

            return $payment;
        });
    }

    /**
     * Ends consults whose booked time (plus extensions and grace) is over.
     */
    public function closeFinished(TrackTeleSession $tracker): int
    {
        $grace = TeleSettings::get('grace_minutes');
        $count = 0;
        TeleSession::query()->with('appointment')->whereNull('ended_at')->get()->each(function (TeleSession $s) use ($grace, $tracker, &$count): void {
            $a = $s->appointment;
            if ($a->status !== AppointmentStatus::Booked || $a->getAttribute('payment_status') !== 'paid' || $a->ends_at->copy()->addMinutes($grace)->isFuture()) {
                return;
            }

            if ($a->consult_type === ConsultType::Chat) {
                $senders = ChatThread::query()->where('appointment_id', $a->id)->where('kind', 'consult')->first()?->messages()->distinct()->pluck('sender')->all() ?? [];
                $reservation = WalletReservation::query()->where('reference', $s->wallet_reference)->first();
                $happened = in_array('doctor', $senders, true) && in_array('patient', $senders, true);
                if ($reservation instanceof WalletReservation) {
                    $happened ? $this->wallet->capture($reservation, 1) : $this->wallet->release($reservation, 'Chat consult did not take place');
                }
                $s->forceFill(['status' => $happened ? 'ended' : 'failed', 'ended_at' => now()])->save();
                if ($happened) {
                    $a->forceFill(['status' => AppointmentStatus::Completed])->save();
                }
            } else {
                $tracker->finish($s, CarbonImmutable::now());
                LiveKitRooms::delete($s->room_name);
                if ($s->fresh()?->status === 'failed') {
                    $a->forceFill(['status' => AppointmentStatus::NoShow])->save();
                }
            }

            WalletReservation::query()->where('reference', 'like', 'appt-'.$a->id.'-ext%')->where('status', 'held')->get()
                ->each(fn (WalletReservation $r) => $this->wallet->release($r, 'Extension not used'));

            if ($s->fresh()?->status === 'ended') {
                ChatThread::create([
                    'appointment_id' => $a->id, 'patient_id' => $a->patient_id, 'doctor_staff_id' => $a->staff_id, 'kind' => 'followup',
                    'opens_at' => now(), 'closes_at' => now()->addDays(TeleSettings::get('followup_days')),
                ]);
            }
            $count++;
        });

        return $count;
    }

    /**
     * Marks a refund task done once the practice refunded in its gateway dashboard.
     */
    public function completeRefundTask(RefundTask $task, string $reference, ?User $by = null): void
    {
        if ($task->done_at !== null) {
            return;
        }
        $this->refunds->handle($task->payment, $task->amount_cents, $task->reason, $by, trim($reference));
        $task->forceFill(['done_at' => now(), 'reference' => trim($reference)])->save();
        if ($task->appointment_id !== null) {
            Appointment::query()->whereKey($task->appointment_id)->update(['payment_status' => 'refunded']);
        }
    }

    private function applyExtension(Appointment $appointment, TeleSession $session): void
    {
        $ext = TeleSettings::get('extension_minutes');
        DB::transaction(function () use ($appointment, $session, $ext): void {
            $appointment->forceFill([
                'ends_at' => $appointment->ends_at->copy()->addMinutes($ext),
                'duration_minutes' => (int) $appointment->getAttribute('duration_minutes') + $ext,
            ])->save();
            $session->forceFill(['extension_minutes' => $session->getAttribute('extension_minutes') + $ext, 'pending_extension_payment' => null])->save();
        });
        $n = WalletReservation::query()->where('reference', 'like', 'appt-'.$appointment->id.'-ext%')->count() + 1;
        try {
            $this->wallet->reserve(Wallet::for($this->provider()->id), 'appt-'.$appointment->id.'-ext'.$n, $appointment->consult_type->value, $ext);
        } catch (ValidationException) {
            // Calls are never cut: usage is still charged at the end even if the wallet is low.
        }
        activity('telemedicine')->performedOn($appointment)->withProperties(['minutes' => $ext])->log('Online consult extended');
    }

    private function refund(Appointment $appointment, string $reason, ?User $user): void
    {
        $invoice = $this->invoice($appointment);
        foreach ($invoice->payments()->where('status', PaymentStatus::Succeeded->value)->get() as $payment) {
            /** @var Payment $payment */
            $amount = $payment->refundableCents();
            if ($amount <= 0) {
                continue;
            }
            $gateway = Gateway::tryFrom((string) $payment->gateway);
            $automatic = $payment->method === PaymentMethod::PayLink && $gateway !== null && $gateway->supportsApiRefund();
            if ($automatic) {
                $this->refunds->handle($payment, $amount, $reason, $user);
            } else {
                RefundTask::create(['payment_id' => $payment->id, 'appointment_id' => $appointment->id, 'amount_cents' => $amount, 'reason' => $reason, 'due_at' => now()->addWeekdays(2)]);
                $appointment->forceFill(['payment_status' => 'refund_due'])->save();
            }
        }

        $patient = Patient::query()->find($appointment->patient_id);
        if ($patient instanceof Patient && filled($patient->cell)) {
            $this->messages->handle('sms', (string) $patient->cell, $this->provider()->name.': your online consult was cancelled and your payment is being refunded. Card refunds can take a few working days.', null, 'appointment', $appointment->id);
        }
    }

    private function close(Appointment $appointment, string $reason, string $paymentStatus): void
    {
        $appointment->forceFill(['status' => AppointmentStatus::Cancelled, 'cancelled_reason' => $reason, 'payment_status' => $paymentStatus, 'hold_expires_at' => null])->save();
        $session = TeleSession::query()->where('appointment_id', $appointment->id)->first();
        if ($session instanceof TeleSession && $session->ended_at === null) {
            $session->forceFill(['status' => 'cancelled', 'ended_at' => now()])->save();
        }
        WalletReservation::query()->where('reference', 'like', 'appt-'.$appointment->id.'%')->where('status', 'held')->get()
            ->each(fn (WalletReservation $r) => $this->wallet->release($r, 'Online consult cancelled'));
        if ($paymentStatus === 'expired') {
            Invoice::query()->where('visit_id', $appointment->getAttribute('visit_id'))->where('paid_cents', 0)->update(['status' => InvoiceStatus::Void->value]);
        }
    }

    private function invoice(Appointment $appointment): Invoice
    {
        return Invoice::query()->where('visit_id', $appointment->getAttribute('visit_id'))->firstOrFail();
    }

    private function provider(): Provider
    {
        $provider = tenant();
        abort_unless($provider instanceof Provider, 404);

        return $provider;
    }
}
