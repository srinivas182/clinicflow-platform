<?php

use App\Domains\Platform\Support\Website\SiteSections;
use Illuminate\Validation\ValidationException;

it('keeps known fields, strips tags and resolves tokens', function (): void {
    $clean = SiteSections::clean([[
        'type' => 'hero', 'heading' => '<b>Welcome</b> to {name}', 'secret' => 'dropped', 'image' => '/images/site/clinic.svg',
        'primary' => ['label' => 'Call', 'href' => 'tel:{phone}'],
    ]]);

    expect($clean[0])->not->toHaveKey('secret')
        ->and($clean[0]['heading'])->toBe('Welcome to {name}')
        ->and(SiteSections::resolve($clean, ['name' => 'Sunrise', 'phone' => '0115550100'])[0]['primary']['href'])->toBe('tel:0115550100');
});

it('refuses unsafe links, images and unknown sections', function (array $section): void {
    expect(fn () => SiteSections::clean([$section]))->toThrow(ValidationException::class);
})->with([
    'javascript link' => [['type' => 'hero', 'primary' => ['label' => 'x', 'href' => 'javascript:alert(1)']]],
    'http image' => [['type' => 'hero', 'image' => 'http://evil.test/x.png']],
    'protocol-relative link' => [['type' => 'cta', 'primary' => ['label' => 'x', 'href' => '//evil.test']]],
    'unknown type' => [['type' => 'script']],
]);

it('sanitises rich text sections', function (): void {
    $clean = SiteSections::clean([['type' => 'richtext', 'html' => '<p>Hi</p><script>alert(1)</script><a href="javascript:x">x</a>']]);

    expect($clean[0]['html'])->not->toContain('<script')->not->toContain('javascript:');
});
