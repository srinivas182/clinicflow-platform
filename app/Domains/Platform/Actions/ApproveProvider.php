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
 * Takes a verified provider live (trial, or active on a free package): listed in the directory, bookings open.
 */
class ApproveProvider
{
    public function handle(Provider $provider, User $admin): Provider
    {
        // Approving a live or paying practice would reset it to a trial.
        if ($provider->status !== ProviderStatus::PendingVerification) {
            throw ValidationException::withMessages(['verification' => 'Only practices awaiting verification can be approved. This practice is '.str_replace('_', ' ', $provider->status->value).'.']);
        }
        $verified = $provider->verificationChecks()
            ->where('status', VerificationStatus::Verified->value)
            ->get()
            ->map(fn (VerificationCheck $c) => $c->type)
            ->all();

        $missing = array_filter(
            VerificationType::requiredFor($provider->type),
            fn (VerificationType $t) => ! in_array($t, $verified, true),
        );

        if ($missing !== []) {
            throw ValidationException::withMessages([
                'verification' => 'Still to verify: '.implode(', ', array_map(fn (VerificationType $t) => $t->label(), $missing)).'.',
            ]);
        }

        // Free packages go straight to active; paid ones start their trial.
        $provider->status = $provider->subscription?->package->isFree() === true ? ProviderStatus::Active : ProviderStatus::Trial;
        $provider->save();

        activity('platform')->causedBy($admin)->performedOn($provider)->log('Provider approved');
        Notification::send(PracticeContacts::owners($provider->id), new PracticeNotice(
            "{$provider->name} is live on Dr Business Flow",
            ['Your registration checks are complete and your practice is approved.', 'Patients can now find you in the directory and book online.'],
            'Open your workspace', PracticeContacts::url($provider, '/'), 'approved',
        ));

        return $provider;
    }
}
