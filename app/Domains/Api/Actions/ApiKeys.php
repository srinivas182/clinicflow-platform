<?php

declare(strict_types=1);

namespace App\Domains\Api\Actions;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\IpUtils;

/**
 * Practice API keys. The full key is shown once at creation; only its SHA-256
 * hash is stored. Each key carries explicit scopes; clinical data is never
 * available through this API.
 */
class ApiKeys
{
    public const SCOPES = [
        'availability:read' => 'Free appointment times',
        'appointments:read' => 'Appointments (times, doctor, status — no reasons)',
        'patients:read' => 'Patient lookup by cell or ID number (demographics only)',
        'invoices:read' => 'Invoices (status and totals)',
        'prices:read' => 'Online consult prices and prepaid packages',
        'appointments:write' => 'Book, reschedule and cancel appointments',
        'patients:write' => 'Register patients (with consent obtained by your system)',
    ];

    /**
     * @param  list<string>  $scopes
     * @param  list<string>  $ips
     * @return array{id: int, key: string}
     */
    public function create(string $name, array $scopes, array $ips, ?string $expiresAt, int $by): array
    {
        $scopes = array_values(array_intersect(array_keys(self::SCOPES), $scopes));
        if (trim($name) === '' || $scopes === []) {
            throw ValidationException::withMessages(['scopes' => 'Name the key and choose at least one permission.']);
        }
        foreach ($ips as $ip) {
            if (filter_var(explode('/', $ip)[0], FILTER_VALIDATE_IP) === false) {
                throw ValidationException::withMessages(['allowed_ips' => "{$ip} is not a valid IP address or range."]);
            }
        }
        if ($expiresAt !== null && now()->gte($expiresAt)) {
            throw ValidationException::withMessages(['expires_at' => 'Choose a future expiry date.']);
        }
        $key = 'cf_live_'.Str::random(40);
        $id = (int) DB::table('api_keys')->insertGetId([
            'name' => trim($name), 'prefix' => substr($key, 0, 14), 'key_hash' => hash('sha256', $key), 'scopes' => json_encode($scopes),
            'allowed_ips' => $ips === [] ? null : json_encode($ips), 'expires_at' => $expiresAt, 'created_by' => $by, 'created_at' => now(), 'updated_at' => now(),
        ]);
        activity('api')->withProperties(['key' => $id, 'scopes' => $scopes])->log('API key created');

        return ['id' => $id, 'key' => $key];
    }

    public function revoke(int $id): void
    {
        DB::table('api_keys')->where('id', $id)->whereNull('revoked_at')->update(['revoked_at' => now(), 'updated_at' => now()]);
        activity('api')->withProperties(['key' => $id])->log('API key revoked');
    }

    /**
     * The active key for a presented token from this IP, or null.
     */
    public function authenticate(string $token, ?string $ip): ?\stdClass
    {
        if (! str_starts_with($token, 'cf_live_')) {
            return null;
        }
        $key = DB::table('api_keys')->where('key_hash', hash('sha256', $token))->whereNull('revoked_at')
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))->first();
        if ($key === null) {
            return null;
        }
        $allowed = $key->allowed_ips === null ? [] : (array) json_decode((string) $key->allowed_ips, true);
        if ($allowed !== [] && ($ip === null || ! IpUtils::checkIp($ip, array_map('strval', $allowed)))) {
            return null;
        }

        return $key;
    }

    public static function allows(\stdClass $key, string $scope): bool
    {
        return in_array($scope, (array) json_decode((string) $key->scopes, true), true);
    }
}
