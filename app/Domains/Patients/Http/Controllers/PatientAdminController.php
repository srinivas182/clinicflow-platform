<?php

declare(strict_types=1);

namespace App\Domains\Patients\Http\Controllers;

use App\Domains\Billing\Models\Invoice;
use App\Domains\Clinical\Models\Allergy;
use App\Domains\Clinical\Models\Consultation;
use App\Domains\Identity\Enums\Permission;
use App\Domains\Identity\Models\AuditEntry;
use App\Domains\Lab\Models\LabOrder;
use App\Domains\Patients\Actions\CaptureConsent;
use App\Domains\Patients\Actions\ImportPatients;
use App\Domains\Patients\Enums\ConsentGivenBy;
use App\Domains\Patients\Models\Patient;
use App\Domains\Prescribing\Models\Prescription;
use App\Domains\Visits\Models\Visit;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Legacy import, consent capture, audit and POPIA compliance tools.
 */
class PatientAdminController extends Controller
{
    public function imports(): Response
    {
        $this->authorize(Permission::SETTINGS_MANAGE);

        return Inertia::render('Patients/Import', [
            'columns' => ImportPatients::COLUMNS,
            'imports' => DB::table('patient_imports')->latest('id')->limit(10)->get()->map(fn ($i) => [
                'id' => $i->id, 'file' => $i->file_name, 'total' => $i->rows_total, 'imported' => $i->rows_imported, 'skipped' => $i->rows_skipped,
                'problems' => array_slice((array) json_decode((string) $i->problems, true), 0, 20), 'at' => (string) $i->created_at,
            ])->values(),
        ]);
    }

    public function import(Request $request, ImportPatients $action): RedirectResponse
    {
        $this->authorize(Permission::SETTINGS_MANAGE);
        $request->validate(['file' => ['required', 'file', 'mimes:csv,txt', 'max:10240']]);
        $file = $request->file('file');
        abort_unless($file instanceof UploadedFile, 422);

        $result = $action->handle((string) $file->getRealPath(), $file->getClientOriginalName(), $this->user($request));

        return back()->with('success', "{$result['imported']} of {$result['total']} patients imported; {$result['skipped']} skipped.");
    }

    public function consent(Request $request, Patient $patient, CaptureConsent $action): RedirectResponse
    {
        $this->authorize(Permission::PATIENTS_EDIT);
        $data = $request->validate([
            'consent_given_by' => ['required', Rule::enum(ConsentGivenBy::class)],
            'popia_consent' => ['accepted'], 'treatment_consent' => ['accepted'], 'maturity_confirmed' => ['boolean'],
        ]);
        $action->handle($patient, ConsentGivenBy::from($data['consent_given_by']), true, true, (bool) ($data['maturity_confirmed'] ?? false), $this->user($request));

        return back()->with('success', 'Consent recorded.');
    }

    public function audit(Request $request): Response
    {
        $this->authorize(Permission::AUDIT_VIEW);
        $query = $this->auditQuery($request);

        return Inertia::render('Compliance/Audit', [
            'filters' => $request->only(['log', 'search', 'from', 'to']),
            'logs' => AuditEntry::query()->distinct()->orderBy('log_name')->pluck('log_name'),
            'entries' => $query->limit(200)->get()->map(fn (AuditEntry $e) => [
                'at' => $e->created_at?->format('Y-m-d H:i'), 'log' => $e->log_name, 'description' => $e->description,
                'by' => $e->causer_id === null ? 'System' : (User::query()->whereKey($e->causer_id)->value('name') ?? 'User #'.$e->causer_id),
                'subject' => $e->subject_type === null ? null : class_basename((string) $e->subject_type).' '.$e->subject_id,
            ])->values(),
        ]);
    }

    public function auditExport(Request $request): StreamedResponse
    {
        $this->authorize(Permission::AUDIT_VIEW);
        $query = $this->auditQuery($request);
        activity('compliance')->causedBy($this->user($request))->withProperties($request->only(['log', 'search', 'from', 'to']))->log('Audit log exported');

        return response()->streamDownload(function () use ($query): void {
            $out = fopen('php://output', 'w');
            if ($out === false) {
                return;
            }
            fputcsv($out, ['time', 'log', 'description', 'causer_id', 'subject_type', 'subject_id']);
            $query->chunk(500, function ($rows) use ($out): void {
                foreach ($rows as $e) {
                    fputcsv($out, [(string) $e->created_at, $e->log_name, $e->description, $e->causer_id, $e->subject_type, $e->subject_id]);
                }
            });
            fclose($out);
        }, 'audit-log-'.now()->format('Ymd-His').'.csv', ['Content-Type' => 'text/csv']);
    }

    /**
     * POPIA section 23: everything this provider holds about a patient.
     */
    public function exportPatient(Request $request, Patient $patient): JsonResponse
    {
        $this->authorize(Permission::AUDIT_VIEW);
        $visits = Visit::query()->where('patient_id', $patient->id)->get();

        $data = [
            'exported_at' => now()->toIso8601String(),
            'patient' => $patient->makeVisible('id_number')->toArray(),
            'consents' => $patient->consents()->get()->toArray(),
            'allergies' => Allergy::query()->where('patient_id', $patient->id)->get()->toArray(),
            'visits' => $visits->toArray(),
            'consultations' => Consultation::query()->with('diagnoses')->where('patient_id', $patient->id)->get()->toArray(),
            'prescriptions' => Prescription::query()->with('items')->where('patient_id', $patient->id)->get()->toArray(),
            'lab_results' => LabOrder::query()->with('results')->where('patient_id', $patient->id)->get()->toArray(),
            'invoices' => Invoice::query()->with(['lines', 'payments'])->where('patient_id', $patient->id)->get()->toArray(),
            'problems' => DB::table('problems')->where('patient_id', $patient->id)->get()->toArray(),
            'immunisations' => DB::table('immunisations')->where('patient_id', $patient->id)->get()->toArray(),
            'pregnancies' => DB::table('pregnancies')->where('patient_id', $patient->id)->get()->toArray(),
            'referrals' => DB::table('referrals')->where('patient_id', $patient->id)->get()->toArray(),
            'shared_and_discussed' => DB::table('patient_access_log')->where('patient_id', $patient->id)->orderByDesc('id')->get()->toArray(),
            'access_log' => $this->accessLog($patient),
        ];
        // Recorded after the export is assembled, so the export shows the log as it stood when requested.
        activity('compliance')->causedBy($this->user($request))->performedOn($patient)->log('Patient data exported (POPIA request)');

        return response()->json($data, 200, ['Content-Disposition' => 'attachment; filename="patient-'.$patient->id.'.json"']);
    }

    /**
     * @return array<int, array{at: string|null, description: string, by: int|null}>
     */
    private function accessLog(Patient $patient): array
    {
        return AuditEntry::query()->where('subject_type', $patient->getMorphClass())->where('subject_id', $patient->id)->latest('id')->get()
            ->map(fn (AuditEntry $e) => ['at' => $e->created_at?->toIso8601String(), 'description' => $e->description, 'by' => $e->causer_id])->values()->all();
    }

    /**
     * @return Builder<AuditEntry>
     */
    private function auditQuery(Request $request): Builder
    {
        return AuditEntry::query()
            ->when($request->filled('log'), fn ($q) => $q->where('log_name', $request->string('log')->toString()))
            ->when($request->filled('search'), fn ($q) => $q->where('description', 'like', '%'.$request->string('search')->toString().'%'))
            ->when($request->filled('from'), fn ($q) => $q->whereDate('created_at', '>=', $request->string('from')->toString()))
            ->when($request->filled('to'), fn ($q) => $q->whereDate('created_at', '<=', $request->string('to')->toString()))
            ->latest('id');
    }

    private function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
