<?php

declare(strict_types=1);

namespace App\Domains\Portal\Http\Controllers;

use App\Domains\Billing\Actions\RecordPayment;
use App\Domains\Billing\Enums\PaymentMethod;
use App\Domains\Billing\Models\Invoice;
use App\Domains\Identity\Models\Staff;
use App\Domains\Lab\Models\LabOrder;
use App\Domains\Lab\Models\LabResult;
use App\Domains\Patients\Models\Patient;
use App\Domains\Platform\Models\Provider;
use App\Domains\Portal\Actions\PortalSignIn;
use App\Domains\Prescribing\Models\Prescription;
use App\Domains\Scheduling\Actions\AvailableSlots;
use App\Domains\Scheduling\Actions\BookAppointment;
use App\Domains\Scheduling\Actions\CancelAppointment;
use App\Domains\Scheduling\Enums\AppointmentStatus;
use App\Domains\Scheduling\Models\Appointment;
use App\Domains\Visits\Enums\VisitStage;
use App\Domains\Visits\Models\Visit;
use App\Http\Controllers\Controller;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Patient web portal on the provider's address: today's visit and queue
 * ticket, bookings, released results, invoices and scripts — for the patient
 * and the family members they manage.
 */
class PortalController extends Controller
{
    public function login(): Response
    {
        return Inertia::render('Portal/Login', ['provider' => $this->providerName()]);
    }

    public function start(Request $request, PortalSignIn $signIn): RedirectResponse
    {
        $data = $request->validate(['cell' => ['required', 'string', 'max:15']]);
        $request->session()->put('portal_challenge', $signIn->start($data['cell']));

        return redirect()->route('portal.verify');
    }

    public function verifyForm(): Response
    {
        return Inertia::render('Portal/Verify', ['provider' => $this->providerName()]);
    }

    public function verify(Request $request, PortalSignIn $signIn): RedirectResponse
    {
        $data = $request->validate(['code' => ['required', 'digits:6']]);
        $challenge = $request->session()->get('portal_challenge');
        $cell = $signIn->verify(is_string($challenge) ? $challenge : null, $data['code']);

        $request->session()->forget('portal_challenge');
        $request->session()->regenerate();
        $request->session()->put('portal_cell', $cell);

        return redirect()->route('portal.home');
    }

    public function logout(Request $request): RedirectResponse
    {
        $request->session()->forget(['portal_cell', 'portal_patient']);

        return redirect()->route('portal.login');
    }

    public function home(Request $request, PortalSignIn $signIn, AvailableSlots $slots): Response
    {
        $profiles = $signIn->profiles($this->cell($request));
        $patient = $this->current($request, $profiles->all());
        $day = CarbonImmutable::parse($request->string('date')->toString() ?: 'tomorrow');

        $visit = Visit::query()->where('patient_id', $patient->id)->onDate('visit_date', today())->latest()->first();
        $ahead = $visit !== null && $visit->stage->isWaiting()
            ? Visit::query()->onDate('visit_date', today())->where('stage', $visit->stage->value)->whereNull('doctor_id')->where('stage_changed_at', '<', $visit->stage_changed_at)->count()
            : null;

        return Inertia::render('Portal/Home', [
            'provider' => $this->providerName(),
            'profiles' => $profiles->map(fn (Patient $p) => ['id' => $p->id, 'name' => $p->fullName(), 'age' => $p->ageInYears(), 'current' => $p->id === $patient->id])->values(),
            // Live updates for today's visit (this patient's own channel).
            'patientChannel' => $visit === null ? null : 'patient.'.$visit->patient_id,
            'visit' => $visit === null ? null : ['ticket' => $visit->ticket, 'stage' => $visit->stage->label(), 'ahead' => $ahead, 'collectionCode' => $visit->stage === VisitStage::Dispatch ? $visit->collection_code : null],
            'appointments' => Appointment::query()->with('staff')->where('patient_id', $patient->id)->where('status', AppointmentStatus::Booked->value)
                ->where('starts_at', '>=', now())->orderBy('starts_at')->get()
                ->map(fn (Appointment $a) => ['id' => $a->id, 'when' => $a->starts_at->format('D j M, H:i'), 'doctor' => $a->staff->name])->values(),
            'bookingDate' => $day->toDateString(),
            'slots' => Staff::role(['doctor', 'locum_doctor'])->orderBy('name')->get()->map(fn (Staff $d) => [
                'doctorId' => $d->id, 'doctor' => $d->name,
                'times' => array_map(fn (array $s) => $s['starts_at']->format('H:i'), $slots->handle($d->id, $day)),
            ])->filter(fn (array $d) => $d['times'] !== [])->values(),
            'results' => LabOrder::query()->with('results')->where('patient_id', $patient->id)->where('status', 'released')->latest('released_at')->get()
                ->map(fn (LabOrder $o) => [
                    'id' => $o->id, 'date' => $o->released_at?->format('j M Y'), 'comment' => $o->doctor_comment,
                    'results' => $o->results->map(fn (LabResult $r) => $r->only(['name', 'value', 'unit', 'reference', 'flag']))->values(),
                ])->values(),
            'invoices' => Invoice::query()->where('patient_id', $patient->id)->latest()->limit(20)->get()
                ->map(fn (Invoice $i) => ['id' => $i->id, 'number' => $i->number, 'date' => $i->created_at?->format('j M Y'), 'total' => $i->total_cents / 100, 'balance' => $i->balanceCents() / 100])->values(),
            'scripts' => Prescription::query()->where('patient_id', $patient->id)->where('status', Prescription::SIGNED)->latest('signed_at')->limit(10)->get()
                ->map(fn (Prescription $p) => ['id' => $p->id, 'date' => $p->signed_at?->format('j M Y'), 'version' => $p->version])->values(),
        ]);
    }

    public function switchProfile(Request $request, string $patient, PortalSignIn $signIn): RedirectResponse
    {
        abort_unless($signIn->profiles($this->cell($request))->contains('id', $patient), 403);
        $request->session()->put('portal_patient', $patient);

        return redirect()->route('portal.home');
    }

    public function book(Request $request, PortalSignIn $signIn, BookAppointment $action): RedirectResponse
    {
        $patient = $this->current($request, $signIn->profiles($this->cell($request))->all());
        $data = $request->validate(['staff_id' => ['required', 'integer'], 'starts_at' => ['required', 'date'], 'reason' => ['nullable', 'string', 'max:255']]);
        $provider = tenant();
        abort_unless($provider instanceof Provider && $provider->status->isLive(), 403, 'Online booking opens once the practice is verified.');

        $appointment = $action->handle($patient, Staff::query()->findOrFail((int) $data['staff_id']), CarbonImmutable::parse($data['starts_at']), reason: $data['reason'] ?? null);

        return back()->with('success', 'Booked for '.$appointment->starts_at->format('D j M, H:i').'.');
    }

    public function cancel(Request $request, Appointment $appointment, PortalSignIn $signIn, CancelAppointment $action): RedirectResponse
    {
        abort_unless($signIn->profiles($this->cell($request))->contains('id', $appointment->patient_id), 403);
        $action->handle($appointment, 'Cancelled by patient online');

        return back()->with('success', 'Appointment cancelled.');
    }

    public function pay(Request $request, Invoice $invoice, PortalSignIn $signIn, RecordPayment $payments): HttpResponse
    {
        abort_unless($signIn->profiles($this->cell($request))->contains('id', $invoice->patient_id), 403);
        abort_if($invoice->balanceCents() <= 0, 422, 'Nothing is owing on this invoice.');
        $payment = $payments->handle($invoice, PaymentMethod::PayLink, $invoice->balanceCents());
        $token = $payment->getAttribute('checkout_token');

        return Inertia::location(route('paylink.show', ['token' => is_string($token) ? $token : '']));
    }

    /**
     * @param  array<int, Patient>  $profiles
     */
    private function current(Request $request, array $profiles): Patient
    {
        abort_if($profiles === [], 403, 'No records are linked to this cell number.');
        $chosen = $request->session()->get('portal_patient');
        foreach ($profiles as $p) {
            if ($p->id === $chosen) {
                return $p;
            }
        }

        return reset($profiles);
    }

    private function cell(Request $request): string
    {
        return (string) $request->session()->get('portal_cell');
    }

    private function providerName(): string
    {
        $provider = tenant();

        return $provider instanceof Provider ? $provider->name : 'Clinic Flow';
    }
}
