<?php

declare(strict_types=1);

namespace App\Domains\Translation\Actions;

use App\Domains\Messaging\Models\MessageTemplate;
use App\Domains\Translation\Models\Translation;
use App\Domains\Translation\Models\TranslatorLanguage;
use App\Domains\Translation\Support\TranslationUnits;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * Translation review: save, approve (native speaker), approve medical wording (clinical reviewer),
 * and publish. Only translations approved by everyone required, and still matching the current
 * English, reach patients; everything else falls back to English.
 */
class Translations
{
    /** Makes sure every unit has a row for the language, and refreshes what is published. */
    public function sync(string $language): void
    {
        foreach (TranslationUnits::all() as $unit => $u) {
            Translation::query()->firstOrCreate(['language' => $language, 'unit' => $unit], ['source' => $u['source'], 'medical' => $u['medical'], 'status' => 'missing']);
        }
        foreach (array_unique(array_map(fn (string $unit) => explode(':', $unit)[1], array_keys(TranslationUnits::all()))) as $key) {
            $this->publish($language, $key);
        }
    }

    public function save(User $user, string $language, string $unit, string $text, string $status = 'draft'): Translation
    {
        $this->mustReview($user, $language);
        $u = TranslationUnits::all()[$unit] ?? throw ValidationException::withMessages(['text' => 'Unknown text.']);
        $text = trim($text);
        $problems = TranslationUnits::placeholderProblems($u['source'], $text);
        if ($text !== '' && ($problems['missing'] !== [] || $problems['unknown'] !== [])) {
            throw ValidationException::withMessages(['text' => $this->placeholderMessage($problems)]);
        }
        $t = Translation::query()->firstOrNew(['language' => $language, 'unit' => $unit]);
        // Any change needs fresh approvals.
        $t->fill(['source' => $u['source'], 'text' => $text === '' ? null : $text, 'medical' => $u['medical'], 'status' => $text === '' ? 'missing' : $status,
            'approved_by' => null, 'approved_at' => null, 'clinical_approved_by' => null, 'clinical_approved_at' => null, 'updated_by' => $user->id])->save();
        $this->publish($language, explode(':', $unit)[1]);

        return $t;
    }

    public function approve(User $user, string $language, string $unit): Translation
    {
        $this->mustReview($user, $language);
        $t = $this->current($language, $unit);
        $t->forceFill(['status' => 'approved', 'approved_by' => $user->id, 'approved_at' => now()])->save();
        activity('translation')->causedBy($user)->withProperties(['language' => $language, 'unit' => $unit])->log('Translation approved');
        $this->publish($language, explode(':', $unit)[1]);

        return $t;
    }

    public function approveClinical(User $user, string $language, string $unit): Translation
    {
        if (! TranslatorLanguage::query()->where(['user_id' => $user->id, 'language' => $language, 'clinical' => true])->exists()) {
            throw ValidationException::withMessages(['text' => 'Only a clinical reviewer for this language can approve medical wording.']);
        }
        $t = $this->current($language, $unit);
        if (! $t->medical) {
            throw ValidationException::withMessages(['text' => 'This text does not need a clinical approval.']);
        }
        $t->forceFill(['clinical_approved_by' => $user->id, 'clinical_approved_at' => now()])->save();
        activity('translation')->causedBy($user)->withProperties(['language' => $language, 'unit' => $unit])->log('Medical wording approved');
        $this->publish($language, explode(':', $unit)[1]);

        return $t;
    }

    /**
     * Stores an AI draft for a text that has no translation yet (never over a reviewer's work).
     * It is not live: a native speaker must review and approve it.
     */
    public function writeAiDraft(string $language, string $unit, string $text): bool
    {
        $u = TranslationUnits::all()[$unit] ?? null;
        $t = Translation::query()->where(['language' => $language, 'unit' => $unit])->first();
        if ($u === null || ($t instanceof Translation && $t->status !== 'missing')) {
            return false;
        }
        Translation::query()->updateOrCreate(['language' => $language, 'unit' => $unit],
            ['source' => $u['source'], 'text' => $text, 'medical' => $u['medical'], 'status' => 'ai_draft', 'approved_by' => null, 'approved_at' => null,
                'clinical_approved_by' => null, 'clinical_approved_at' => null, 'updated_by' => null]);

        return true;
    }

    public function canReview(User $user, string $language): bool
    {
        return TranslatorLanguage::query()->where(['user_id' => $user->id, 'language' => $language])->exists();
    }

    /**
     * Publishes a message's translations for one language — SMS on its own, email subject and body
     * only together — or removes them (falling back to English) if they are not fully live.
     */
    public function publish(string $language, string $key): void
    {
        $units = TranslationUnits::all();
        $live = function (string $part) use ($language, $key, $units): ?string {
            $unit = "message:{$key}:{$part}";
            if (! isset($units[$unit])) {
                return null;
            }
            $t = Translation::query()->where(['language' => $language, 'unit' => $unit])->first();

            return $t instanceof Translation && $t->isLive($units[$unit]['source']) ? (string) $t->text : null;
        };
        $where = ['key' => $key, 'language' => $language];
        $sms = $live('sms');
        $sms !== null ? MessageTemplate::query()->updateOrCreate($where + ['channel' => 'sms'], ['subject' => null, 'body' => $sms])
            : MessageTemplate::query()->where($where + ['channel' => 'sms'])->delete();
        $subject = $live('subject');
        $email = $live('email');
        $emailNeedsSubject = isset($units["message:{$key}:subject"]);
        ($email !== null && (! $emailNeedsSubject || $subject !== null))
            ? MessageTemplate::query()->updateOrCreate($where + ['channel' => 'email'], ['subject' => $subject, 'body' => $email])
            : MessageTemplate::query()->where($where + ['channel' => 'email'])->delete();
    }

    private function current(string $language, string $unit): Translation
    {
        $u = TranslationUnits::all()[$unit] ?? throw ValidationException::withMessages(['text' => 'Unknown text.']);
        $t = Translation::query()->where(['language' => $language, 'unit' => $unit])->first();
        if (! $t instanceof Translation || trim((string) $t->text) === '') {
            throw ValidationException::withMessages(['text' => 'Please write the translation first.']);
        }
        if ($t->source !== $u['source']) {
            throw ValidationException::withMessages(['text' => 'The English has changed since this was translated. Please check and save the translation again first.']);
        }

        return $t;
    }

    private function mustReview(User $user, string $language): void
    {
        if ($language === 'en' || ! $this->canReview($user, $language)) {
            throw ValidationException::withMessages(['text' => 'You are not a reviewer for this language.']);
        }
    }

    /** @param  array{missing: list<string>, unknown: list<string>}  $p */
    private function placeholderMessage(array $p): string
    {
        $parts = [];
        if ($p['missing'] !== []) {
            $parts[] = 'please keep '.implode(', ', array_map(fn ($n) => '{{ '.$n.' }}', $p['missing'])).' exactly as in the English';
        }
        if ($p['unknown'] !== []) {
            $parts[] = 'please remove '.implode(', ', array_map(fn ($n) => '{{ '.$n.' }}', $p['unknown'])).' (not in the English)';
        }

        return ucfirst(implode('; ', $parts)).'.';
    }
}
