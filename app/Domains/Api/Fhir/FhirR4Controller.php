<?php

declare(strict_types=1);

namespace App\Domains\Api\Fhir;

use App\Domains\Clinical\Support\AccessLog;
use App\Domains\Patients\Models\Patient;
use App\Domains\Prescribing\Models\Prescription;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * FHIR R4 read API. A connected system sees only patients who consented to it,
 * and only the consented categories. Clinical notes are never exposed; lab
 * results only once released. Every read is shown to the patient on "My care".
 */
class FhirR4Controller extends Controller
{
    public function __construct(private readonly FhirConsents $consents) {}

    public function metadata(): JsonResponse
    {
        $read = fn (string $type, array $search = []) => ['type' => $type, 'interaction' => [['code' => 'read'], ['code' => 'search-type']],
            'searchParam' => array_map(fn ($p) => ['name' => $p, 'type' => 'reference'], $search)];

        return $this->fhir([
            'resourceType' => 'CapabilityStatement', 'status' => 'active', 'date' => now()->toDateString(), 'kind' => 'instance', 'fhirVersion' => '4.0.1', 'format' => ['json'],
            'software' => ['name' => 'Clinic Flow', 'version' => (string) config('clinicflow.version')],
            'implementation' => ['description' => 'Read-only clinical record, per-patient consent required for each connected system. Clinical notes are never available.', 'url' => url('/api/fhir/r4')],
            'rest' => [['mode' => 'server', 'security' => ['description' => 'Authorization: Bearer <practice API key with the fhir:read permission>'],
                'resource' => [$read('Patient'), $read('AllergyIntolerance', ['patient']), $read('Condition', ['patient']), $read('MedicationRequest', ['patient']),
                    $read('Immunization', ['patient']), $read('Observation', ['patient'])]]],
        ]);
    }

    /** Patients who have consented to this system. */
    public function patients(Request $request): JsonResponse
    {
        $ids = $this->consents->patientsFor($this->keyId($request));

        return $this->bundle(Patient::query()->whereIn('id', $ids)->orderBy('surname')->limit(500)->get()->map(fn (Patient $p) => $this->patientResource($p)));
    }

    public function patient(Request $request, string $id): JsonResponse
    {
        $patient = Patient::query()->whereKey($id)->first();
        if (! $patient instanceof Patient || $this->consents->allowed($patient->id, $this->keyId($request)) === []) {
            return $this->outcome(404, 'not-found', 'No patient with that id has consented to this system.');
        }
        $this->log($request, $patient->id, 'demographics');

        return $this->fhir($this->patientResource($patient));
    }

    public function search(Request $request, string $type): JsonResponse
    {
        $category = ['AllergyIntolerance' => 'allergies', 'Condition' => 'problems', 'MedicationRequest' => 'medicines', 'Immunization' => 'immunisations', 'Observation' => 'results'][$type];
        $patientId = (string) preg_replace('#^Patient/#', '', (string) $request->query('patient', ''));
        if ($patientId === '') {
            return $this->outcome(400, 'required', 'The patient search parameter is required.');
        }
        if (! in_array($category, $this->consents->allowed($patientId, $this->keyId($request)), true)) {
            return $this->outcome(403, 'forbidden', 'The patient has not consented to share this part of their record with this system.');
        }
        $ref = ['reference' => 'Patient/'.$patientId];
        $resources = match ($type) {
            'AllergyIntolerance' => DB::table('allergies')->where('patient_id', $patientId)->where('status', '!=', 'removed')->orderBy('id')->get()->map(fn ($a) => [
                'resourceType' => 'AllergyIntolerance', 'id' => 'allergy-'.$a->id, 'patient' => $ref, 'recordedDate' => substr((string) $a->created_at, 0, 10),
                'clinicalStatus' => $this->concept('http://terminology.hl7.org/CodeSystem/allergyintolerance-clinical', 'active'),
                'code' => ['text' => $a->substance], 'reaction' => $a->reaction ? [['manifestation' => [['text' => $a->reaction]]]] : [],
            ]),
            'Condition' => DB::table('problems')->where('patient_id', $patientId)->orderBy('id')->get()->map(fn ($p) => array_filter([
                'resourceType' => 'Condition', 'id' => 'problem-'.$p->id, 'subject' => $ref,
                'code' => array_filter(['text' => $p->description, 'coding' => $p->icd10_code ? [['system' => 'http://hl7.org/fhir/sid/icd-10', 'code' => $p->icd10_code]] : null]),
                'clinicalStatus' => $this->concept('http://terminology.hl7.org/CodeSystem/condition-clinical', $p->status === 'resolved' ? 'resolved' : 'active'),
                'category' => [$this->concept('http://terminology.hl7.org/CodeSystem/condition-category', 'problem-list-item')],
                'onsetDateTime' => $p->onset_date, 'extension' => (bool) $p->chronic ? [['url' => 'https://clinicflow.co.za/fhir/chronic', 'valueBoolean' => true]] : null,
            ], fn ($v) => $v !== null)),
            'MedicationRequest' => DB::table('prescription_items')->join('prescriptions', 'prescriptions.id', '=', 'prescription_items.prescription_id')
                ->where('prescriptions.patient_id', $patientId)->where('prescriptions.status', Prescription::SIGNED)->orderByDesc('prescriptions.signed_at')->limit(200)
                ->get(['prescription_items.*', 'prescriptions.signed_at'])->map(fn ($i) => [
                    'resourceType' => 'MedicationRequest', 'id' => 'rx-item-'.$i->id, 'status' => 'active', 'intent' => 'order', 'subject' => $ref,
                    'authoredOn' => substr((string) $i->signed_at, 0, 10),
                    'medicationCodeableConcept' => array_filter(['text' => $i->description, 'coding' => $i->nappi_code ? [['system' => 'urn:za:nappi', 'code' => $i->nappi_code]] : null]),
                    'dosageInstruction' => [['text' => (string) $i->dose]],
                    'dispenseRequest' => ['quantity' => ['value' => (int) $i->quantity], 'numberOfRepeatsAllowed' => (int) $i->repeats],
                ]),
            'Immunization' => DB::table('immunisations')->where('patient_id', $patientId)->orderBy('given_on')->get()->map(fn ($v) => array_filter([
                'resourceType' => 'Immunization', 'id' => 'imm-'.$v->id, 'status' => 'completed', 'patient' => $ref, 'vaccineCode' => ['text' => $v->vaccine],
                'occurrenceDateTime' => $v->given_on, 'lotNumber' => $v->batch, 'protocolApplied' => $v->dose ? [['doseNumberString' => $v->dose]] : null,
            ], fn ($x) => $x !== null)),
            default => $this->observations($patientId, $ref),
        };
        $this->log($request, $patientId, $category);

        return $this->bundle($resources);
    }

    /**
     * Released lab results only.
     *
     * @param  array{reference: string}  $ref
     * @return Collection<int, mixed>
     */
    private function observations(string $patientId, array $ref): Collection
    {
        $flags = ['high' => 'H', 'low' => 'L', 'critical' => 'AA', 'normal' => 'N'];

        return DB::table('lab_results')->join('lab_orders', 'lab_orders.id', '=', 'lab_results.lab_order_id')->where('lab_orders.patient_id', $patientId)
            ->whereNotNull('lab_orders.released_at')->orderByDesc('lab_orders.released_at')->limit(500)
            ->get(['lab_results.*', 'lab_orders.verified_at', 'lab_orders.released_at'])->map(fn ($r) => array_filter([
                'resourceType' => 'Observation', 'id' => 'lab-'.$r->id, 'status' => 'final', 'subject' => $ref,
                'category' => [$this->concept('http://terminology.hl7.org/CodeSystem/observation-category', 'laboratory')],
                'code' => array_filter(['text' => $r->name, 'coding' => preg_match('/^\d{1,7}-\d$/', (string) $r->test_code) === 1 ? [['system' => 'http://loinc.org', 'code' => $r->test_code]] : null]),
                'valueQuantity' => $r->value !== null ? array_filter(['value' => (float) $r->value, 'unit' => $r->unit]) : null,
                'referenceRange' => $r->reference ? [['text' => $r->reference]] : null,
                'interpretation' => isset($flags[$r->flag ?? '']) ? [$this->concept('http://terminology.hl7.org/CodeSystem/v3-ObservationInterpretation', $flags[$r->flag])] : null,
                'effectiveDateTime' => $r->verified_at, 'issued' => $r->released_at,
            ], fn ($v) => $v !== null));
    }

    /**
     * @return array<string, mixed>
     */
    private function patientResource(Patient $p): array
    {
        return array_filter([
            'resourceType' => 'Patient', 'id' => $p->id,
            'name' => [['family' => $p->surname, 'given' => array_values(array_filter(explode(' ', (string) $p->first_names)))]],
            'birthDate' => $p->date_of_birth->toDateString(), 'gender' => $p->sex?->value === 'male' ? 'male' : ($p->sex?->value === 'female' ? 'female' : 'unknown'),
            'telecom' => array_values(array_filter([$p->cell ? ['system' => 'phone', 'value' => $p->cell, 'use' => 'mobile'] : null, $p->email ? ['system' => 'email', 'value' => $p->email] : null])),
        ]);
    }

    private function log(Request $request, string $patientId, string $category): void
    {
        $name = (string) DB::table('api_keys')->where('id', $this->keyId($request))->value('name');
        AccessLog::record($patientId, 'shared', '"'.$name.'" (connected system) read your '.($category === 'demographics' ? 'contact details' : strtolower(FhirConsents::CATEGORIES[$category])));
    }

    private function keyId(Request $request): int
    {
        return (int) $request->attributes->get('api_key_id');
    }

    /**
     * @return array{coding: list<array{system: string, code: string}>}
     */
    private function concept(string $system, string $code): array
    {
        return ['coding' => [['system' => $system, 'code' => $code]]];
    }

    /**
     * @param  Collection<int, mixed>  $resources
     */
    private function bundle(Collection $resources): JsonResponse
    {
        return $this->fhir(['resourceType' => 'Bundle', 'type' => 'searchset', 'total' => $resources->count(),
            'entry' => $resources->map(fn ($r) => ['fullUrl' => url('/api/fhir/r4/'.data_get($r, 'resourceType').'/'.data_get($r, 'id')), 'resource' => $r])->values()->all()]);
    }

    private function outcome(int $status, string $code, string $message): JsonResponse
    {
        return $this->fhir(['resourceType' => 'OperationOutcome', 'issue' => [['severity' => 'error', 'code' => $code, 'diagnostics' => $message]]], $status);
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function fhir(array $body, int $status = 200): JsonResponse
    {
        return response()->json($body, $status, ['Content-Type' => 'application/fhir+json']);
    }
}
