<?php

declare(strict_types=1);

namespace App\Domains\Prescribing\Actions;

use App\Domains\Clinical\Models\Consultation;
use App\Domains\Identity\Models\Staff;
use App\Domains\Prescribing\Contracts\DrugDatabase;
use App\Domains\Prescribing\Models\Prescription;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Creates or replaces the draft script for a consultation.
 */
class SaveDraftPrescription
{
    public function __construct(private readonly DrugDatabase $drugs) {}

    /**
     * @param  list<array{medicine_id: int, dose: string, quantity: int, repeats: int, override_reason?: ?string}>  $items
     */
    public function handle(Consultation $consultation, Staff $prescriber, array $items): Prescription
    {
        if ($consultation->isCompleted()) {
            throw ValidationException::withMessages(['prescription' => 'This consultation is completed.']);
        }
        if ($items === []) {
            throw ValidationException::withMessages(['items' => 'Add at least one medicine.']);
        }

        return DB::transaction(function () use ($consultation, $prescriber, $items): Prescription {
            $draft = Prescription::query()->where('consultation_id', $consultation->id)->where('status', Prescription::DRAFT)->first();
            $latest = (int) Prescription::query()->where('consultation_id', $consultation->id)->max('version');

            $draft ??= Prescription::create([
                'consultation_id' => $consultation->id,
                'patient_id' => $consultation->patient_id,
                'prescriber_staff_id' => $prescriber->id,
                'version' => $latest + 1,
                'status' => Prescription::DRAFT,
            ]);

            $draft->items()->delete();
            foreach ($items as $i => $row) {
                $medicine = $this->drugs->find((int) $row['medicine_id']);
                if ($medicine === null) {
                    throw ValidationException::withMessages(["items.{$i}.medicine_id" => 'Unknown medicine.']);
                }
                if ((int) $row['quantity'] < 1 || trim($row['dose']) === '') {
                    throw ValidationException::withMessages(["items.{$i}.dose" => 'Each medicine needs a dose and a quantity.']);
                }
                $draft->items()->create([
                    'medicine_id' => $medicine->id,
                    'nappi_code' => $medicine->nappi_code,
                    'description' => $medicine->label(),
                    'schedule' => $medicine->schedule,
                    'dose' => trim($row['dose']),
                    'quantity' => (int) $row['quantity'],
                    'repeats' => max(0, (int) $row['repeats']),
                    'override_reason' => isset($row['override_reason']) && trim((string) $row['override_reason']) !== '' ? trim((string) $row['override_reason']) : null,
                ]);
            }

            return $draft->refresh();
        });
    }
}
