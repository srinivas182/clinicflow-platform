<?php

declare(strict_types=1);

namespace App\Domains\Scribe\Actions;

use App\Domains\Clinical\Models\Consultation;
use App\Domains\Patients\Models\Patient;
use App\Domains\Platform\Models\Subscription;
use App\Domains\Scribe\Models\AiProvider;
use App\Domains\Scribe\Providers\NoteWriter;
use App\Domains\Scribe\Providers\SpeechToText;
use App\Domains\Wallet\Actions\WalletLedger;
use App\Domains\Wallet\Models\Wallet;
use App\Domains\Wallet\Support\WalletSettings;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * AI scribe: with the patient's agreement, a recording is transcribed and Claude
 * drafts a consultation note for the doctor to review. Minutes come first from the
 * month's included minutes (package + add-on), then from the practice wallet; a
 * recording the wallet cannot cover is refused before anything is sent, so the
 * platform never carries a practice's AI cost. Audio is never stored.
 */
class AiScribe
{
    public function __construct(private readonly WalletLedger $ledger) {}

    private function central(): ConnectionInterface
    {
        return DB::connection((string) config('tenancy.database.central_connection'));
    }

    /**
     * @return array{enabled: bool, included: int, used: int, left: int, price_per_minute_cents: int}
     */
    public function allowance(string $tenantId): array
    {
        $subscription = Subscription::query()->where('tenant_id', $tenantId)->latest('id')->first();
        $packageMinutes = (int) ($subscription?->package?->limits['ai_minutes'] ?? 0);
        $addon = in_array('ai_scribe', (array) ($subscription?->getAttribute('addons') ?? []), true);
        $included = $packageMinutes + ($addon ? (int) WalletSettings::get('ai.addon_minutes') : 0);
        $used = (int) $this->central()->table('ai_usage')->where('tenant_id', $tenantId)->where('period', now()->format('Y-m'))->value('minutes_included_used');

        return ['enabled' => $addon || $packageMinutes > 0, 'included' => $included, 'used' => $used, 'left' => max(0, $included - $used),
            'price_per_minute_cents' => (int) WalletSettings::get('ai.price_per_minute_cents')];
    }

    /**
     * The doctor confirms the patient agreed (or records that they declined).
     */
    public function start(Consultation $consultation, int $staffId, bool $patientAgreed): ?string
    {
        $patient = Patient::query()->whereKey($consultation->patient_id)->firstOrFail();
        if (! $patientAgreed) {
            $patient->forceFill(['ai_scribe_declined_at' => now()])->save();
            activity('scribe')->performedOn($patient)->log('Patient declined the AI scribe');

            return null;
        }
        if (! $this->allowance((string) tenant('id'))['enabled']) {
            throw ValidationException::withMessages(['scribe' => 'The AI scribe is not part of this practice\'s package. Add it under Billing.']);
        }
        $patient->forceFill(['ai_scribe_declined_at' => null])->save();
        $id = strtolower((string) Str::ulid());
        DB::table('scribe_sessions')->insert(['id' => $id, 'consultation_id' => $consultation->id, 'patient_id' => $patient->id, 'staff_id' => $staffId,
            'consent_at' => now(), 'status' => 'created', 'created_at' => now(), 'updated_at' => now()]);
        activity('scribe')->performedOn($consultation)->withProperties(['session' => $id])->log('Patient agreed to the AI scribe');

        return $id;
    }

    /**
     * Video, audio or chat consult: ask the patient on their own screen. Nothing runs until they agree.
     */
    public function request(Consultation $consultation, string $appointmentId, int $staffId, string $source): string
    {
        if (! in_array($source, ['call', 'chat'], true)) {
            throw ValidationException::withMessages(['scribe' => 'Unknown consult type.']);
        }
        if (! $this->allowance((string) tenant('id'))['enabled']) {
            throw ValidationException::withMessages(['scribe' => 'The AI scribe is not part of this practice\'s package. Add it under Billing.']);
        }
        DB::table('scribe_sessions')->where('appointment_id', $appointmentId)->where('status', 'awaiting')->update(['status' => 'discarded', 'updated_at' => now()]);
        $id = strtolower((string) Str::ulid());
        DB::table('scribe_sessions')->insert(['id' => $id, 'consultation_id' => $consultation->id, 'patient_id' => $consultation->patient_id, 'staff_id' => $staffId,
            'appointment_id' => $appointmentId, 'source' => $source, 'consent_at' => null, 'status' => 'awaiting', 'created_at' => now(), 'updated_at' => now()]);

        return $id;
    }

    /**
     * The patient's answer from their own screen.
     */
    public function answer(string $sessionId, string $patientId, bool $agree): void
    {
        $session = DB::table('scribe_sessions')->where('id', $sessionId)->where('patient_id', $patientId)->where('status', 'awaiting')->first();
        if ($session === null) {
            throw ValidationException::withMessages(['scribe' => 'There is no AI scribe request to answer.']);
        }
        DB::table('scribe_sessions')->where('id', $sessionId)->update(['status' => $agree ? 'created' : 'declined', 'consent_at' => $agree ? now() : null, 'updated_at' => now()]);
        Patient::query()->whereKey($patientId)->update(['ai_scribe_declined_at' => $agree ? null : now()]);
        activity('scribe')->withProperties(['session' => $sessionId, 'by' => 'patient'])->log($agree ? 'Patient agreed to the AI scribe on their own screen' : 'Patient declined the AI scribe');
    }

    /**
     * Chat consult: draft straight from the chat messages (no speech-to-text); counts as one minute.
     */
    public function fromChat(string $sessionId): void
    {
        $session = DB::table('scribe_sessions')->where('id', $sessionId)->where('source', 'chat')->first();
        if ($session === null || $session->status !== 'created' || $session->consent_at === null) {
            throw ValidationException::withMessages(['scribe' => 'The patient has not agreed to the AI scribe for this chat.']);
        }
        $thread = DB::table('chat_threads')->where('appointment_id', $session->appointment_id)->first();
        $messages = $thread === null ? collect() : DB::table('chat_messages')->where('chat_thread_id', $thread->id)->orderBy('id')->get(['sender', 'body']);
        $text = $messages->map(fn ($m) => ucfirst((string) $m->sender).': '.(string) $m->body)->implode("\n");
        if (trim($text) === '') {
            throw ValidationException::withMessages(['scribe' => 'There are no chat messages to draft from yet.']);
        }
        $tenantId = (string) tenant('id');
        if (! $this->affordable($tenantId, 1)) {
            throw ValidationException::withMessages(['scribe' => 'Not enough AI scribe minutes or wallet balance. Top up the wallet.']);
        }
        $billed = $this->bill($tenantId, $sessionId, 1);
        $patient = Patient::query()->whereKey((string) $session->patient_id)->firstOrFail();
        DB::table('scribe_sessions')->where('id', $sessionId)->update(['status' => 'transcribed', 'minutes_billed' => 1, 'wallet_cents' => $billed['wallet_cents'],
            'transcript' => Crypt::encryptString($this->withoutNames($patient, $text)), 'updated_at' => now()]);
        $this->draft($sessionId);
    }

    /**
     * Transcribe, bill, and draft. $claimedSeconds (from the browser) is only used to refuse
     * recordings the practice cannot pay for before anything is sent; billing uses the provider's measured length.
     */
    public function process(string $sessionId, string $audio, string $mime, int $claimedSeconds): void
    {
        $session = DB::table('scribe_sessions')->where('id', $sessionId)->first();
        if ($session === null || $session->status !== 'created') {
            throw ValidationException::withMessages(['scribe' => 'This recording was already processed.']);
        }
        $tenantId = (string) tenant('id');
        $max = (int) WalletSettings::get('ai.max_recording_minutes') * 60;
        if ($claimedSeconds < 1 || $claimedSeconds > $max) {
            throw ValidationException::withMessages(['audio' => 'Recordings can be up to '.intdiv($max, 60).' minutes.']);
        }
        $speech = AiProvider::active('speech');
        $notes = AiProvider::active('notes');
        if (! $speech instanceof AiProvider || ! $notes instanceof AiProvider) {
            throw ValidationException::withMessages(['scribe' => 'The AI scribe is not available right now.']);
        }
        if (! $this->affordable($tenantId, (int) ceil($claimedSeconds / 60))) {
            throw ValidationException::withMessages(['scribe' => 'Not enough AI scribe minutes or wallet balance for this recording. Top up the wallet.']);
        }

        try {
            $heard = SpeechToText::transcribe($speech, $audio, $mime);
            if (trim($heard['text']) === '') {
                // Nothing usable was heard: treat as a failure and charge nothing.
                throw new \RuntimeException('No speech was recognised in the recording.');
            }
        } catch (\Throwable $e) {
            $this->fail($sessionId, $e->getMessage());

            throw ValidationException::withMessages(['scribe' => 'The recording could not be transcribed (or no speech was heard). Nothing was charged.']);
        } finally {
            unset($audio); // audio is never written to storage
        }

        $seconds = min($max, max(1, $heard['seconds'] ?: $claimedSeconds));
        $billed = $this->bill($tenantId, $sessionId, (int) ceil($seconds / 60));
        $patient = Patient::query()->whereKey((string) $session->patient_id)->firstOrFail();
        $transcript = $this->withoutNames($patient, $heard['text']);
        DB::table('scribe_sessions')->where('id', $sessionId)->update(['status' => 'transcribed', 'seconds' => $seconds, 'minutes_billed' => $billed['minutes'], 'wallet_cents' => $billed['wallet_cents'],
            'transcript' => Crypt::encryptString($transcript), 'speech_driver' => $speech->driver, 'updated_at' => now()]);

        $this->draft($sessionId);
    }

    /**
     * (Re)drafts the note from the stored transcript — no extra charge.
     */
    public function draft(string $sessionId): void
    {
        $session = DB::table('scribe_sessions')->where('id', $sessionId)->first();
        $notes = AiProvider::active('notes');
        if ($session === null || $session->transcript === null || ! in_array($session->status, ['transcribed', 'failed', 'drafted'], true) || ! $notes instanceof AiProvider) {
            throw ValidationException::withMessages(['scribe' => 'There is no transcript to draft from.']);
        }
        try {
            $draft = NoteWriter::draft($notes, Crypt::decryptString((string) $session->transcript));
        } catch (\Throwable $e) {
            $this->fail($sessionId, $e->getMessage());

            throw ValidationException::withMessages(['scribe' => 'The draft could not be written. Try again — the recording was kept and will not be charged twice.']);
        }
        DB::table('scribe_sessions')->where('id', $sessionId)->update(['status' => 'drafted', 'draft' => Crypt::encryptString((string) json_encode($draft)),
            'notes_driver' => $notes->driver, 'error' => null, 'updated_at' => now()]);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function draftFor(string $sessionId): ?array
    {
        $raw = DB::table('scribe_sessions')->where('id', $sessionId)->value('draft');

        return $raw === null ? null : (array) json_decode(Crypt::decryptString((string) $raw), true);
    }

    public function close(string $sessionId, bool $accepted): void
    {
        DB::table('scribe_sessions')->where('id', $sessionId)->whereIn('status', ['drafted', 'transcribed', 'failed'])
            ->update(['status' => $accepted ? 'accepted' : 'discarded', 'accepted_at' => $accepted ? now() : null, 'updated_at' => now()]);
        activity('scribe')->withProperties(['session' => $sessionId])->log($accepted ? 'AI draft accepted into the note for review and saving' : 'AI draft discarded');
    }

    /** Removes the patient's names before any text leaves for the note writer. */
    private function withoutNames(Patient $patient, string $text): string
    {
        $names = array_filter(array_merge(explode(' ', (string) $patient->first_names), [(string) $patient->surname]), fn ($n) => mb_strlen($n) > 1);

        return $names === [] ? $text : (string) preg_replace('/\b('.implode('|', array_map(fn ($n) => preg_quote($n, '/'), $names)).')\b/iu', 'the patient', $text);
    }

    /** For other AI features (e.g. lab explanations): can this practice pay for $minutes? */
    public function canAfford(string $tenantId, int $minutes): bool
    {
        return $this->allowance($tenantId)['enabled'] && $this->affordable($tenantId, $minutes);
    }

    /**
     * Charges $minutes of AI use (included minutes first, then the wallet) under $reference.
     *
     * @return array{minutes: int, wallet_cents: int}
     */
    public function chargeMinutes(string $tenantId, string $reference, int $minutes): array
    {
        return $this->bill($tenantId, $reference, $minutes);
    }

    private function affordable(string $tenantId, int $minutes): bool
    {
        $left = $this->allowance($tenantId)['left'];
        $extra = max(0, $minutes - $left);

        return $extra === 0 || Wallet::for($tenantId)->availableCents() >= $extra * (int) WalletSettings::get('ai.price_per_minute_cents');
    }

    /**
     * @return array{minutes: int, wallet_cents: int}
     */
    private function bill(string $tenantId, string $sessionId, int $minutes): array
    {
        $allowance = $this->allowance($tenantId);
        $fromIncluded = min($minutes, $allowance['left']);
        $extra = $minutes - $fromIncluded;
        $cents = $extra * $allowance['price_per_minute_cents'];
        if ($cents > 0 && ! $this->ledger->chargeUsage(Wallet::for($tenantId), $cents, 'ai-'.$sessionId, "AI scribe: {$extra} extra minute(s)")) {
            // The pre-check passed but the balance changed meanwhile: charge nothing more than is available.
            $cents = 0;
        }
        $period = now()->format('Y-m');
        $this->central()->table('ai_usage')->insertOrIgnore(['tenant_id' => $tenantId, 'period' => $period, 'created_at' => now(), 'updated_at' => now()]);
        $this->central()->table('ai_usage')->where('tenant_id', $tenantId)->where('period', $period)
            ->incrementEach(['minutes_included_used' => $fromIncluded, 'minutes_wallet' => $extra, 'wallet_cents' => $cents], ['updated_at' => now()]);

        return ['minutes' => $minutes, 'wallet_cents' => $cents];
    }

    private function fail(string $sessionId, string $error): void
    {
        DB::table('scribe_sessions')->where('id', $sessionId)->update(['status' => 'failed', 'error' => mb_substr($error, 0, 255), 'updated_at' => now()]);
    }
}
