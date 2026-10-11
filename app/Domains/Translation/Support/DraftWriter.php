<?php

declare(strict_types=1);

namespace App\Domains\Translation\Support;

use App\Domains\Scribe\Models\AiProvider;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * First-draft translations from the platform's AI provider (the same Claude set-up as the AI scribe).
 * Drafts are only a starting point: a native speaker must review and approve every one.
 */
final class DraftWriter
{
    private const SYSTEM = 'You translate short healthcare messages for South African patients. Translate each English text into the requested language '
        .'using plain, everyday words a patient with little schooling understands, as a native speaker from South Africa would say it. '
        .'Keep every placeholder written like {{ name }} exactly as it is, unchanged and untranslated. Keep SMS texts about as short as the English. '
        .'Do not add or remove information. Reply with JSON only: an object whose keys are the given ids and whose values are the translations.';

    /**
     * @param  array<string, string>  $texts  id => English
     * @return array<string, string> id => draft translation
     */
    public static function translate(AiProvider $provider, string $language, array $texts): array
    {
        $response = Http::timeout(120)->withHeaders(['x-api-key' => $provider->credential('api_key'), 'anthropic-version' => '2023-06-01'])
            ->post($provider->credential('base_url', 'https://api.anthropic.com').'/v1/messages', [
                'model' => $provider->credential('model', 'claude-sonnet-5-5'), 'max_tokens' => 4000, 'system' => self::SYSTEM,
                'messages' => [['role' => 'user', 'content' => "Language: {$language}\n\n".json_encode($texts, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)]],
            ]);
        if (! $response->successful()) {
            throw new RuntimeException('Draft translations could not be created (AI provider HTTP '.$response->status().').');
        }
        $text = trim((string) collect((array) $response->json('content', []))->where('type', 'text')->pluck('text')->implode(''));
        $text = (string) preg_replace('/^```(?:json)?\s*|\s*```$/', '', $text);
        $drafts = json_decode($text, true);

        return is_array($drafts) ? array_filter(array_map(fn ($v) => is_string($v) ? trim($v) : '', $drafts), fn ($v, $k) => is_string($k) && $v !== '', ARRAY_FILTER_USE_BOTH) : [];
    }
}
