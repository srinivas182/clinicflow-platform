<?php

use App\Domains\Telemedicine\Models\VideoConfig;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Vite;

uses(DatabaseMigrations::class);

beforeEach(function (): void {
    if (config('database.default') !== 'mysql') {
        $this->markTestSkipped('Tenancy tests require MySQL.');
    }
});

it('sends security headers on every page, and a content-security policy with a fresh nonce', function (): void {
    config(['clinicflow.security.csp' => true]);
    VideoConfig::query()->create(['driver' => 'cloud', 'mode' => 'live', 'url' => 'wss://clinicflow-za.livekit.cloud', 'enabled' => true]);
    Cache::forget('csp:video-origins');

    $first = $this->get('http://localhost/login')->assertOk();
    $first->assertHeader('X-Content-Type-Options', 'nosniff')->assertHeader('X-Frame-Options', 'SAMEORIGIN')
        ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
    expect($first->headers->get('Permissions-Policy'))->toContain('camera=(self)')->toContain('geolocation=()');
    $csp = (string) $first->headers->get('Content-Security-Policy');
    expect($csp)->toContain("default-src 'self'")->toContain("object-src 'none'")->toContain("frame-ancestors 'self'")
        ->toContain('wss://clinicflow-za.livekit.cloud')->toMatch("/script-src 'self' 'nonce-[A-Za-z0-9]{32}'/")
        ->not->toContain("'unsafe-eval'")->not->toMatch("/script-src[^;]*'unsafe-inline'/");
    preg_match("/'nonce-([A-Za-z0-9]{32})'/", $csp, $a);
    preg_match("/'nonce-([A-Za-z0-9]{32})'/", (string) $this->get('http://localhost/login')->headers->get('Content-Security-Policy'), $b);
    expect($a[1])->not->toBe($b[1]);
    // Vite tags get the same nonce as the header (Laravel adds it to the script tags it renders).
    $this->get('http://localhost/login');
    $header = (string) $this->get('http://localhost/login')->headers->get('Content-Security-Policy');
    expect($header)->toContain("'nonce-".Vite::cspNonce()."'");
});

it('can run the policy in report-only mode, and leaves it off in development unless asked', function (): void {
    config(['clinicflow.security.csp' => true, 'clinicflow.security.csp_report_only' => true]);
    $r = $this->get('http://localhost/login');
    expect($r->headers->has('Content-Security-Policy'))->toBeFalse()->and($r->headers->has('Content-Security-Policy-Report-Only'))->toBeTrue();
    config(['clinicflow.security.csp' => null, 'clinicflow.security.csp_report_only' => false]);
    expect($this->get('http://localhost/login')->headers->has('Content-Security-Policy'))->toBeFalse();
});

it('lets the payment page auto-submit under the policy', function (): void {
    config(['clinicflow.security.csp' => true]);
    $html = view('payments.redirect', ['action' => 'https://sandbox.payfast.co.za/eng/process', 'fields' => ['amount' => '100.00']] + ['cspNonce' => 'abc123'])->render();
    expect($html)->toContain('<script nonce="abc123">');
});

it('flags unsafe production settings in the deployment check', function (): void {
    config(['app.debug' => true, 'app.url' => 'http://clinicflow.test', 'session.secure' => false]);
    $this->artisan('security:check')->expectsOutputToContain('✗ Debug mode is off')->expectsOutputToContain('✗ Site address uses HTTPS')->assertSuccessful();
    config(['app.debug' => false, 'app.url' => 'https://clinicflow.co.za', 'session.secure' => true, 'session.encrypt' => true, 'session.lifetime' => 30,
        'clinicflow.security.csp' => true, 'clinicflow.security.require_authenticator_for_admins' => true]);
    $this->artisan('security:check')->expectsOutputToContain('All security settings are in place.')->assertSuccessful();
});
