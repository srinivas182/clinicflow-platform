<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Support;

use Illuminate\Support\Facades\DB;

/**
 * The patient's "who discussed my care / what was shared / who reviewed it" log, shown in the portal.
 */
final class AccessLog
{
    public static function record(string $patientId, string $kind, string $summary): void
    {
        DB::table('patient_access_log')->insert(['patient_id' => $patientId, 'kind' => $kind, 'summary' => mb_substr($summary, 0, 255), 'created_at' => now()]);
    }
}
