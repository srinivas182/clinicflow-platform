<?php

declare(strict_types=1);

namespace App\Domains\Visits\Http\Controllers;

use App\Domains\Billing\Models\Invoice;
use App\Domains\Branches\Support\BranchContext;
use App\Domains\Identity\Enums\Permission;
use App\Domains\Identity\Models\Staff;
use App\Domains\Patients\Actions\SearchPatients;
use App\Domains\Patients\Models\Patient;
use App\Domains\Scheduling\Enums\AppointmentStatus;
use App\Domains\Scheduling\Models\Appointment;
use App\Domains\Visits\Actions\CheckInPatient;
use App\Domains\Visits\Actions\DischargeVisit;
use App\Domains\Visits\Actions\RemoveFromQueue;
use App\Domains\Visits\Actions\TransitionVisit;
use App\Domains\Visits\Enums\LeftReason;
use App\Domains\Visits\Enums\PayerType;
use App\Domains\Visits\Enums\VisitStage;
use App\Domains\Visits\Models\Visit;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Reception's home: today's queue, search-first check-in, stage moves and removal.
 */
class FrontDeskController extends Controller
{
    public function index(Request $request, SearchPatients $search): Response
    {
        $this->authorize(Permission::VISITS_MANAGE);

        $visits = Visit::query()->with(['patient', 'invoice'])->whereDate('visit_date', today())->when(BranchContext::filterId(), fn ($q, int $b) => $q->where('branch_id', $b))->orderBy('created_at')->get();
        $term = $request->string('search')->toString();

        return Inertia::render('FrontDesk/Index', [
            'visits' => $visits->map(fn (Visit $v): array => [
                'id' => $v->id,
                'ticket' => $v->ticket,
                'patient' => $v->patient->fullName(),
                'stage' => $v->stage->value,
                'stageLabel' => $v->stage->label(),
                'payer' => $v->payer_type->value,
                'minutes' => $v->minutesInStage(),
                'invoiceId' => $v->invoice?->id,
                'balance' => $v->invoice !== null ? $v->invoice->balanceCents() / 100 : 0,
                'next' => array_map(fn (VisitStage $s) => ['value' => $s->value, 'label' => $s->label()], array_values(array_filter($v->stage->allowedNext(), fn (VisitStage $s) => $s !== VisitStage::Left))),
                'canRemove' => $v->stage->isWaiting(),
            ])->values(),
            'counts' => [
                'today' => $visits->count(),
                'waiting' => $visits->filter(fn (Visit $v) => $v->stage->isWaiting())->count(),
                'takings' => (int) Invoice::query()->whereDate('created_at', today())->sum('paid_cents') / 100,
            ],
            'search' => $term,
            'results' => $term === '' ? [] : $search->handle($term, 8)->map(fn (Patient $p): array => [
                'id' => $p->id,
                'name' => $p->fullName(),
                'idNumber' => $p->maskedIdNumber(),
                'medicalAid' => $p->medical_aid_scheme,
                'appointmentId' => Appointment::query()->where('patient_id', $p->id)->where('status', AppointmentStatus::Booked->value)->whereDate('starts_at', today())->value('id'),
            ])->values(),
            'doctors' => Staff::role(['doctor', 'locum_doctor'])->orderBy('name')->get(['id', 'name']),
            'leftReasons' => array_map(fn (LeftReason $r) => $r->value, LeftReason::cases()),
        ]);
    }

    public function checkIn(Request $request, CheckInPatient $action): RedirectResponse
    {
        $this->authorize(Permission::VISITS_MANAGE);

        $data = $request->validate([
            'patient_id' => ['required', 'string'],
            'payer_type' => ['required', Rule::enum(PayerType::class)],
            'appointment_id' => ['nullable', 'string'],
            'preferred_staff_id' => ['nullable', 'integer'],
        ]);

        $visit = $action->handle(
            Patient::query()->findOrFail((string) $data['patient_id']),
            PayerType::from($data['payer_type']),
            isset($data['appointment_id']) ? Appointment::query()->findOrFail((string) $data['appointment_id']) : null,
            isset($data['preferred_staff_id']) ? (int) $data['preferred_staff_id'] : null,
            'reception',
            $this->user($request),
        );

        return redirect()->route('frontdesk')->with('success', "Checked in — ticket {$visit->ticket}.");
    }

    public function move(Request $request, Visit $visit, TransitionVisit $action): RedirectResponse
    {
        $this->authorize(Permission::VISITS_MANAGE);

        $data = $request->validate(['stage' => ['required', Rule::enum(VisitStage::class)], 'override_reason' => ['nullable', 'string', 'max:255']]);
        $stage = VisitStage::from($data['stage']);

        if ($stage === VisitStage::Done) {
            $override = $data['override_reason'] ?? null;
            if (filled($override)) {
                $this->authorize(Permission::DISCHARGE_OVERRIDE);
            }
            app(DischargeVisit::class)->handle($visit, $this->user($request), $override);
        } else {
            $action->handle($visit, $stage, $this->user($request));
        }

        return back()->with('success', "{$visit->ticket} moved to {$visit->stage->label()}.");
    }

    public function remove(Request $request, Visit $visit, RemoveFromQueue $action): RedirectResponse
    {
        $this->authorize(Permission::VISITS_MANAGE);

        $data = $request->validate([
            'reason' => ['required', Rule::enum(LeftReason::class)],
            'note' => ['nullable', 'string', 'max:255'],
        ]);
        $action->handle($visit, LeftReason::from($data['reason']), $data['note'] ?? null, $this->user($request));

        return back()->with('success', "{$visit->ticket} removed from the queue.");
    }

    private function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
