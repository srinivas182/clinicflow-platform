<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Actions;

use App\Domains\Clinical\Models\MessageThread;
use App\Domains\Clinical\Support\AccessLog;
use App\Domains\Patients\Models\Patient;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The Information Officer's audited review of a patient's clinical conversations:
 * a reason, a second person's approval, read-only access for 7 days, everyone
 * involved notified and the review shown in the patient's own log.
 */
class BreakGlass
{
    public const REASONS = ['complaint' => 'Patient complaint', 'legal_claim' => 'Medico-legal claim', 'regulator' => 'HPCSA or Ombud investigation', 'breach' => 'Data-breach investigation', 'access_request' => 'Formal patient access request'];

    public function request(Patient $patient, int $by, string $reason, string $details): int
    {
        if (! array_key_exists($reason, self::REASONS) || trim($details) === '') {
            throw ValidationException::withMessages(['reason' => 'Choose a reason and describe why the review is needed.']);
        }

        return (int) DB::table('break_glass_reviews')->insertGetId(['patient_id' => $patient->id, 'requested_by' => $by, 'reason' => $reason, 'details' => trim($details), 'created_at' => now(), 'updated_at' => now()]);
    }

    public function approve(int $reviewId, int $by): void
    {
        $review = DB::table('break_glass_reviews')->where('id', $reviewId)->first();
        if ($review === null || $review->approved_at !== null) {
            throw ValidationException::withMessages(['review' => 'This review cannot be approved.']);
        }
        if ((int) $review->requested_by === $by) {
            throw ValidationException::withMessages(['review' => 'A second senior person must approve the review.']);
        }
        DB::table('break_glass_reviews')->where('id', $reviewId)->update(['approved_by' => $by, 'approved_at' => now(), 'expires_at' => now()->addDays(7), 'updated_at' => now()]);

        AccessLog::record((string) $review->patient_id, 'reviewed', 'Your care discussions were reviewed by the practice\'s Information Officer — reason: '.self::REASONS[$review->reason]);
        foreach (MessageThread::query()->where('patient_id', $review->patient_id)->get() as $thread) {
            activity('compliance')->performedOn($thread)->withProperties(['reason' => $review->reason, 'review' => $reviewId])->log('Conversation opened for break-glass review');
        }
    }

    /**
     * Read-only access is allowed only for the requester of an approved, unexpired review.
     */
    public function canRead(string $patientId, int $userId): bool
    {
        return DB::table('break_glass_reviews')->where('patient_id', $patientId)->where('requested_by', $userId)
            ->whereNotNull('approved_at')->where('expires_at', '>', now())->exists();
    }
}
