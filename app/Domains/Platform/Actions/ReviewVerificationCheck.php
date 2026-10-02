<?php

declare(strict_types=1);

namespace App\Domains\Platform\Actions;

use App\Domains\Platform\Enums\VerificationStatus;
use App\Domains\Platform\Models\VerificationCheck;
use App\Models\User;

/**
 * Super admin records the outcome of one registration check.
 */
class ReviewVerificationCheck
{
    public function handle(VerificationCheck $check, VerificationStatus $status, User $reviewer, ?string $notes = null): VerificationCheck
    {
        $check->forceFill([
            'status' => $status,
            'notes' => $notes,
            'reviewed_by' => $reviewer->id,
            'reviewed_at' => now(),
        ])->save();

        activity('platform')->causedBy($reviewer)->performedOn($check)
            ->withProperties(['type' => $check->type->value, 'status' => $status->value])
            ->log('Verification check reviewed');

        return $check;
    }
}
