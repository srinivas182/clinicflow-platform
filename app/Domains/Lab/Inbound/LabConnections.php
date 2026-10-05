<?php

declare(strict_types=1);

namespace App\Domains\Lab\Inbound;

use App\Domains\Api\Actions\ApiKeys;
use App\Domains\Lab\Actions\LabCatalog;
use App\Domains\Platform\Models\Setting;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Which connected lab system receives this practice's orders, and each lab
 * system's own test codes mapped to the practice's catalogue codes.
 */
class LabConnections
{
    public function outgoingKey(): ?int
    {
        $id = Setting::get('lab', 'outgoing_key');

        return is_numeric($id) && DB::table('api_keys')->where('id', (int) $id)->whereNull('revoked_at')->exists() ? (int) $id : null;
    }

    public function setOutgoingKey(?int $keyId): void
    {
        if ($keyId !== null) {
            $key = DB::table('api_keys')->where('id', $keyId)->whereNull('revoked_at')->first();
            if ($key === null || ! ApiKeys::allows($key, 'lab:orders')) {
                throw ValidationException::withMessages(['key_id' => 'Choose a lab system key with the lab:orders permission.']);
            }
        }
        Setting::put('lab', 'outgoing_key', $keyId);
    }

    public function map(int $keyId, string $externalCode, string $testCode): void
    {
        $externalCode = strtoupper(trim($externalCode));
        $testCode = strtoupper(trim($testCode));
        if ($externalCode === '' || app(LabCatalog::class)->resolve($testCode) === null) {
            throw ValidationException::withMessages(['test_code' => 'Choose a test from your lab catalogue and enter the lab system\'s code.']);
        }
        DB::table('lab_code_maps')->updateOrInsert(['api_key_id' => $keyId, 'external_code' => $externalCode], ['test_code' => $testCode, 'updated_at' => now(), 'created_at' => now()]);
    }

    public function unmap(int $id): void
    {
        DB::table('lab_code_maps')->where('id', $id)->delete();
    }

    /** Lab system code → catalogue code (unchanged when not mapped). */
    public function toCatalog(?int $keyId, string $code): string
    {
        if ($keyId === null) {
            return $code;
        }
        $mapped = DB::table('lab_code_maps')->where('api_key_id', $keyId)->where('external_code', strtoupper($code))->value('test_code');

        return is_string($mapped) ? $mapped : $code;
    }

    /** Catalogue code → lab system code (unchanged when not mapped). */
    public function toExternal(int $keyId, string $testCode): string
    {
        $mapped = DB::table('lab_code_maps')->where('api_key_id', $keyId)->where('test_code', strtoupper($testCode))->value('external_code');

        return is_string($mapped) ? $mapped : $testCode;
    }
}
