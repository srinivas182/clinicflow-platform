<?php

use Illuminate\Foundation\Testing\DatabaseMigrations;

uses(DatabaseMigrations::class);

it('shows the logo and a loading bar before any script has arrived', function (): void {
    $html = (string) $this->get('http://localhost/login')->assertOk()->getContent();
    expect($html)->toContain('id="boot"')->toContain('role="status"')->toContain('class="boot-bar"')
        ->toContain(config('app.name'))
        // The loading screen comes before the app's own root element in the page.
        ->and(strpos($html, 'id="boot"'))->toBeLessThan((int) strpos($html, 'id="app"'));
});
