<?php

declare(strict_types=1);

namespace App\Domains\Telemedicine\Http\Controllers;

use App\Domains\Identity\Enums\Permission;
use App\Domains\Patients\Models\Patient;
use App\Domains\Platform\Models\Provider;
use App\Domains\Platform\Models\Subscription;
use App\Domains\Portal\Actions\PortalSignIn;
use App\Domains\Scheduling\Models\Appointment;
use App\Domains\Telemedicine\Models\TeleSession;
use App\Domains\Telemedicine\Support\LiveKit;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
                ->whereHas('appointment', fn ($q) => $q->where('staff_id', $me)->whereDate('starts_at', '>=', today()))
                ->whereNull('ended_at')->get()->sortBy(fn (TeleSession $t) => $t->appointment->starts_at)->values()
                ->map(fn (TeleSession $t) => [
                    'appointmentId' => $t->appointment_id, 'patient' => $t->appointment->patient->fullName(),
                    'type' => $t->appointment->consult_type->value, 'at' => $t->appointment->starts_at->format('D j M H:i'),
                    'patientWaiting' => $t->patient_joined_at !== null, 'status' => $t->status,
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

    private function call(Appointment $appointment, string $identity, string $name, string $role): Response|RedirectResponse
    {
        $session = TeleSession::query()->where('appointment_id', $appointment->id)->firstOrFail();
        if ($session->ended_at !== null) {
            return back()->with('error', 'This consult has ended.');
        }
        $livekit = LiveKit::active();
        abort_if($livekit === null, 503, 'Video consults are not available yet.');
        abort_if($appointment->starts_at->subMinutes(15)->isFuture(), 403, 'You can join from 15 minutes before the consult.');

        if ($role === 'doctor' && $session->doctor_joined_at === null) {
            $livekit->createRoom($session->room_name);
        }

        return Inertia::render('Telemedicine/Call', [
            'serverUrl' => $livekit->url(),
            'token' => $livekit->joinToken($session->room_name, $identity, $name, 180),
            'type' => $appointment->consult_type->value,
            'role' => $role,
            'other' => $role === 'doctor' ? $appointment->patient->fullName() : 'your doctor',
            'leaveUrl' => $role === 'doctor' ? '/telemedicine' : '/my',
        ]);
    }

    private function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
