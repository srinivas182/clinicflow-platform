<?php

declare(strict_types=1);

namespace App\Domains\Translation\Jobs;

use App\Domains\Scribe\Models\AiProvider;
use App\Domains\Translation\Actions\Translations;
use App\Domains\Translation\Models\Language;
use App\Domains\Translation\Models\Translation;
use App\Domains\Translation\Support\DraftWriter;
use App\Domains\Translation\Support\TranslationUnits;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Fills texts that have no translation yet with AI drafts ("AI draft — needs review"). Never touches
 * a reviewer's draft or an approved translation. A draft that loses or adds a placeholder is discarded.
 */
class CreateDraftTranslations implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $timeout = 600;

    public function __construct(public string $language, public ?int $requestedBy = null) {}

    public function handle(Translations $translations): void
    {
        $provider = AiProvider::active('notes');
        $language = Language::query()->find($this->language);
        if ($provider === null || ! $language instanceof Language || $this->language === 'en') {
            return;
        }
        $translations->sync($this->language);
        $units = TranslationUnits::all();
        $missing = Translation::query()->where(['language' => $this->language, 'status' => 'missing'])->pluck('unit')
            ->filter(fn (string $unit) => isset($units[$unit]))->values();
        $saved = 0;
        $discarded = 0;
        foreach ($missing->chunk(10) as $chunk) {
            $texts = $chunk->mapWithKeys(fn (string $unit) => [$unit => $units[$unit]['source']])->all();
            foreach (DraftWriter::translate($provider, "{$language->name} ({$language->native_name})", $texts) as $unit => $draft) {
                if (! isset($texts[$unit])) {
                    continue;
                }
                $problems = TranslationUnits::placeholderProblems($units[$unit]['source'], $draft);
                if ($problems['missing'] !== [] || $problems['unknown'] !== []) {
                    $discarded++;

                    continue;
                }
                $saved += $translations->writeAiDraft($this->language, $unit, $draft) ? 1 : 0;
            }
        }
        activity('translation')->withProperties(['language' => $this->language, 'drafts' => $saved, 'discarded' => $discarded, 'requested_by' => $this->requestedBy])
            ->log('AI draft translations created');
    }
}
