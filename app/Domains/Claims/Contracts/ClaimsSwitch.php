<?php

declare(strict_types=1);

namespace App\Domains\Claims\Contracts;

use App\Domains\Claims\Support\ClaimSubmission;
use App\Domains\Claims\Support\EligibilityResult;
use App\Domains\Claims\Support\RemittanceLine;
use App\Domains\Claims\Support\SubmissionResult;

/**
 * Medical aid switching house (e.g. MediKredit, Healthbridge, Altron
 * HealthTech). The demo switch is used until the client chooses one.
 */
interface ClaimsSwitch
{
    public function name(): string;

    public function checkEligibility(string $scheme, string $memberNumber, ?string $dependantCode): EligibilityResult;

    public function submit(ClaimSubmission $claim): SubmissionResult;

    /**
     * Remittance advice for accepted claims. Real switches deliver remittance
     * files; the outstanding list lets the demo switch answer deterministically.
     *
     * @param  array<int, array{reference: string, total_cents: int, member_number: string}>  $outstanding
     * @return list<RemittanceLine>
     */
    public function fetchRemittances(array $outstanding): array;
}
