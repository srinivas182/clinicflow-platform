<?php

declare(strict_types=1);

namespace App\Domains\Billing\Gateways;

final readonly class CheckoutRequest
{
    public function __construct(
        public int $amountCents,
        public string $reference,
        public string $description,
        public string $returnUrl,
        public string $cancelUrl,
        public string $notifyUrl,
        public string $email,
        public string $customerName = '',
    ) {}
}
