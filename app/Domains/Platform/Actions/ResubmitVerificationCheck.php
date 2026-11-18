<?php

declare(strict_types=1);

namespace App\Domains\Platform\Actions;

use App\Domains\Platform\Enums\VerificationStatus;
use App\Domains\Platform\Models\VerificationCheck;
use App\Domains\Platform\Notifications\PracticeNotice;
use App\Domains\Platform\Support\PracticeContacts;
use App\Models\User;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

/**
 * The practice corrects a rejected registration check (its reference number) and sends it back
 * for review; the platform team is emailed.
 */
class ResubmitVerificationCheck
{
    public function handle(VerificationCheck $check, string $reference, User $by): VerificationCheck
    {
        $reference = trim($reference);
        if ($check->status !== VerificationStatus::Rejected) {
            throw ValidationException::withMessages(['reference' => 'Only a rejected check can be corrected and sent back.']);
        }
        if ($reference === '' || mb_strlen($reference) > 80) {
            throw ValidationException::withMessages(['reference' => 'Enter the corrected number (up to 80 characters).']);
        }
        $check->forceFill(['reference' => $reference, 'status' => VerificationStatus::Pending, 'resubmitted_at' => now()])->save();
        activity('platform')->causedBy($by)->performedOn($check)->withProperties(['type' => $check->type->value])->log('Verification check resubmitted');

        $provider = $check->provider;
        Notification::send(PracticeContacts::platformAdmins(), new PracticeNotice(
            "{$provider->name} resubmitted {$check->type->label()}",
            ["{$provider->name} corrected its {$check->type->label()} and sent it back for review.", "Previous reason: {$check->notes}"],
            'Review it', rtrim((string) config('app.url'), '/')."/admin/providers/{$provider->id}", 'check_resubmitted',
        ));

        return $check;
    }
}
