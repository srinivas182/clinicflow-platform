<?php

declare(strict_types=1);

namespace App\Domains\Wallet\Support;

use Illuminate\Support\Facades\DB;

/**
 * Super-admin wallet rules (platform_settings): usage prices, default
 * threshold and top-up packs. Prices exclude VAT; top-ups are invoiced with VAT.
 */
final class WalletSettings
{
    public const DEFAULTS = [
        'wallet.price_video_per_minute_cents' => 250,
        'wallet.price_audio_per_minute_cents' => 120,
        'wallet.price_chat_per_session_cents' => 1200,
        'wallet.threshold_cents' => 15000,
        'wallet.packs' => [['amount' => 50000, 'bonus' => 0], ['amount' => 100000, 'bonus' => 5000], ['amount' => 250000, 'bonus' => 20000]],
    ];

    public static function get(string $key): mixed
    {
        $row = DB::connection((string) config('tenancy.database.central_connection'))->table('platform_settings')->where('key', $key)->value('value');

        return $row === null ? self::DEFAULTS[$key] ?? null : json_decode((string) $row, true);
    }

    public static function put(string $key, mixed $value): void
    {
        DB::connection((string) config('tenancy.database.central_connection'))->table('platform_settings')
            ->updateOrInsert(['key' => $key], ['value' => json_encode($value), 'updated_at' => now(), 'created_at' => now()]);
    }

    public static function thresholdCents(): int
    {
        return (int) self::get('wallet.threshold_cents');
    }

    /**
     * Price for one online consult: per minute for video/audio, per session for chat.
     */
    public static function priceCents(string $consultType, int $minutes = 1): int
    {
        return match ($consultType) {
            'video' => (int) self::get('wallet.price_video_per_minute_cents') * max(1, $minutes),
            'audio' => (int) self::get('wallet.price_audio_per_minute_cents') * max(1, $minutes),
            'chat' => (int) self::get('wallet.price_chat_per_session_cents'),
            default => 0,
        };
    }

    /**
     * @return list<array{amount: int, bonus: int}>
     */
    public static function packs(): array
    {
        $packs = self::get('wallet.packs');

        return is_array($packs) ? array_values(array_map(fn ($p) => ['amount' => (int) ($p['amount'] ?? 0), 'bonus' => (int) ($p['bonus'] ?? 0)], $packs)) : [];
    }
}
