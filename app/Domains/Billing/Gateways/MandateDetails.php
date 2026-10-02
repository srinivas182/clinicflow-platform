<?php

declare(strict_types=1);

namespace App\Domains\Billing\Gateways;

/**
 * A reusable payment authority returned by a gateway after a card payment
 * the customer agreed to save. Holds the gateway's token only, never card data.
 */
final readonly class MandateDetails
{
    public function __construct(
        public string $token,
        public ?string $email = null,
        public ?string $brand = null,
        public ?string $last4 = null,
        public ?string $expiry = null,
    ) {}
}
