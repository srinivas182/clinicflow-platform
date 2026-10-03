<?php

declare(strict_types=1);

namespace App\Domains\Telemedicine\Support;

use App\Domains\Telemedicine\Models\VideoConfig;
use Illuminate\Support\Facades\Http;

/**
 * Minimal LiveKit client: HS256 access tokens (join passes and server-API
 * tokens), the RoomService API over HTTP, and webhook verification.
 * Works the same for LiveKit Cloud and self-hosted servers.
 */
final class LiveKit
{
    public function __construct(private readonly VideoConfig $config) {}

    public static function active(): ?self
    {
        $config = VideoConfig::active();

        return $config !== null && $config->isComplete() ? new self($config) : null;
    }

    public function url(): string
    {
        return (string) $this->config->url;
    }

    /**
     * Join pass for one participant in one room, valid for the given minutes.
     */
    public function joinToken(string $room, string $identity, string $name, int $validMinutes = 120): string
    {
        return $this->jwt([
            'sub' => $identity,
            'name' => $name,
            'video' => ['room' => $room, 'roomJoin' => true, 'canPublish' => true, 'canSubscribe' => true, 'canPublishData' => true],
        ], $validMinutes * 60);
    }

    /**
     * @return array{ok: bool, error: ?string}
     */
    public function createRoom(string $room): array
    {
        return $this->call('CreateRoom', ['name' => $room, 'empty_timeout' => 600, 'max_participants' => 2]);
    }

    /**
     * Creates and removes a test room.
     *
     * @return array{ok: bool, error: ?string}
     */
    public function testConnection(): array
    {
        $room = 'cf-connection-test-'.bin2hex(random_bytes(4));
        $created = $this->createRoom($room);
        if (! $created['ok']) {
            return $created;
        }

        return $this->call('DeleteRoom', ['room' => $room]);
    }

    /**
     * Verifies a LiveKit webhook: the Authorization header is a JWT signed with
     * our secret whose "sha256" claim is the base64 SHA-256 of the body.
     */
    public function verifyWebhook(string $body, ?string $authorization): bool
    {
        if ($authorization === null || $authorization === '') {
            return false;
        }
        $token = str_starts_with($authorization, 'Bearer ') ? substr($authorization, 7) : $authorization;
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return false;
        }

        $expected = self::b64(hash_hmac('sha256', $parts[0].'.'.$parts[1], (string) $this->config->api_secret, true));
        if (! hash_equals($expected, $parts[2])) {
            return false;
        }

        $claims = json_decode((string) base64_decode(strtr($parts[1], '-_', '+/')), true);
        if (! is_array($claims) || ($claims['iss'] ?? null) !== $this->config->api_key || (isset($claims['exp']) && (int) $claims['exp'] < time())) {
            return false;
        }

        return hash_equals((string) ($claims['sha256'] ?? ''), base64_encode(hash('sha256', $body, true)));
    }

    /**
     * @param  array<string, mixed>  $claims
     */
    public function jwt(array $claims, int $ttlSeconds = 600): string
    {
        $now = time();
        $payload = ['iss' => $this->config->api_key, 'nbf' => $now - 5, 'exp' => $now + $ttlSeconds, ...$claims];
        $head = self::b64((string) json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
        $body = self::b64((string) json_encode($payload));

        return $head.'.'.$body.'.'.self::b64(hash_hmac('sha256', $head.'.'.$body, (string) $this->config->api_secret, true));
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{ok: bool, error: ?string}
     */
    private function call(string $method, array $payload): array
    {
        $base = (string) preg_replace('#^ws#', 'http', rtrim($this->url(), '/'));
        try {
            $response = Http::withToken($this->jwt(['video' => ['roomCreate' => true, 'roomList' => true, 'roomAdmin' => true]], 60))
                ->acceptJson()->timeout(10)->post("{$base}/twirp/livekit.RoomService/{$method}", $payload);
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }

        return $response->successful() ? ['ok' => true, 'error' => null] : ['ok' => false, 'error' => "LiveKit returned HTTP {$response->status()}."];
    }

    private static function b64(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }
}
