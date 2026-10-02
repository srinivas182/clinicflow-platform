<?php

declare(strict_types=1);

namespace App\Domains\Claims\Support;

final readonly class SubmissionResult
{
    /**
     * @param  array<string, mixed>  $raw
     */
    public function __construct(public bool $accepted, public ?string $reference, public ?string $reason = null, public array $raw = []) {}
}
