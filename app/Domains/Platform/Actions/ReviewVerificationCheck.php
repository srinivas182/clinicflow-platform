<?php

declare(strict_types=1);

namespace App\Domains\Platform\Actions;

use App\Domains\Platform\Enums\ProviderStatus;
use App\Domains\Platform\Enums\VerificationStatus;
use App\Domains\Platform\Enums\VerificationType;
use App\Domains\Platform\Models\Provider;
use App\Domains\Platform\Models\VerificationCheck;
use App\Domains\Platform\Notifications\PracticeNotice;
use App\Domains\Platform\Support\PracticeContacts;
use App\Models\User;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

/**
 * Super admin records the outcome of one registration check. A rejection needs a reason, which the
 * practice sees and is emailed. Rejecting a required check of a live practice can suspend it
 * (read-only) until the check is verified again, when its earlier status returns.
 */
class ReviewVerificationCheck
{
    public function handle(VerificationCheck $check, VerificationStatus $status, User $reviewer, ?string $notes = null, bool $suspend = false): VerificationCheck
    {
        $notes = $notes === null ? null : trim($notes);
        if ($status === VerificationStatus::Rejected && ($notes === null || $notes === '')) {
            throw ValidationException::withMessages(['notes' => 'Give the reason for rejecting, so the practice knows what to fix.']);
        }

        $check->forceFill([
            'status' => $status,
            'notes' => $notes,
            'reviewed_by' => $reviewer->id,
            'reviewed_at' => now(),
            'resubmitted_at' => null,
        ])->save();

        activity('platform')->causedBy($reviewer)->performedOn($check)
            ->withProperties(['type' => $check->type->value, 'status' => $status->value])
            ->log('Verification check reviewed');

        $provider = $check->provider;
        if ($status === VerificationStatus::Rejected) {
            $this->rejected($check, $provider, $reviewer, $suspend);
        } elseif ($status === VerificationStatus::Verified) {
            $this->restoreIfCleared($provider, $reviewer);
        }

        return $check;
    }

    private function rejected(VerificationCheck $check, Provider $provider, User $reviewer, bool $suspend): void
    {
        $required = in_array($check->type, VerificationType::requiredFor($provider->type), true);
        $suspended = false;
        if ($suspend && $required && $provider->status->isLive()) {
            $provider->setAttribute('suspended_from', $provider->status->value);
            $provider->status = ProviderStatus::Suspended;
            $provider->save();
            activity('platform')->causedBy($reviewer)->performedOn($provider)->withProperties(['check' => $check->type->value])->log('Practice suspended: registration check rejected');
            $suspended = true;
        }

        Notification::send(PracticeContacts::owners($provider->id), new PracticeNotice(
            "Action needed: {$check->type->label()} for {$provider->name}",
            array_values(array_filter([
                "We could not verify your {$check->type->label()}.",
                'Reason: '.$check->notes,
                $suspended ? 'Until this is fixed your workspace is read-only and you are not listed in the directory. Your data is safe.' : null,
                'Correct it in Settings → Verification and send it back for review.',
            ])),
            'Fix and resubmit', PracticeContacts::url($provider, '/settings/verification'), 'check_rejected',
        ));
    }

    /** A practice suspended over a check gets its earlier status back once every required check is verified. */
    private function restoreIfCleared(Provider $provider, User $reviewer): void
    {
        $previous = $provider->getAttribute('suspended_from');
        if ($provider->status !== ProviderStatus::Suspended || ! is_string($previous)) {
            return;
        }
        $verified = $provider->verificationChecks()->where('status', VerificationStatus::Verified->value)->get()->map(fn (VerificationCheck $c) => $c->type)->all();
        foreach (VerificationType::requiredFor($provider->type) as $type) {
            if (! in_array($type, $verified, true)) {
                return;
            }
        }
        $provider->status = ProviderStatus::tryFrom($previous) ?? ProviderStatus::Trial;
        $provider->setAttribute('suspended_from', null);
        $provider->save();
        activity('platform')->causedBy($reviewer)->performedOn($provider)->log('Practice restored: registration checks verified');
        Notification::send(PracticeContacts::owners($provider->id), new PracticeNotice(
            "{$provider->name} is fully active again",
            ['Your corrected registration details are verified. Your workspace is open for changes and you are listed again.'],
            'Open your workspace', PracticeContacts::url($provider, '/'), 'restored',
        ));
    }
}
