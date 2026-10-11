<?php

use Illuminate\Foundation\Testing\DatabaseMigrations;

uses(DatabaseMigrations::class);

it('can be added to the home screen', function (): void {
    $html = (string) $this->get('http://localhost/login')->assertOk()->getContent();
    expect($html)->toContain('rel="manifest" href="/manifest.webmanifest"')->toContain('rel="apple-touch-icon"')->toContain('name="theme-color"');

    $manifest = json_decode((string) file_get_contents(public_path('manifest.webmanifest')), true, flags: JSON_THROW_ON_ERROR);
    expect($manifest['name'])->toBe('Dr Business Flow')->and($manifest['display'])->toBe('standalone')->and($manifest['start_url'])->toBe('/');
    foreach ($manifest['icons'] as $icon) {
        expect(file_exists(public_path(ltrim($icon['src'], '/'))))->toBeTrue();
    }
    expect(collect($manifest['icons'])->pluck('purpose')->filter()->all())->toContain('maskable');
});

it('only ever keeps the app\'s code and the offline page on the device — never pages or data', function (): void {
    $worker = (string) file_get_contents(public_path('sw.js'));
    // The only things put in the device cache: /build/ assets and the offline page.
    expect(substr_count($worker, 'cache.put('))->toBe(1)
        ->and($worker)->toContain('url.pathname.startsWith("/build/")')
        ->and($worker)->toContain('cache.add("/offline.html")')
        // Pages always come from the server; the offline page is shown only without a connection.
        ->and($worker)->toContain('fetch(request).catch(() => caches.match("/offline.html"))');
    expect(file_get_contents(public_path('offline.html')))->toContain("You're offline");
});
