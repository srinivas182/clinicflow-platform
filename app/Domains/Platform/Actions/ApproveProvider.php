<?php

declare(strict_types=1);

namespace App\Domains\Platform\Actions;

use App\Domains\Platform\Enums\ProviderStatus;
use App\Domains\Platform\Enums\VerificationStatus;
use App\Domains\Platform\Enums\VerificationType;
use App\Domains\Platform\Models\Provider;
use App\Domains\Platform\Models\VerificationCheck;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * Takes a verified provider live (trial): listed in the directory, bookings open.
 */
class ApproveProvider
{
    public function handle(Provider $provider, User $admin): Provider
    {
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

        $provider->status = ProviderStatus::Trial;
        $provider->save();

        activity('platform')->causedBy($admin)->performedOn($provider)->log('Provider approved');

        return $provider;
    }
}
