<?php

declare(strict_types=1);

namespace App\Domains\Website\Actions;

use App\Domains\Messaging\Actions\SendMessage;
use App\Domains\Patients\Models\Patient;
use App\Domains\Platform\Models\Provider;
use App\Domains\Platform\Models\Setting;
use App\Domains\Visits\Enums\VisitStage;
use App\Domains\Visits\Models\Visit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Patient feedback after a visit. Private to the practice by default; shown on
 * the website only when the practice has switched public display on after
 * confirming legal approval (HPCSA advertising rules), and only for reviews
 * the patient agreed may be shown.
 */
class Feedback
{
    /**
     * One request per finished visit from the last two days, at most one per patient every 30 days.
     */
    public function sendRequests(): int
    {
        if (! (bool) Setting::get('reviews', 'enabled', true)) {
            return 0;
        }
        $sent = 0;
        Visit::query()->where('stage', VisitStage::Done->value)->whereBetween('visit_date', [now()->subDays(2)->toDateString(), now()->subDay()->toDateString()])
            ->whereNotIn('id', DB::table('feedback_requests')->select('visit_id'))->get()
            ->each(function (Visit $v) use (&$sent): void {
                $patient = Patient::query()->find($v->patient_id);
                if (! $patient instanceof Patient || blank($patient->cell)
                    || DB::table('feedback_requests')->where('patient_id', $v->patient_id)->where('created_at', '>', now()->subDays(30))->exists()) {
                    return;
                }
                $token = Str::random(40);
                DB::table('feedback_requests')->insert(['visit_id' => $v->id, 'patient_id' => $v->patient_id, 'token' => $token, 'created_at' => now(), 'updated_at' => now()]);
                $ok = app(SendMessage::class)->template('feedback.request', 'sms', (string) $patient->cell, ['patient' => $patient->first_names, 'link' => url('/feedback/'.$token)], 'en', 'visit', $v->id);
                DB::table('feedback_requests')->where('token', $token)->update(['sent_at' => $ok ? now() : null]);
                $sent += $ok ? 1 : 0;
            });

        return $sent;
    }

    public function submit(string $token, int $rating, ?string $comment, bool $publicOk): void
    {
        $request = DB::table('feedback_requests')->where('token', $token)->first();
        if ($request === null || $request->completed_at !== null || now()->diffInDays($request->created_at, true) > 14) {
            throw ValidationException::withMessages(['token' => 'This feedback link has expired or was already used.']);
        }
        if ($rating < 1 || $rating > 5) {
            throw ValidationException::withMessages(['rating' => 'Choose from 1 to 5 stars.']);
        }
        DB::transaction(function () use ($request, $rating, $comment, $publicOk): void {
            DB::table('reviews')->insert([
                'feedback_request_id' => $request->id, 'patient_id' => $request->patient_id, 'staff_id' => Visit::query()->whereKey($request->visit_id)->value('doctor_id'),
                'rating' => $rating, 'comment' => $comment === null ? null : mb_substr(trim($comment), 0, 2000), 'public_ok' => $publicOk,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('feedback_requests')->where('id', $request->id)->update(['completed_at' => now(), 'updated_at' => now()]);
        });
    }

    public function reply(int $reviewId, string $reply): void
    {
        if (trim($reply) === '') {
            throw ValidationException::withMessages(['reply' => 'Write a reply.']);
        }
        DB::table('reviews')->where('id', $reviewId)->update(['reply' => mb_substr(trim($reply), 0, 1000), 'replied_at' => now(), 'updated_at' => now()]);
    }

    /**
     * The practice reports an abusive review; it is hidden until the super admin decides.
     */
    public function flag(int $reviewId, string $reason): void
    {
        $review = DB::table('reviews')->where('id', $reviewId)->first();
        $provider = tenant();
        if ($review === null || ! $provider instanceof Provider || trim($reason) === '') {
            throw ValidationException::withMessages(['reason' => 'Say why this review is abusive.']);
        }
        DB::table('reviews')->where('id', $reviewId)->update(['flagged_at' => now(), 'flag_reason' => mb_substr(trim($reason), 0, 255)]);
        DB::connection((string) config('tenancy.database.central_connection'))->table('flagged_reviews')->updateOrInsert(
            ['tenant_id' => $provider->id, 'review_id' => $reviewId],
            ['rating' => $review->rating, 'comment' => $review->comment, 'reason' => mb_substr(trim($reason), 0, 255), 'created_at' => now(), 'updated_at' => now()],
        );
    }

    public static function publicEnabled(): bool
    {
        return (bool) Setting::get('reviews', 'public', false) && (bool) Setting::get('reviews', 'public_confirmed', false);
    }

    /**
     * @return list<array{rating: int, comment: string|null, name: string, date: string}>|null null when public display is off
     */
    public static function publicReviews(): ?array
    {
        if (! self::publicEnabled()) {
            return null;
        }

        return DB::table('reviews')->join('patients', 'patients.id', '=', 'reviews.patient_id')->where('reviews.public_ok', true)->whereNull('reviews.flagged_at')
            ->orderByDesc('reviews.id')->limit(12)->get(['reviews.rating', 'reviews.comment', 'reviews.created_at', 'patients.first_names', 'patients.surname'])
            ->map(fn ($r) => ['rating' => (int) $r->rating, 'comment' => $r->comment === null ? null : (string) $r->comment,
                'name' => mb_substr((string) $r->first_names, 0, 1).'. '.mb_substr((string) $r->surname, 0, 1).'.', 'date' => substr((string) $r->created_at, 0, 7)])->values()->all();
    }
}
