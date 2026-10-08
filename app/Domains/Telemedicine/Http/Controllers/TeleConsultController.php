<?php

declare(strict_types=1);

namespace App\Domains\Telemedicine\Http\Controllers;

use App\Domains\Billing\Models\Payment;
use App\Domains\Identity\Enums\Permission;
use App\Domains\Patients\Models\Patient;
use App\Domains\Platform\Models\Provider;
use App\Domains\Platform\Models\Subscription;
use App\Domains\Portal\Actions\PortalSignIn;
use App\Domains\Scheduling\Enums\ConsultType;
use App\Domains\Scheduling\Models\Appointment;
use App\Domains\Telemedicine\Actions\OnlineBooking;
use App\Domains\Telemedicine\Events\CallStateChanged;
use App\Domains\Telemedicine\Models\ChatThread;
use App\Domains\Telemedicine\Models\TeleSession;
use App\Domains\Telemedicine\Support\LiveKit;
use App\Domains\Telemedicine\Support\TeleSettings;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Online consults inside Clinic Flow: the doctor's list and call screen, the
 * patient's call screen in the portal, and the owner's add-on switch.
 */
class TeleConsultController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize(Permission::CONSULTS_WRITE);
        $me = $this->user($request)->id;

        return Inertia::render('Telemedicine/Index', [
            'consults' => TeleSession::query()->with('appointment.patient')
                ->whereHas('appointment', fn ($q) => $q->where('staff_id', $me)->where('starts_at', '>=', today()->startOfDay()))
                ->whereNull('ended_at')->get()->sortBy(fn (TeleSession $t) => $t->appointment->starts_at)->values()
                ->map(fn (TeleSession $t) => [
                    'appointmentId' => $t->appointment_id, 'patient' => $t->appointment->patient->fullName(),
                    'type' => $t->appointment->consult_type->value, 'at' => $t->appointment->starts_at->format('D j M H:i'),
                    'patientWaiting' => $t->patient_joined_at !== null, 'status' => $t->status,
                    'paid' => $t->appointment->getAttribute('payment_status') === 'paid', 'opensAt' => $t->appointment->starts_at->toIso8601String(),
                ]),
            'videoReady' => LiveKit::active() !== null,
        ]);
    }

    public function doctorCall(Request $request, Appointment $appointment): Response|RedirectResponse
    {
        $this->authorize(Permission::CONSULTS_WRITE);
        $user = $this->user($request);
        abort_unless($appointment->staff_id === $user->id, 403, 'This consult is booked with another doctor.');

        return $this->call($appointment, "doctor-{$user->id}", $user->name, 'doctor');
    }

    public function patientCall(Request $request, Appointment $appointment, PortalSignIn $signIn): Response|RedirectResponse
    {
        $profiles = $signIn->profiles((string) $request->session()->get('portal_cell'));
        abort_unless($profiles->contains('id', $appointment->patient_id), 403);
        $patient = Patient::query()->findOrFail($appointment->patient_id);

        return $this->call($appointment, "patient-{$patient->id}", $patient->first_names, 'patient');
    }

    public function addon(Request $request): RedirectResponse
    {
        $this->authorize(Permission::PAYMENTS_CONFIGURE);
        $data = $request->validate(['enabled' => ['required', 'boolean']]);
        $provider = tenant();
        abort_unless($provider instanceof Provider, 404);
        $subscription = Subscription::query()->with('package')->where('tenant_id', $provider->id)->latest('id')->firstOrFail();
        if (! in_array('telemedicine', (array) $subscription->package->addons, true)) {
            return back()->withErrors(['enabled' => 'Your package does not offer telemedicine.']);
        }

        $addons = array_values(array_diff((array) ($subscription->getAttribute('addons') ?? []), ['telemedicine']));
        if ($data['enabled']) {
            $addons[] = 'telemedicine';
        }
        $subscription->forceFill(['addons' => $addons])->save();
        activity('platform')->performedOn($provider)->withProperties(['telemedicine' => (bool) $data['enabled']])->log('Telemedicine add-on changed');

        return back()->with('success', $data['enabled'] ? 'Telemedicine is on. The add-on fee is added to your next subscription invoice.' : 'Telemedicine is off.');
    }

    public function extend(Request $request, Appointment $appointment, OnlineBooking $booking): RedirectResponse
    {
        $this->authorize(Permission::CONSULTS_WRITE);
        abort_unless($appointment->staff_id === $this->user($request)->id, 403);
        $booking->extend($appointment);

        event(new CallStateChanged((string) tenant('id'), (string) $appointment->id));

        return back()->with('success', 'Extension sent to the patient for payment. Time is added as soon as they pay.');
    }

    /**
     * Live state for the call screen: end time (after paid extensions) and any extension awaiting payment.
     */
    public function state(Appointment $appointment): JsonResponse
    {
        $session = TeleSession::query()->where('appointment_id', $appointment->id)->firstOrFail();
        $pending = $session->getAttribute('pending_extension_payment');
        $token = $pending === null ? null : Payment::query()->whereKey($pending)->value('checkout_token');

        $scribe = DB::table('scribe_sessions')->where('appointment_id', $appointment->id)->latest('created_at')->first(['id', 'status']);

        return response()->json([
            'scribe' => $scribe === null ? null : ['id' => $scribe->id, 'status' => $scribe->status],
            'endsAt' => $appointment->fresh()?->ends_at->toIso8601String(),
            'extensionPayUrl' => $token === null ? null : url('/pay/'.$token),
            'ended' => $session->ended_at !== null,
        ]);
    }

    private function call(Appointment $appointment, string $identity, string $name, string $role): Response|RedirectResponse
    {
        $session = TeleSession::query()->where('appointment_id', $appointment->id)->firstOrFail();
        if ($session->ended_at !== null) {
            return back()->with('error', 'This consult has ended.');
        }
        abort_unless($appointment->getAttribute('payment_status') === 'paid', 403, 'This consult is not paid yet.');
        if ($appointment->consult_type === ConsultType::Chat) {
            $thread = ChatThread::query()->where('appointment_id', $appointment->id)->where('kind', 'consult')->firstOrFail();

            return redirect($role === 'doctor' ? "/chats/{$thread->id}" : "/my/chats/{$thread->id}");
        }

        $grace = TeleSettings::get('grace_minutes');
        if ($appointment->ends_at->copy()->addMinutes($grace)->isPast()) {
            return back()->with('error', 'The booked time for this consult is over.');
        }
        $open = ! $appointment->starts_at->isFuture();
        $livekit = LiveKit::active();
        abort_if($livekit === null, 503, 'Video consults are not available yet.');

        if ($open && $role === 'doctor' && $session->doctor_joined_at === null) {
            $livekit->createRoom($session->room_name);
        }

        return Inertia::render('Telemedicine/Call', [
            'appointmentId' => $appointment->id,
            'serverUrl' => $livekit->url(),
            // No join pass before the start time: the waiting screen only tests the camera and microphone.
            'token' => $open ? $livekit->joinToken($session->room_name, $identity, $name, $appointment->duration_minutes + 120) : null,
            'startsAt' => $appointment->starts_at->toIso8601String(),
            'endsAt' => $appointment->ends_at->toIso8601String(),
            'graceMinutes' => $grace,
            'type' => $appointment->consult_type->value,
            'role' => $role,
            'other' => $role === 'doctor' ? $appointment->patient->fullName() : 'your doctor',
            'leaveUrl' => $role === 'doctor' ? '/telemedicine' : '/my/online',
            'stateUrl' => $role === 'doctor' ? "/telemedicine/{$appointment->id}/state" : "/my/online/{$appointment->id}/state",
        ]);
    }

    private function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
