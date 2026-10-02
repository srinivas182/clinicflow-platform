<?php

declare(strict_types=1);

namespace App\Domains\Prescribing\Support;

/**
 * One safety finding on a draft script.
 *  - block: must be fixed (allergy, schedule repeat limit); cannot be overridden.
 *  - override: may be accepted with a written reason (interaction, duplicate).
 */
final readonly class SafetyIssue
{
    public function __construct(
        public int $itemId,
        public string $type,
        public string $level,
        public string $message,
    ) {}

    /**
     * @return array{itemId: int, type: string, level: string, message: string}
     */
    public function toArray(): array
    {
        return ['itemId' => $this->itemId, 'type' => $this->type, 'level' => $this->level, 'message' => $this->message];
    }
}
