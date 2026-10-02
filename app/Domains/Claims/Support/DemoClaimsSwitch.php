<?php

declare(strict_types=1);

namespace App\Domains\Claims\Support;

use App\Domains\Claims\Contracts\ClaimsSwitch;
use Illuminate\Support\Str;

/**
 * Deterministic test switch (development, testing and demos only):
 *  - member numbers ending in 0000 are inactive;
 *  - claims are rejected for inactive members or lines without an ICD-10 code;
 *  - everything else is accepted.
 */
class DemoClaimsSwitch implements ClaimsSwitch
{
    public function name(): string
    {
        return 'demo';
    }

    public function checkEligibility(string $scheme, string $memberNumber, ?string $dependantCode): EligibilityResult
    {
        if (str_ends_with($memberNumber, '0000')) {
            return new EligibilityResult('inactive', "{$scheme}: membership not active on the date of service.", ['code' => 'M01']);
        }

        return new EligibilityResult('active', "{$scheme}: member active".($dependantCode ? ", dependant {$dependantCode}" : '').'.', ['code' => '00', 'benefits' => 'Day-to-day available']);
    }

    public function submit(ClaimSubmission $claim): SubmissionResult
    {
        if (str_ends_with($claim->memberNumber, '0000')) {
            return new SubmissionResult(false, null, 'Member not active on the date of service.', ['code' => 'M01']);
        }

        foreach ($claim->lines as $line) {
            if ($line['icd10_codes'] === []) {
                return new SubmissionResult(false, null, "ICD-10 code missing on '{$line['description']}'.", ['code' => 'D01']);
            }
        }

        return new SubmissionResult(true, 'DEMO-'.Str::upper(Str::random(10)), null, ['code' => '00']);
    }

    /**
     * Pays in full, except member numbers ending in 9999, which are paid at 80%
     * (the shortfall becomes the patient's co-payment).
     */
    public function fetchRemittances(array $outstanding): array
    {
        return array_map(fn (array $c) => new RemittanceLine(
            $c['reference'],
            'RA-'.substr($c['reference'], -8),
            str_ends_with($c['member_number'], '9999') ? (int) round($c['total_cents'] * 0.8) : $c['total_cents'],
            str_ends_with($c['member_number'], '9999') ? 'Paid at scheme rate; balance is a member co-payment.' : 'Paid in full.',
        ), $outstanding);
    }
}
