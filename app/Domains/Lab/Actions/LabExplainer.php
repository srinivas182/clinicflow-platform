<?php

declare(strict_types=1);

namespace App\Domains\Lab\Actions;

use App\Domains\Identity\Models\Staff;
use App\Domains\Lab\Models\LabOrder;
use App\Domains\Lab\Models\LabResult;
use App\Domains\Patients\Models\Patient;
use App\Domains\Scribe\Actions\AiScribe;
use App\Domains\Scribe\Models\AiProvider;
use App\Domains\Scribe\Providers\NoteWriter;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Drafts a plain-language explanation of lab results for the doctor to edit and
 * release. Only on the doctor's request, never for critical results, and only
 * the age band, sex, results and the doctor's own comment are sent (no name or ID).
 * Counts as one AI minute; nothing is charged if drafting fails.
 */
class LabExplainer
{
    public function __construct(private readonly AiScribe $ai) {}

    public function draft(LabOrder $order, Staff $doctor): string
    {
        if (! in_array($order->status, ['verified', 'discuss'], true)) {
            throw ValidationException::withMessages(['order' => 'Only verified results can be explained.']);
        }
        if ($order->has_critical) {
            throw ValidationException::withMessages(['order' => 'Critical results need a conversation with the patient — no AI explanation.']);
        }
        $tenantId = (string) tenant('id');
        $notes = AiProvider::active('notes');
        if (! $notes instanceof AiProvider) {
            throw ValidationException::withMessages(['order' => 'AI explanations are not available right now.']);
        }
        if (! $this->ai->canAfford($tenantId, 1)) {
            throw ValidationException::withMessages(['order' => 'Not enough AI minutes or wallet balance. Top up the wallet.']);
        }
        $patient = Patient::query()->whereKey($order->patient_id)->firstOrFail();
        $age = $patient->ageInYears();
        $payload = [
            'age_band' => $age < 18 ? 'under 18' : intdiv($age, 10) * 10 .'-'.(intdiv($age, 10) * 10 + 9),
            'sex' => $patient->sex === null ? 'not recorded' : $patient->sex->value,
            'doctor_comment' => $order->doctor_comment,
            'results' => $order->results()->get()->map(fn (LabResult $r) => array_filter([
                'test' => $r->name, 'value' => $r->value ?? $r->getAttribute('result_text'), 'unit' => $r->unit ?: null,
                'usual_range' => $r->reference ?: null, 'flag' => $r->flag,
            ], fn ($v) => $v !== null && $v !== ''))->values()->all(),
        ];
        try {
            $text = NoteWriter::explain($notes, $payload);
        } catch (\Throwable) {
            throw ValidationException::withMessages(['order' => 'The explanation could not be written right now. Nothing was charged — try again.']);
        }
        $billed = $this->ai->chargeMinutes($tenantId, 'lab-'.$order->id.'-'.now()->timestamp, 1);
        DB::table('lab_explanations')->insert(['lab_order_id' => $order->id, 'staff_id' => $doctor->id, 'minutes' => 1, 'wallet_cents' => $billed['wallet_cents'], 'created_at' => now()]);
        activity('lab')->performedOn($order)->log('AI explanation drafted for the doctor to review');

        return $text;
    }
}
