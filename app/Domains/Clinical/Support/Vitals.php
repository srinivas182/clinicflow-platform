<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Support;

final readonly class Vitals
{
    public function __construct(
        public int $systolic,
        public int $diastolic,
        public int $pulse,
        public float $temperature,
        public ?int $spo2 = null,
        public ?int $respRate = null,
        public ?float $glucose = null,
        public ?float $weightKg = null,
        public ?int $pain = null,
    ) {}
}
