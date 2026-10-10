<?php

declare(strict_types=1);

namespace App\Domains\Billing\Gateways;

/**
 * A verified gateway notification. `reference` is Dr Business Flow's own reference
 * (checkout token); amounts are always re-checked against our records.
 */
final readonly class WebhookResult
{
    public function __construct(
        public string $reference,
        public bool $paid,
        public ?int $amountCents = null,
        public ?string $gatewayReference = null,
        public ?MandateDetails $mandate = null,
    ) {}
}
