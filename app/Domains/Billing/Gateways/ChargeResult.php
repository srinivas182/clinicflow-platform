<?php

declare(strict_types=1);

namespace App\Domains\Billing\Gateways;

final readonly class ChargeResult
{
    public function __construct(
        public bool $ok,
        public ?string $gatewayReference = null,
        public ?string $error = null,
    ) {}
}
