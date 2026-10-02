<?php

declare(strict_types=1);

namespace App\Domains\Platform\Actions;

use App\Domains\Identity\Actions\AddStaffMember;
use App\Domains\Identity\Enums\StaffRole;
use App\Domains\Identity\Models\Staff;
use App\Domains\Platform\Enums\ProviderStatus;
use App\Domains\Platform\Enums\ProviderType;
use App\Domains\Platform\Enums\SubscriptionStatus;
use App\Domains\Platform\Enums\VerificationType;
use App\Domains\Platform\Models\Package;
use App\Domains\Platform\Models\Provider;
use App\Domains\Platform\Models\Subscription;
use App\Domains\Platform\Support\SubdomainPolicy;
use App\Models\User;
use Illuminate\Validation\ValidationException;
use Stancl\Tenancy\Database\Models\Domain;

/**
 * Self-service sign-up: creates the provider (with its own database), free
 * subdomain, trial subscription, verification checklist and owner account.
 */
class StartProviderSignup
{
    public function __construct(private readonly AddStaffMember $addStaff) {}

    /**
     * @param  array<string, string>  $references  verification references keyed by VerificationType value
     */
    public function handle(
        string $name,
        ProviderType $type,
        string $subdomain,
        Package $package,
        string $ownerName,
        string $ownerEmail,
        string $ownerPhone,
        string $password,
        array $references = [],
    ): Provider {
        $subdomain = strtolower(trim($subdomain));
        $errors = [];

        if (! SubdomainPolicy::isValid($subdomain)) {
            $errors['subdomain'] = 'Use 3–40 lowercase letters, numbers or hyphens. Some words are reserved.';
        } elseif (Domain::query()->where('domain', SubdomainPolicy::domainFor($subdomain))->exists()) {
            $errors['subdomain'] = 'This address is already taken.';
        }

        if ($package->provider_type !== $type || ! $package->is_active) {
            $errors['package'] = 'Choose a package for this kind of practice.';
        }

        if (User::query()->where('email', $ownerEmail)->orWhere('phone', $ownerPhone)->exists()) {
            $errors['owner_email'] = 'This email or cell number already has a Clinic Flow account. Sign in and add a workspace instead.';
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $provider = Provider::create([
            'name' => trim($name),
            'type' => $type,
            'status' => ProviderStatus::PendingVerification,
        ]);

        $provider->domains()->create(['domain' => SubdomainPolicy::domainFor($subdomain)]);

        Subscription::create([
            'tenant_id' => $provider->id,
            'package_id' => $package->id,
            'status' => SubscriptionStatus::Trialing,
            'trial_ends_at' => now()->addDays($package->trial_days),
        ]);

        foreach (VerificationType::requiredFor($type) as $check) {
            $provider->verificationChecks()->create([
                'type' => $check,
                'reference' => $references[$check->value] ?? null,
            ]);
        }

        $owner = User::create(['name' => $ownerName, 'email' => $ownerEmail, 'phone' => $ownerPhone, 'password' => $password]);
        $this->addStaff->handle($provider, $owner, StaffRole::Owner);

        if ($type === ProviderType::IndependentDoctor) {
            // A solo doctor is both owner and prescriber.
            $provider->run(fn () => Staff::query()->findOrFail($owner->id)->assignRole(StaffRole::Doctor->value));
        }

        activity('platform')->causedBy($owner)->performedOn($provider)->log('Provider signed up');

        return $provider;
    }
}
