<?php

use App\Domains\Identity\Enums\Permission;
use App\Domains\Identity\Enums\StaffRole;
use App\Domains\Prescribing\Support\ScheduleRules;

it('limits repeats by schedule', function (): void {
    expect(ScheduleRules::maxRepeats('S6'))->toBe(0)
        ->and(ScheduleRules::maxRepeats('s4'))->toBe(5)
        ->and(ScheduleRules::maxRepeats('S3'))->toBe(5)
        ->and(ScheduleRules::maxRepeats('S0'))->toBeNull();
});

it('keeps consult writing for prescribers only', function (StaffRole $role): void {
    expect(in_array(Permission::CONSULTS_WRITE, $role->defaultPermissions(), true))->toBe($role->isPrescriber());
})->with(StaffRole::cases());
