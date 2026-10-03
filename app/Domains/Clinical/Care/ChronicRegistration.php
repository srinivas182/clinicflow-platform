<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Care;

use App\Domains\Patients\Models\Patient;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Chronic medicine registration with the patient's medical aid. Today the
 * practice submits it through the scheme and records the outcome here; once the
 * claims switch is connected, submit() sends it electronically (see the guide).
 */
class ChronicRegistration
{
    /**
     * @param  list<string>  $medicines
     */
    public function create(Patient $patient, string $icd10, array $medicines, ?string $notes = null): int
    {
        if (blank($patient->medical_aid_scheme)) {
            throw ValidationException::withMessages(['scheme' => 'The patient has no medical aid.']);
        }
        if ($medicines === []) {
            throw ValidationException::withMessages(['medicines' => 'List the chronic medicines.']);
        }

        return (int) DB::table('chronic_registrations')->insertGetId(['patient_id' => $patient->id, 'scheme' => (string) $patient->medical_aid_scheme, 'icd10_code' => strtoupper($icd10),
            'medicines' => json_encode($medicines), 'status' => 'draft', 'notes' => $notes, 'created_at' => now(), 'updated_at' => now()]);
    }

    public function submit(int $id): void
    {
        $this->move($id, 'draft', ['status' => 'submitted', 'submitted_at' => now()]);
    }

    public function decide(int $id, bool $approved, ?string $reference, ?string $notes): void
    {
        $this->move($id, 'submitted', ['status' => $approved ? 'approved' : 'declined', 'reference' => $reference, 'notes' => $notes, 'decided_at' => now()]);
    }

    /**
     * @param  array<string, mixed>  $changes
     */
    private function move(int $id, string $from, array $changes): void
    {
        $updated = DB::table('chronic_registrations')->where('id', $id)->where('status', $from)->update($changes + ['updated_at' => now()]);
        if ($updated === 0) {
            throw ValidationException::withMessages(['registration' => "This registration is not {$from}."]);
        }
    }
}
