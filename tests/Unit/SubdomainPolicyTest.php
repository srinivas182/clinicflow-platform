<?php

use App\Domains\Platform\Support\SubdomainPolicy;

it('suggests a clean subdomain from the practice name', function (): void {
    expect(SubdomainPolicy::suggest('Dr Priya Naidoo'))->toBe('priya-naidoo')
        ->and(SubdomainPolicy::suggest('Sunrise Medical Centre (Pty) Ltd'))->toBe('sunrise-medical-centre');
});

it('accepts valid and refuses reserved or malformed subdomains', function (string $slug, bool $valid): void {
    expect(SubdomainPolicy::isValid($slug))->toBe($valid);
})->with([
    ['sunrise', true],
    ['dr-naidoo-2', true],
    ['admin', false],
    ['accounts', false],
    ['-sunrise', false],
    ['sun--rise', false],
    ['Sunrise', false],
    ['ab', false],
]);
