<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Http\Controllers;

use App\Domains\Clinical\Actions\AcceptRedAlert;
use App\Domains\Clinical\Actions\CallNextPatient;
use App\Domains\Clinical\Enums\TriageColour;
use App\Domains\Identity\Models\Staff;
use App\Domains\Visits\Enums\VisitStage;
use App\Domains\Visits\Models\Visit;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The doctor's queue: own bookings, patients asking for them, the shared pool,
 * red alerts, and "Call next".
 */
class DoctorQueueController extends Controller
{
    public function index(Request $request, CallNextPatient $calls): Response
    {
        $doctor = $this->doctor($request);
        $waiting = Visit::query()->with('patient')->onDate('visit_date', today())
            ->where('stage', VisitStage::Doctor->value)->whereNull('called_at')->get();

        $row = fn (Visit $v) => [
            'id' => $v->id, 'ticket' => $v->ticket, 'patient' => $v->patient->fullName(), 'colour' => $v->triage_colour,
            'minutes' => $v->minutesInStage(), 'booked' => $v->appointment_id !== null,
        ];
        $current = $calls->current($doctor);

        return Inertia::render('DoctorQueue/Index', [
            'doctor' => $doctor->name,
            'current' => $current === null ? null : ['id' => $current->id, 'ticket' => $current->ticket, 'patient' => $current->patient->fullName(), 'colour' => $current->triage_colour],
            'mine' => $waiting->filter(fn (Visit $v) => $v->preferred_staff_id === $doctor->id)->map($row)->values(),
            'pool' => $waiting->filter(fn (Visit $v) => $v->preferred_staff_id === null)
                ->sortBy(fn (Visit $v) => [TriageColour::tryFrom((string) $v->triage_colour)?->priority() ?? 9, $v->created_at->getTimestamp()])->map($row)->values(),
            'redAlerts' => Visit::query()->with('patient')->onDate('visit_date', today())->where('stage', VisitStage::Doctor->value)
                ->where('triage_colour', 'red')->whereNull('alert_accepted_at')->get()->map($row)->values(),
        ]);
    }

    public function callNext(Request $request, CallNextPatient $action): RedirectResponse
    {
        $visit = $action->handle($this->doctor($request));

        return back()->with('success', $visit === null ? 'Nobody is waiting for you.' : "Calling {$visit->ticket} — {$visit->patient->fullName()}.");
    }

    public function accept(Request $request, Visit $visit, AcceptRedAlert $action): RedirectResponse
    {
        $action->handle($visit, $this->doctor($request));

        return back()->with('success', "You're taking {$visit->ticket} (red).");
    }

    private function doctor(Request $request): Staff
    {
        $user = $request->user();
        $staff = $user === null ? null : Staff::query()->find($user->getAuthIdentifier());

        abort_unless($staff instanceof Staff && $staff->hasAnyRole(['doctor', 'locum_doctor']), 403, 'Only doctors have a doctor queue.');

        return $staff;
    }
}
