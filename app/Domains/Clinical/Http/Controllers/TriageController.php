<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Http\Controllers;

use App\Domains\Clinical\Actions\ManageAllergies;
use App\Domains\Clinical\Actions\RecordTriage;
use App\Domains\Clinical\Enums\TriageColour;
use App\Domains\Clinical\Models\Allergy;
use App\Domains\Clinical\Support\TriageSuggester;
use App\Domains\Clinical\Support\Vitals;
use App\Domains\Identity\Enums\Permission;
use App\Domains\Patients\Models\Patient;
use App\Domains\Visits\Enums\VisitStage;
use App\Domains\Visits\Models\Visit;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class TriageController extends Controller
{
    public function index(): Response
    {
        $this->authorize(Permission::TRIAGE_RECORD);

        return Inertia::render('Triage/Index', [
            'visits' => Visit::query()->with('patient')->onDate('visit_date', today())
                ->where('stage', VisitStage::Triage->value)->orderBy('stage_changed_at')->get()
                ->map(fn (Visit $v) => ['id' => $v->id, 'ticket' => $v->ticket, 'patient' => $v->patient->fullName(), 'age' => $v->patient->ageInYears(), 'minutes' => $v->minutesInStage()])->values(),
        ]);
    }

    public function show(Visit $visit): Response
    {
        $this->authorize(Permission::TRIAGE_RECORD);

        return Inertia::render('Triage/Record', [
            'visit' => ['id' => $visit->id, 'ticket' => $visit->ticket, 'patient' => $visit->patient->fullName(), 'patientId' => $visit->patient_id, 'age' => $visit->patient->ageInYears(), 'stage' => $visit->stage->value],
            'allergies' => Allergy::query()->where('patient_id', $visit->patient_id)->where('status', 'active')->get(['id', 'substance', 'reaction']),
        ]);
    }

    public function suggest(Request $request): JsonResponse
    {
        $this->authorize(Permission::TRIAGE_RECORD);
        $result = TriageSuggester::suggest($this->vitals($request->validate($this->vitalRules())));

        return response()->json(['colour' => $result['colour']->value, 'label' => $result['colour']->label(), 'reasons' => $result['reasons']]);
    }

    public function store(Request $request, Visit $visit, RecordTriage $action): RedirectResponse
    {
        $this->authorize(Permission::TRIAGE_RECORD);
        $data = $request->validate($this->vitalRules() + ['colour' => ['required', Rule::enum(TriageColour::class)], 'notes' => ['nullable', 'string', 'max:500']]);
        $action->handle($visit, $this->vitals($data), TriageColour::from($data['colour']), $data['notes'] ?? null, $this->user($request));

        return redirect()->route('triage.index')->with('success', "{$visit->ticket} triaged — now waiting for a doctor.");
    }

    public function addAllergy(Request $request, Patient $patient, ManageAllergies $action): RedirectResponse
    {
        $this->authorize(Permission::PATIENTS_EDIT);
        $data = $request->validate(['substance' => ['required', 'string', 'max:120'], 'reaction' => ['nullable', 'string', 'max:255']]);
        $action->add($patient, $data['substance'], $data['reaction'] ?? null, $this->user($request));

        return back()->with('success', 'Allergy recorded.');
    }

    public function removeAllergy(Request $request, Allergy $allergy, ManageAllergies $action): RedirectResponse
    {
        $this->authorize(Permission::PATIENTS_EDIT);
        $action->remove($allergy, $request->string('reason')->toString(), $this->user($request));

        return back()->with('success', 'Allergy removed — reason recorded.');
    }

    /**
     * @return array<string, list<string>>
     */
    private function vitalRules(): array
    {
        return [
            'bp_systolic' => ['required', 'integer'], 'bp_diastolic' => ['required', 'integer'], 'pulse' => ['required', 'integer'],
            'temperature' => ['required', 'numeric'], 'spo2' => ['nullable', 'integer'], 'resp_rate' => ['nullable', 'integer'],
            'glucose' => ['nullable', 'numeric'], 'weight_kg' => ['nullable', 'numeric'], 'pain_score' => ['nullable', 'integer'],
        ];
    }

    /**
     * @param  array<string, mixed>  $d
     */
    private function vitals(array $d): Vitals
    {
        $int = fn (string $k): ?int => isset($d[$k]) && is_numeric($d[$k]) ? (int) $d[$k] : null;
        $float = fn (string $k): ?float => isset($d[$k]) && is_numeric($d[$k]) ? (float) $d[$k] : null;

        return new Vitals((int) $int('bp_systolic'), (int) $int('bp_diastolic'), (int) $int('pulse'), (float) $float('temperature'),
            $int('spo2'), $int('resp_rate'), $float('glucose'), $float('weight_kg'), $int('pain_score'));
    }

    private function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
