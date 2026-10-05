<?php

declare(strict_types=1);

namespace App\Domains\Api\Fhir;

use App\Domains\Api\Actions\ApiKeys;
use App\Domains\Clinical\Support\AccessLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * A connected system (API key with fhir:read) may read a patient's record only
 * with that patient's consent for that system, and only the chosen categories.
 * Clinical notes are never available. Patients see every read on "My care".
 */
class FhirConsents
{
    public const CATEGORIES = [
        'allergies' => 'Allergies',
        'problems' => 'Problem list',
        'medicines' => 'Prescribed medicines',
        'immunisations' => 'Immunisations',
        'results' => 'Released lab results',
    ];

    /**
     * @param  list<string>  $categories
     */
    public function grant(string $patientId, int $keyId, array $categories, string $via, ?int $by, ?string $expiresAt = null): int
    {
        $categories = array_values(array_intersect(array_keys(self::CATEGORIES), $categories));
        $key = DB::table('api_keys')->where('id', $keyId)->whereNull('revoked_at')->first();
        if ($categories === [] || $key === null || ! ApiKeys::allows($key, 'fhir:read') || ! in_array($via, ['portal', 'staff'], true)) {
            throw ValidationException::withMessages(['categories' => 'Choose a connected system and at least one part of the record to share.']);
        }
        if ($expiresAt !== null && now()->gte($expiresAt)) {
            throw ValidationException::withMessages(['expires_at' => 'Choose a future date.']);
        }
        // One active consent per patient and system: a new grant replaces the old one.
        DB::table('fhir_consents')->where('patient_id', $patientId)->where('api_key_id', $keyId)->whereNull('revoked_at')->update(['revoked_at' => now(), 'updated_at' => now()]);
        $id = (int) DB::table('fhir_consents')->insertGetId(['patient_id' => $patientId, 'api_key_id' => $keyId, 'categories' => json_encode($categories),
            'granted_via' => $via, 'granted_by' => $by, 'expires_at' => $expiresAt, 'created_at' => now(), 'updated_at' => now()]);
        AccessLog::record($patientId, 'shared', 'You allowed "'.$key->name.'" to read: '.implode(', ', array_map(fn ($c) => self::CATEGORIES[$c], $categories)));

        return $id;
    }

    public function revoke(string $patientId, int $keyId): void
    {
        $n = DB::table('fhir_consents')->where('patient_id', $patientId)->where('api_key_id', $keyId)->whereNull('revoked_at')->update(['revoked_at' => now(), 'updated_at' => now()]);
        if ($n > 0) {
            AccessLog::record($patientId, 'shared', 'Sharing with "'.DB::table('api_keys')->where('id', $keyId)->value('name').'" was withdrawn');
        }
    }

    /**
     * @return list<string> categories this key may read for this patient now (empty = none)
     */
    public function allowed(string $patientId, int $keyId): array
    {
        $row = DB::table('fhir_consents')->where('patient_id', $patientId)->where('api_key_id', $keyId)->whereNull('revoked_at')
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))->latest('id')->first();

        return $row === null ? [] : array_values(array_map('strval', (array) json_decode((string) $row->categories, true)));
    }

    /**
     * @return list<string> patient ids with an active consent for this key
     */
    public function patientsFor(int $keyId): array
    {
        return array_values(DB::table('fhir_consents')->where('api_key_id', $keyId)->whereNull('revoked_at')
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))->distinct()->pluck('patient_id')->map(fn ($v) => (string) $v)->all());
    }
}
