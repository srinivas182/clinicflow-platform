<?php

use App\Domains\Identity\Enums\Permission;
use App\Domains\Identity\Enums\StaffRole;
use App\Domains\Platform\Enums\ProviderType;

it('lets only prescribers sign scripts', function (StaffRole $role): void {
    expect(in_array(Permission::SCRIPTS_SIGN, $role->defaultPermissions(), true))->toBe($role->isPrescriber());
})->with(StaffRole::cases());

it('does not give the owner prescribing rights by default', function (): void {
    expect(StaffRole::Owner->defaultPermissions())->not->toContain(Permission::SCRIPTS_SIGN)
        ->and(StaffRole::Owner->defaultPermissions())->toContain(Permission::AUDIT_VIEW);
});

it('offers only relevant roles to each provider type', function (): void {
    expect(StaffRole::forProviderType(ProviderType::Pharmacy))->not->toContain(StaffRole::Doctor)
        ->and(StaffRole::forProviderType(ProviderType::Lab))->toContain(StaffRole::LabTechnician)
        ->and(StaffRole::forProviderType(ProviderType::Clinic))->toHaveCount(11);
});
