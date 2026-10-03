<?php

declare(strict_types=1);

namespace App\Domains\Telemedicine\Http\Controllers;

use App\Domains\Billing\Models\Invoice;
use App\Domains\Billing\Models\Payment;
use App\Domains\Identity\Enums\Permission;
use App\Domains\Identity\Models\Staff;
use App\Domains\Patients\Models\Patient;
use App\Domains\Platform\Models\Provider;
use App\Domains\Platform\Models\Setting;
use App\Domains\Portal\Actions\PortalSignIn;
use App\Domains\Scheduling\Enums\ConsultType;
use App\Domains\Scheduling\Models\Appointment;
use App\Domains\Telemedicine\Actions\OnlineBooking;
use App\Domains\Telemedicine\Models\ChatThread;
use App\Domains\Telemedicine\Models\RefundTask;
use App\Domains\Telemedicine\Models\TeleAvailability;
use App\Domains\Telemedicine\Models\TeleException;
use App\Domains\Telemedicine\Models\TelePrice;
use App\Domains\Telemedicine\Support\OnlineSlots;
use App\Domains\Telemedicine\Support\Telemedicine;
use App\Domains\Telemedicine\Support\TeleSettings;
use App\Http\Controllers\Controller;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Online consult set-up (practice), booking (patient portal and front desk),
 * cancellation, refund tasks and the patient's consult list.
 */
class OnlineConsultController extends Controller
{
    // ---------------- practice settings ----------------

    public function settings(): Response
    {
        $this->authorize(Permission::SETTINGS_MANAGE);

        return Inertia::render('Settings/Telemedicine', [
            'doctors' => $this->doctors()->map(fn (Staff $d) => [
                'id' => $d->id, 'name' => $d->name,
                'availability' => TeleAvailability::query()->where('staff_id', $d->id)->orderBy('weekday')->orderBy('start_time')->get(['mode', 'weekday', 'start_time', 'end_time']),
                'exceptions' => TeleException::query()->where('staff_id', $d->id)->whereDate('date', '>=', today())->orderBy('date')->get(['id', 'date', 'type', 'mode', 'start_time', 'end_time', 'note']),
            ])->values(),
            'prices' => TelePrice::query()->orderBy('mode')->orderBy('duration_minutes')->get(['staff_id', 'mode', 'duration_minutes', 'price_cents']),
            'rules' => TeleSettings::all(),
            'noShowMinutes' => TeleSettings::DOCTOR_NO_SHOW_MINUTES,
            'enabled' => Telemedicine::enabledFor($this->provider()->id),
            'refundTasks' => RefundTask::query()->with('payment')->whereNull('done_at')->orderBy('due_at')->get()->map(fn (RefundTask $t) => [
                'id' => $t->id, 'amount' => $t->amount_cents / 100, 'reason' => $t->reason, 'due' => $t->due_at->format('j M'), 'overdue' => $t->due_at->isPast(),
                'gateway' => $t->payment->gateway ?? $t->payment->method->label(),
            ])->values(),
        ]);
    }

    public function saveAvailability(Request $request, Staff $staff): RedirectResponse
    {
        $this->authorize(Permission::SETTINGS_MANAGE);
        $data = $request->validate([
            'rows' => ['present', 'array', 'max:50'], 'rows.*.mode' => ['required', Rule::in(['video', 'audio', 'chat', 'all'])],
            'rows.*.weekday' => ['required', 'integer', 'between:0,6'], 'rows.*.start_time' => ['required', 'date_format:H:i'], 'rows.*.end_time' => ['required', 'date_format:H:i', 'after:rows.*.start_time'],
        ]);
        TeleAvailability::query()->where('staff_id', $staff->id)->delete();
        foreach ($data['rows'] as $row) {
            TeleAvailability::create(['staff_id' => $staff->id, ...$row]);
        }

        return back()->with('success', "Online hours saved for {$staff->name}.");
    }

    public function addException(Request $request, Staff $staff): RedirectResponse
    {
        $this->authorize(Permission::SETTINGS_MANAGE);
        $data = $request->validate([
            'date' => ['required', 'date', 'after_or_equal:today'], 'type' => ['required', Rule::in(['off', 'extra'])], 'mode' => ['nullable', Rule::in(['video', 'audio', 'chat', 'all'])],
            'start_time' => ['nullable', 'required_if:type,extra', 'date_format:H:i'], 'end_time' => ['nullable', 'required_with:start_time', 'date_format:H:i'], 'note' => ['nullable', 'string', 'max:120'],
        ]);
        TeleException::create(['staff_id' => $staff->id, ...$data]);

        return back()->with('success', 'Date exception added.');
    }

    public function deleteException(TeleException $exception): RedirectResponse
    {
        $this->authorize(Permission::SETTINGS_MANAGE);
        $exception->delete();

        return back()->with('success', 'Date exception removed.');
    }

    public function savePrices(Request $request): RedirectResponse
    {
        $this->authorize(Permission::SETTINGS_MANAGE);
        $data = $request->validate([
            'rows' => ['present', 'array', 'max:60'], 'rows.*.staff_id' => ['nullable', 'integer'], 'rows.*.mode' => ['required', Rule::in(['video', 'audio', 'chat'])],
            'rows.*.duration_minutes' => ['required', 'integer', Rule::in([15, 30, 45, 60])], 'rows.*.price' => ['required', 'numeric', 'min:0'],
        ]);
        $hasMinimum = false;
        foreach ((array) $data['rows'] as $row) {
            $hasMinimum = $hasMinimum || (($row['staff_id'] ?? null) === null && (int) $row['duration_minutes'] === 15);
        }
        if (! $hasMinimum) {
            return back()->withErrors(['rows' => 'Set a practice price for 15 minutes (the minimum) for every mode you offer.']);
        }
        TelePrice::query()->delete();
        foreach ($data['rows'] as $r) {
            TelePrice::create(['staff_id' => $r['staff_id'], 'mode' => $r['mode'], 'duration_minutes' => (int) $r['duration_minutes'], 'price_cents' => (int) round(((float) $r['price']) * 100)]);
        }

        return back()->with('success', 'Online consult prices saved.');
    }

    public function saveRules(Request $request): RedirectResponse
    {
        $this->authorize(Permission::SETTINGS_MANAGE);
        $data = $request->validate([
            'hold_minutes' => ['required', 'integer', 'between:5,30'], 'grace_minutes' => ['required', 'integer', 'between:0,10'],
            'cancel_cutoff_minutes' => ['required', 'integer', 'between:0,2880'], 'followup_days' => ['required', 'integer', 'between:0,14'],
            'extension_minutes' => ['required', 'integer', Rule::in([15, 30])], 'buffer_minutes' => ['required', 'integer', 'between:0,30'],
        ]);
        foreach ($data as $key => $value) {
            Setting::put('telemedicine', $key, (int) $value);
        }

        return back()->with('success', 'Online consult rules saved.');
    }

    public function completeRefund(Request $request, RefundTask $task, OnlineBooking $booking): RedirectResponse
    {
        $this->authorize(Permission::BILLING_REFUND);
        $data = $request->validate(['reference' => ['required', 'string', 'max:80']]);
        $booking->completeRefundTask($task, $data['reference'], $this->user($request));

        return back()->with('success', 'Refund recorded.');
    }

    // ---------------- booking ----------------

    public function slots(Request $request): JsonResponse
    {
        $data = $request->validate(['staff_id' => ['required', 'integer'], 'mode' => ['required', Rule::in(['video', 'audio', 'chat'])], 'duration' => ['required', 'integer'], 'date' => ['required', 'date']]);

        return response()->json([
            'slots' => OnlineSlots::for((int) $data['staff_id'], $data['mode'], (int) $data['duration'], CarbonImmutable::parse($data['date'])),
            'price' => (OnlineSlots::priceCents((int) $data['staff_id'], $data['mode'], (int) $data['duration']) ?? 0) / 100,
        ]);
    }

    public function staffBook(Request $request, OnlineBooking $booking): RedirectResponse
    {
        $this->authorize(Permission::APPOINTMENTS_BOOK);
        $data = $this->bookingData($request) + $request->validate(['patient_id' => ['required', 'string']]);
        $appointment = $booking->book(Patient::query()->findOrFail((string) $data['patient_id']), Staff::query()->findOrFail((int) $data['staff_id']), ConsultType::from($data['mode']),
            (int) $data['duration'], CarbonImmutable::parse($data['date'].' '.$data['time']), $this->user($request));
        $invoiceId = Invoice::query()->where('visit_id', $appointment->getAttribute('visit_id'))->value('id');

        return redirect("/invoices/{$invoiceId}")->with('success', 'Online consult held for '.TeleSettings::get('hold_minutes').' minutes. Take payment or send a pay link to confirm it.');
    }

    // ---------------- patient portal ----------------

    public function portal(Request $request, PortalSignIn $signIn): Response
    {
        $patientIds = $signIn->profiles($this->cell($request))->pluck('id');
        $offered = TelePrice::query()->get(['staff_id', 'mode', 'duration_minutes', 'price_cents']);

        return Inertia::render('Portal/Online', [
            'providerName' => $this->provider()->name,
            'enabled' => Telemedicine::enabledFor($this->provider()->id),
            'patients' => $signIn->profiles($this->cell($request))->map(fn (Patient $p) => ['id' => $p->id, 'name' => $p->fullName()])->values(),
            'doctors' => $this->doctors()->filter(fn (Staff $d) => TeleAvailability::query()->where('staff_id', $d->id)->exists())->map(fn (Staff $d) => [
                'id' => $d->id, 'name' => $d->name,
                'modes' => array_values(array_unique(TeleAvailability::query()->where('staff_id', $d->id)->pluck('mode')->flatMap(fn ($m) => $m === 'all' ? ['video', 'audio', 'chat'] : [$m])->all())),
            ])->values(),
            'durations' => $offered->groupBy('mode')->map(fn (Collection $rows) => $rows->pluck('duration_minutes')->unique()->sort()->values()),
            'consults' => Appointment::query()->whereIn('patient_id', $patientIds)->whereNotNull('payment_status')->where('starts_at', '>=', now()->subDays(30))->orderByDesc('starts_at')->get()
                ->map(fn (Appointment $a) => [
                    'id' => $a->id, 'mode' => $a->consult_type->value, 'at' => $a->starts_at->toIso8601String(), 'duration' => $a->getAttribute('duration_minutes'),
                    'status' => $a->status->value, 'payment' => $a->getAttribute('payment_status'), 'doctor' => Staff::query()->whereKey($a->staff_id)->value('name'),
                    'payUrl' => $this->pendingPayUrl($a),
                ])->values(),
            'chats' => ChatThread::query()->whereIn('patient_id', $patientIds)->where('closes_at', '>', now())->get(['id', 'kind', 'opens_at', 'closes_at']),
            'rules' => ['cutoffMinutes' => TeleSettings::get('cancel_cutoff_minutes'), 'followupDays' => TeleSettings::get('followup_days')],
        ]);
    }

    public function patientBook(Request $request, PortalSignIn $signIn, OnlineBooking $booking): HttpResponse
    {
        $data = $this->bookingData($request) + $request->validate(['patient_id' => ['required', 'string']]);
        abort_unless($signIn->profiles($this->cell($request))->contains('id', $data['patient_id']), 403);
        $appointment = $booking->book(Patient::query()->findOrFail((string) $data['patient_id']), Staff::query()->findOrFail((int) $data['staff_id']), ConsultType::from($data['mode']),
            (int) $data['duration'], CarbonImmutable::parse($data['date'].' '.$data['time']));
        $payment = $booking->payLink($appointment);

        return Inertia::location(url('/pay/'.$payment->checkout_token));
    }

    public function patientCancel(Request $request, Appointment $appointment, PortalSignIn $signIn, OnlineBooking $booking): RedirectResponse
    {
        abort_unless($signIn->profiles($this->cell($request))->contains('id', $appointment->patient_id), 403);
        $booking->cancel($appointment, 'patient', $request->string('reason')->toString() ?: 'Cancelled by the patient');

        return back()->with('success', 'Your consult is cancelled.');
    }

    public function patientState(Request $request, Appointment $appointment, PortalSignIn $signIn, TeleConsultController $calls): JsonResponse
    {
        abort_unless($signIn->profiles($this->cell($request))->contains('id', $appointment->patient_id), 403);

        return $calls->state($appointment);
    }

    /**
     * @return array{staff_id: int, mode: string, duration: int, date: string, time: string}
     */
    private function bookingData(Request $request): array
    {
        /** @var array{staff_id: int, mode: string, duration: int, date: string, time: string} $data */
        $data = $request->validate([
            'staff_id' => ['required', 'integer'], 'mode' => ['required', Rule::in(['video', 'audio', 'chat'])],
            'duration' => ['required', 'integer'], 'date' => ['required', 'date'], 'time' => ['required', 'date_format:H:i'],
        ]);

        return $data;
    }

    private function pendingPayUrl(Appointment $a): ?string
    {
        if ($a->getAttribute('payment_status') !== 'pending' || $a->status->value !== 'booked') {
            return null;
        }
        $invoiceId = Invoice::query()->where('visit_id', $a->getAttribute('visit_id'))->value('id');
        $token = Payment::query()->where('invoice_id', $invoiceId)->where('status', 'pending')->latest('id')->value('checkout_token');

        return $token === null ? null : url('/pay/'.$token);
    }

    /**
     * @return Collection<int, Staff>
     */
    private function doctors(): Collection
    {
        return Staff::query()->orderBy('name')->get()->filter(fn (Staff $s) => $s->hasAnyRole(['doctor', 'locum_doctor']))->values();
    }

    private function cell(Request $request): string
    {
        return (string) $request->session()->get('portal_cell');
    }

    private function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }

    private function provider(): Provider
    {
        $provider = tenant();
        abort_unless($provider instanceof Provider, 404);

        return $provider;
    }
}
