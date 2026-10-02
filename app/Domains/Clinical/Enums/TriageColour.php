<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Enums;

/**
 * South African Triage Scale colour. Lower priority() is seen first.
 */
enum TriageColour: string
{
    case Red = 'red';
    case Orange = 'orange';
    case Yellow = 'yellow';
    case Green = 'green';

    public function priority(): int
    {
        return match ($this) {
            self::Red => 0,
            self::Orange => 1,
            self::Yellow => 2,
            self::Green => 3,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Red => 'Red — emergency',
            self::Orange => 'Orange — very urgent',
            self::Yellow => 'Yellow — urgent',
            self::Green => 'Green — routine',
        };
    }
}
