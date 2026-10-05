<?php

declare(strict_types=1);

namespace App\Domains\Scribe\Providers;

use App\Domains\Scribe\Models\AiProvider;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Speech-to-text adapters. Each returns the transcript and the audio length the
 * provider measured (used for billing, not the length the browser claimed).
 */
final class SpeechToText
{
    /**
     * @return array{text: string, seconds: int}
     */
    public static function transcribe(AiProvider $provider, string $audio, string $mime): array
    {
        return match ($provider->driver) {
            'deepgram' => self::deepgram($provider, $audio, $mime),
            'azure' => self::azure($provider, $audio, $mime),
            default => throw new RuntimeException("Unknown speech provider {$provider->driver}."),
        };
    }

    /**
     * Deepgram Nova-3 Medical (pre-recorded audio).
     *
     * @return array{text: string, seconds: int}
     */
    private static function deepgram(AiProvider $provider, string $audio, string $mime): array
    {
        $response = Http::timeout(120)->withHeaders(['Authorization' => 'Token '.$provider->credential('api_key')])
            ->withBody($audio, $mime)
            ->post('https://api.deepgram.com/v1/listen?'.http_build_query(['model' => $provider->credential('model', 'nova-3-medical'), 'smart_format' => 'true', 'diarize' => 'true', 'language' => 'en']));
        if (! $response->successful()) {
            throw new RuntimeException('Speech-to-text failed (Deepgram HTTP '.$response->status().').');
        }

        return ['text' => trim((string) $response->json('results.channels.0.alternatives.0.transcript', '')), 'seconds' => (int) ceil((float) $response->json('metadata.duration', 0))];
    }

    /**
     * Azure AI Speech fast transcription (region, e.g. southafricanorth; South African English).
     *
     * @return array{text: string, seconds: int}
     */
    private static function azure(AiProvider $provider, string $audio, string $mime): array
    {
        $region = $provider->credential('region', 'southafricanorth');
        $response = Http::timeout(120)->withHeaders(['Ocp-Apim-Subscription-Key' => $provider->credential('api_key')])
            ->attach('audio', $audio, 'consult', ['Content-Type' => $mime])
            ->attach('definition', (string) json_encode(['locales' => [$provider->credential('locale', 'en-ZA')], 'diarization' => ['enabled' => true, 'maxSpeakers' => 3]]))
            ->post("https://{$region}.api.cognitive.microsoft.com/speechtotext/transcriptions:transcribe?api-version=2024-11-15");
        if (! $response->successful()) {
            throw new RuntimeException('Speech-to-text failed (Azure HTTP '.$response->status().').');
        }

        return ['text' => trim((string) $response->json('combinedPhrases.0.text', '')), 'seconds' => (int) ceil(((int) $response->json('durationMilliseconds', 0)) / 1000)];
    }
}
