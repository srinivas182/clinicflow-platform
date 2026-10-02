<?php

declare(strict_types=1);

namespace App\Domains\Billing\Support;

final readonly class GatewayResult
{
    public function __construct(
        public bool $ok,
        public ?string $reference = null,
        public ?string $url = null,
        public ?string $error = null,
    ) {}
}
