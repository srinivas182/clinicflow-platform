<?php

declare(strict_types=1);

namespace App\Domains\Visits\Enums;

/**
 * Visit lifecycle (prospect logic flow + check-in stage). Every visit ends in
 * Done or Left. Two paths loop back to the doctor: a pharmacist query and a
 * partner pharmacy that cannot fill the script.
 */
enum VisitStage: string
{
    case CheckedIn = 'checked_in';
    case Triage = 'triage';
    case Doctor = 'doctor';
    case Pharmacy = 'pharmacy';
    case Dispatch = 'dispatch';
    case Done = 'done';
    case Left = 'left';

    /**
     * @return list<self>
     */
    public function allowedNext(): array
    {
        return match ($this) {
            self::CheckedIn => [self::Triage, self::Left],
            self::Triage => [self::Doctor, self::Left],
            self::Doctor => [self::Pharmacy, self::Dispatch, self::Done, self::Left],
            self::Pharmacy => [self::Doctor, self::Dispatch],
            self::Dispatch => [self::Doctor, self::Done],
            self::Done, self::Left => [],
        };
    }

    public function canMoveTo(self $next): bool
    {
        return in_array($next, $this->allowedNext(), true);
    }

    public function isWaiting(): bool
    {
        return in_array($this, [self::CheckedIn, self::Triage, self::Doctor], true);
    }

    public function isClosed(): bool
    {
        return in_array($this, [self::Done, self::Left], true);
    }

    public function label(): string
    {
        return match ($this) {
            self::CheckedIn => 'Checked in',
            self::Triage => 'Waiting for triage',
            self::Doctor => 'Waiting for doctor',
            self::Pharmacy => 'Pharmacy',
            self::Dispatch => 'Dispatch',
            self::Done => 'Done',
            self::Left => 'Left the queue',
        };
    }
}
