<?php

declare(strict_types=1);

namespace App\Domains\Claims\Support;

final readonly class RemittanceLine
{
    public function __construct(public string $switchReference, public string $schemeReference, public int $paidCents, public ?string $message = null) {}
}
