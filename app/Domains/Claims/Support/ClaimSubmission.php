<?php

declare(strict_types=1);

namespace App\Domains\Claims\Support;

/**
 * What is sent to the switch for one claim.
 */
final readonly class ClaimSubmission
{
    /**
     * @param  array<int, array{tariff_code: ?string, nappi_code: ?string, icd10_codes: list<string>, description: string, quantity: int, amount_cents: int}>  $lines
     */
    public function __construct(
        public string $claimId,
        public string $practiceNumber,
        public string $scheme,
        public string $memberNumber,
        public ?string $dependantCode,
        public string $patientName,
        public string $dateOfService,
        public int $totalCents,
        public array $lines,
    ) {}
}
