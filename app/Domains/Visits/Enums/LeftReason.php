<?php

declare(strict_types=1);

namespace App\Domains\Visits\Enums;

/**
 * Why a patient left the queue before being seen.
 */
enum LeftReason: string
{
    case LeftBeforeSeen = 'left_before_seen';
    case NoAnswerWhenCalled = 'no_answer';
    case SentElsewhere = 'sent_elsewhere';
    case EnteredInError = 'entered_in_error';
}
