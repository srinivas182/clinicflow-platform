<?php

declare(strict_types=1);

namespace App\Domains\Scribe\Providers;

use App\Domains\Scribe\Models\AiProvider;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Drafts a consultation note from a transcript with Claude. The draft is only
 * ever a suggestion for the doctor to review; it is never saved or signed by itself.
 */
final class NoteWriter
{
    public const SYSTEM = 'You draft clinical consultation notes for a South African general practice from a doctor-patient conversation transcript. '
        .'Write only what was said; never invent findings, doses, results or diagnoses. If something is unclear, write "[unclear — check]". '
        .'Use concise clinical English. Suggest ICD-10 codes only for conditions the doctor clearly stated. '
        .'Reply with JSON only: {"history": string, "examination": string, "assessment": string, "plan": string, "icd10": [{"code": string, "description": string}]}.';

    public const EXPLAIN = 'You write short, plain-language explanations of lab results for a patient in South Africa, to be reviewed and edited by their doctor before release. '
        .'Use simple English (about a grade 8 reading level), warm and calm, no more than 150 words. Explain what each test measures and whether the result is in the usual range. '
        .'Do not diagnose, do not suggest treatment or medicine changes, and do not add anything that is not in the results or the doctor\'s comment. '
        .'If the doctor gave a comment, reflect it. End with: "Please speak to your doctor if you have any questions." Reply with the explanation text only.';

    /**
     * Plain-language explanation of released lab results (draft for the doctor).
     *
     * @param  array<string, mixed>  $payload
     */
    public static function explain(AiProvider $provider, array $payload): string
    {
        $response = Http::timeout(60)->withHeaders(['x-api-key' => $provider->credential('api_key'), 'anthropic-version' => '2023-06-01'])
            ->post($provider->credential('base_url', 'https://api.anthropic.com').'/v1/messages', [
                'model' => $provider->credential('summary_model', 'claude-haiku-4-5'), 'max_tokens' => 600, 'system' => self::EXPLAIN,
                'messages' => [['role' => 'user', 'content' => (string) json_encode($payload, JSON_PRETTY_PRINT)]],
            ]);
        if (! $response->successful()) {
            throw new RuntimeException('The explanation could not be written (Claude HTTP '.$response->status().').');
        }
        $text = trim((string) collect((array) $response->json('content', []))->where('type', 'text')->pluck('text')->implode(''));
        if ($text === '') {
            throw new RuntimeException('The explanation came back empty. Try again.');
        }

        return $text;
    }

    /**
     * @return array{history: string, examination: string, assessment: string, plan: string, icd10: list<array{code: string, description: string}>}
     */
    public static function draft(AiProvider $provider, string $transcript): array
    {
        if ($provider->driver !== 'anthropic') {
            throw new RuntimeException("Unknown note provider {$provider->driver}.");
        }
        $response = Http::timeout(90)->withHeaders(['x-api-key' => $provider->credential('api_key'), 'anthropic-version' => '2023-06-01'])
            ->post($provider->credential('base_url', 'https://api.anthropic.com').'/v1/messages', [
                'model' => $provider->credential('model', 'claude-sonnet-5-5'), 'max_tokens' => 2000, 'system' => self::SYSTEM,
                'messages' => [['role' => 'user', 'content' => "Transcript:\n".$transcript]],
            ]);
        if (! $response->successful()) {
            throw new RuntimeException('Draft note failed (Claude HTTP '.$response->status().').');
        }
        $text = (string) collect((array) $response->json('content', []))->where('type', 'text')->pluck('text')->implode('');
        $json = json_decode((string) preg_replace('/^```(?:json)?\s*|\s*```$/', '', trim($text)), true);
        if (! is_array($json)) {
            throw new RuntimeException('The draft note could not be read. Try again.');
        }

        return [
            'history' => (string) ($json['history'] ?? ''), 'examination' => (string) ($json['examination'] ?? ''),
            'assessment' => (string) ($json['assessment'] ?? ''), 'plan' => (string) ($json['plan'] ?? ''),
            'icd10' => array_values(array_filter(array_map(fn ($c) => is_array($c) && preg_match('/^[A-Z][0-9]{2}(\.[0-9A-Z]{1,4})?$/', (string) ($c['code'] ?? '')) === 1
                ? ['code' => (string) $c['code'], 'description' => (string) ($c['description'] ?? '')] : null, (array) ($json['icd10'] ?? [])))),
        ];
    }
}
