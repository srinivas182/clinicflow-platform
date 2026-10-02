<?php

declare(strict_types=1);

namespace App\Domains\Claims\Support;

final readonly class EligibilityResult
{
    /**
     * @param  array<string, mixed>  $raw
     */
    public function __construct(public string $status, public string $message, public array $raw = []) {}

    public function isActive(): bool
    {
        return $this->status === 'active';
    }
}
