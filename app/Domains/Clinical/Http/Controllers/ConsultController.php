<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Http\Controllers;

use App\Domains\Clinical\Actions\CompleteConsultation;
use App\Domains\Clinical\Actions\SaveConsultation;
use App\Domains\Clinical\Models\Allergy;
use App\Domains\Clinical\Models\Consultation;
use App\Domains\Clinical\Models\ConsultationDiagnosis;
use App\Domains\Clinical\Models\Icd10Code;
use App\Domains\Clinical\Models\TriageRecord;
use App\Domains\Identity\Enums\Permission;
use App\Domains\Identity\Models\Staff;
use App\Domains\Lab\Models\LabTest;
use App\Domains\Prescribing\Actions\CheckPrescriptionSafety;
use App\Domains\Prescribing\Models\Medicine;
use App\Domains\Prescribing\Models\Prescription;
use App\Domains\Prescribing\Models\PrescriptionItem;
use App\Domains\Prescribing\Support\SafetyIssue;
use App\Domains\Visits\Enums\VisitStage;
use App\Domains\Visits\Models\Visit;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The doctor's consult screen: notes, ICD-10 diagnoses, prescription.
 */
class ConsultController extends Controller
{
    public function show(Request $request, Visit $visit, CheckPrescriptionSafety $safety): Response
    {
        $this->authorize(Permission::CONSULTS_WRITE);
        $me = $this->staff($request);
        abort_unless($visit->doctor_id === $me->id && $visit->stage === VisitStage::Doctor || Consultation::query()->where('visit_id', $visit->id)->exists(), 403, 'Call this patient before starting the consult.');

        $consultation = Consultation::query()->firstOrCreate(['visit_id' => $visit->id], [
            'patient_id' => $visit->patient_id,
            'doctor_staff_id' => $me->id,
        ]);
        abort_unless($consultation->doctor_staff_id === $me->id, 403, 'Another doctor is seeing this patient.');

        $prescriptions = Prescription::query()->with('items')->where('consultation_id', $consultation->id)->orderBy('version')->get();
        $draft = $prescriptions->firstWhere('status', Prescription::DRAFT);
        $triage = TriageRecord::query()->where('visit_id', $visit->id)->first();

        return Inertia::render('Consult/Show', [
            'visit' => ['id' => $visit->id, 'ticket' => $visit->ticket, 'stage' => $visit->stage->value],
            'patient' => [
                'name' => $visit->patient->fullName(),
                'age' => $visit->patient->ageInYears(),
                'medicalAid' => $visit->patient->medical_aid_scheme,
                'allergies' => Allergy::query()->where('patient_id', $visit->patient_id)->where('status', 'active')->pluck('substance'),
            ],
            'triage' => $triage?->only(['bp_systolic', 'bp_diastolic', 'pulse', 'temperature', 'colour', 'complaint']),
            'consultation' => [
                'id' => $consultation->id,
                'subjective' => $consultation->subjective, 'objective' => $consultation->objective,
                'assessment' => $consultation->assessment, 'plan' => $consultation->plan,
                'lockVersion' => $consultation->lock_version,
                'completed' => $consultation->isCompleted(),
                'diagnoses' => $consultation->diagnoses()->get()->map(fn (ConsultationDiagnosis $d) => ['code' => $d->icd10_code, 'description' => $d->description, 'primary' => $d->is_primary])->values(),
            ],
            'prescriptions' => $prescriptions->map(fn (Prescription $p) => [
                'id' => $p->id, 'version' => $p->version, 'status' => $p->status, 'signedAt' => $p->signed_at?->format('j M H:i'),
                'dispensable' => $p->isDispensable(), 'changeReason' => $p->change_reason,
                'items' => $p->items->map(fn (PrescriptionItem $i) => $i->only(['id', 'medicine_id', 'description', 'schedule', 'dose', 'quantity', 'repeats', 'override_reason']))->values(),
            ])->values(),
            'labTests' => LabTest::query()->orderBy('name')->get(['code', 'name', 'price_cents']),
            'safety' => $draft instanceof Prescription ? array_map(fn (SafetyIssue $i) => $i->toArray(), $safety->handle($draft)) : [],
        ]);
    }

    public function save(Request $request, Consultation $consultation, SaveConsultation $action): RedirectResponse
    {
        $this->authorizeConsult($request, $consultation);
        $data = $request->validate([
            'subjective' => ['nullable', 'string', 'max:5000'], 'objective' => ['nullable', 'string', 'max:5000'],
            'assessment' => ['nullable', 'string', 'max:5000'], 'plan' => ['nullable', 'string', 'max:5000'],
            'diagnoses' => ['array', 'max:10'], 'diagnoses.*.code' => ['required', 'string', 'max:10'], 'diagnoses.*.primary' => ['boolean'],
            'lock_version' => ['required', 'integer'],
        ]);

        $diagnoses = array_map(fn (array $d) => ['code' => (string) $d['code'], 'primary' => (bool) ($d['primary'] ?? false)], $data['diagnoses'] ?? []);
        $action->handle($consultation, $data, array_values($diagnoses), (int) $data['lock_version']);

        return back()->with('success', 'Notes saved.');
    }

    public function complete(Request $request, Consultation $consultation, CompleteConsultation $action): RedirectResponse
    {
        $this->authorizeConsult($request, $consultation);
        $action->handle($consultation, $this->user($request));

        return redirect('/doctor')->with('success', 'Consultation completed.');
    }

    public function icd10(Request $request): JsonResponse
    {
        $this->authorize(Permission::CONSULTS_WRITE);
        $q = trim($request->string('q')->toString());

        return response()->json(Icd10Code::query()
            ->where(fn ($w) => $w->where('code', 'like', strtoupper($q).'%')->orWhere('description', 'like', "%{$q}%"))
            ->orderBy('code')->limit(15)->get(['code', 'description', 'valid_primary']));
    }

    public function medicines(Request $request): JsonResponse
    {
        $this->authorize(Permission::CONSULTS_WRITE);
        $q = trim($request->string('q')->toString());

        return response()->json(Medicine::query()->where('name', 'like', "%{$q}%")->orderBy('name')->limit(15)
            ->get(['id', 'name', 'strength', 'form', 'schedule', 'default_dose']));
    }

    private function authorizeConsult(Request $request, Consultation $consultation): void
    {
        $this->authorize(Permission::CONSULTS_WRITE);
        abort_unless($consultation->doctor_staff_id === $this->staff($request)->id, 403, 'Another doctor is seeing this patient.');
    }

    private function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }

    private function staff(Request $request): Staff
    {
        return Staff::query()->findOrFail($this->user($request)->id);
    }
}
