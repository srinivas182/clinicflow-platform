<?php

declare(strict_types=1);

namespace App\Domains\Scheduling\Http\Controllers;

use App\Domains\Identity\Enums\Permission;
use App\Domains\Identity\Models\Staff;
use App\Domains\Patients\Models\Patient;
use App\Domains\Scheduling\Actions\AvailableSlots;
use App\Domains\Scheduling\Actions\BookAppointment;
use App\Domains\Scheduling\Actions\CancelAppointment;
use App\Domains\Scheduling\Enums\ConsultType;
use App\Domains\Scheduling\Models\Appointment;
use App\Http\Controllers\Controller;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Day view: each doctor's appointments and free slots; book and cancel.
 */
class AppointmentController extends Controller
{
    public function index(Request $request, AvailableSlots $slots): Response
    {
        $this->authorize(Permission::APPOINTMENTS_VIEW);

        $day = CarbonImmutable::parse($request->string('date')->toString() ?: 'today');
        $doctors = Staff::role(['doctor', 'locum_doctor'])->orderBy('name')->get();

        return Inertia::render('Appointments/Index', [
            'date' => $day->toDateString(),
            'doctors' => $doctors->map(fn (Staff $doctor): array => [
                'id' => $doctor->id,
                'name' => $doctor->name,
                'appointments' => Appointment::query()->with('patient')
                    ->where('staff_id', $doctor->id)
                    ->whereDate('starts_at', $day)
                    ->orderBy('starts_at')->get()
                    ->map(fn (Appointment $a): array => [
                        'id' => $a->id,
                        'patient' => $a->patient->fullName(),
                        'startsAt' => $a->starts_at->format('H:i'),
                        'status' => $a->status->value,
                        'reason' => $a->reason,
                    ])->values(),
                'freeSlots' => array_map(fn (array $s): string => $s['starts_at']->format('H:i'), $slots->handle($doctor->id, $day)),
            ])->values(),
            'canBook' => $this->user($request)->can(Permission::APPOINTMENTS_BOOK),
        ]);
    }

    public function store(Request $request, BookAppointment $action): RedirectResponse
    {
        $this->authorize(Permission::APPOINTMENTS_BOOK);

        $data = $request->validate([
            'patient_id' => ['required', 'string'],
            'staff_id' => ['required', 'integer'],
            'starts_at' => ['required', 'date'],
            'consult_type' => ['required', Rule::enum(ConsultType::class)],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        $appointment = $action->handle(
            Patient::query()->findOrFail((string) $data['patient_id']),
            Staff::query()->findOrFail((int) $data['staff_id']),
            CarbonImmutable::parse($data['starts_at']),
            ConsultType::from($data['consult_type']),
            $data['reason'] ?? null,
            $this->user($request),
        );

        return back()->with('success', 'Booked for '.$appointment->starts_at->format('D j M, H:i').'.');
    }

    public function cancel(Request $request, Appointment $appointment, CancelAppointment $action): RedirectResponse
    {
        $this->authorize(Permission::APPOINTMENTS_BOOK);

        $action->handle($appointment, $request->string('reason')->toString(), $this->user($request));

        return back()->with('success', 'Appointment cancelled.');
    }

    private function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
