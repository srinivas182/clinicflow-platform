<?php

use App\Domains\Patients\Enums\Sex;
use App\Domains\Patients\Support\SaIdNumber;
use Carbon\CarbonImmutable;

it('accepts a valid SA ID and reads date of birth, sex and citizenship', function (): void {
    $id = SaIdNumber::tryParse(saId('880412', '0547', '0'), CarbonImmutable::parse('2026-10-03'));

    expect($id)->not->toBeNull()
        ->and($id->dateOfBirth()?->toDateString())->toBe('1988-04-12')
        ->and($id->sex())->toBe(Sex::Female)
        ->and($id->isCitizen())->toBeTrue();
});

it('reads male and permanent-resident digits', function (): void {
    $id = SaIdNumber::tryParse(saId('150720', '5123', '1'));

    expect($id?->sex())->toBe(Sex::Male)
        ->and($id?->isCitizen())->toBeFalse()
        ->and($id?->dateOfBirth()?->toDateString())->toBe('2015-07-20');
});

it('rejects a wrong check digit, wrong length or impossible date', function (string $value): void {
    expect(SaIdNumber::tryParse($value))->toBeNull();
})->with([
    'bad check digit' => substr(saId(), 0, 12).(((int) substr(saId(), 12)) + 1) % 10,
    'too short' => '88041205470',
    'impossible date' => saId('881345'),
]);

it('ignores spaces when parsing', function (): void {
    $raw = saId();
    $spaced = substr($raw, 0, 6).' '.substr($raw, 6, 4).' '.substr($raw, 10);

    expect(SaIdNumber::tryParse($spaced)?->value())->toBe($raw);
});
