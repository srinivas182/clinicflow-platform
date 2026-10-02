<?php

declare(strict_types=1);

namespace App\Domains\Billing\Gateways;

/**
 * Where to send the payer: a redirect URL, or (PayFast) a form to auto-submit.
 */
final readonly class CheckoutStart
{
    /**
     * @param  array<string, string>  $formFields
     */
    public function __construct(
        public ?string $redirectUrl = null,
        public ?string $formAction = null,
        public array $formFields = [],
        public ?string $gatewayReference = null,
    ) {}
}
