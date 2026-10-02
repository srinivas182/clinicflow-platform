<?php

use App\Domains\Platform\Enums\ProviderStatus;
use App\Domains\Platform\Enums\ProviderType;

it('allows telemedicine only for clinics and independent doctors', function (ProviderType $type, bool $allowed): void {
    expect($type->canOfferTelemedicine())->toBe($allowed);
})->with([
    [ProviderType::Clinic, true],
    [ProviderType::IndependentDoctor, true],
    [ProviderType::Pharmacy, false],
    [ProviderType::Lab, false],
]);

it('stops writes but never deletes data for read-only providers', function (): void {
    expect(ProviderStatus::Active->canWrite())->toBeTrue()
        ->and(ProviderStatus::Trial->canWrite())->toBeTrue()
        ->and(ProviderStatus::ReadOnly->canWrite())->toBeFalse()
        ->and(ProviderStatus::Suspended->canWrite())->toBeFalse();
});
