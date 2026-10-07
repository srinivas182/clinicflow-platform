<?php

use App\Domains\Identity\Actions\AddStaffMember;
use App\Domains\Identity\Enums\StaffRole;
use App\Domains\Platform\Enums\ProviderType;
use App\Domains\Platform\Models\Provider;
use App\Http\Middleware\HandleInertiaRequests;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Route;

uses(DatabaseMigrations::class);

beforeEach(function (): void {
    if (config('database.default') !== 'mysql') {
        $this->markTestSkipped('Tenancy tests require MySQL.');
    }
});

afterEach(function (): void {
    tenancy()->end();
    Provider::query()->get()->each->delete();
});

function inertiaHeaders(): array
{
    return ['X-Inertia' => 'true', 'X-Inertia-Version' => (string) app(HandleInertiaRequests::class)->version(request()), 'X-Requested-With' => 'XMLHttpRequest'];
}

it('shows a branded not-found page that works in both themes and under the security policy', function (): void {
    $page = $this->get('http://localhost/this-page-does-not-exist')->assertNotFound();
    $html = (string) $page->getContent();
    expect($html)->toContain('Page not found')->toContain('Error 404')->toContain('prefers-color-scheme: dark')->toContain('data-theme="dark"')
        ->not->toContain('onclick')->not->toContain('javascript:');

    $this->withHeaders(inertiaHeaders())->get('http://localhost/this-page-does-not-exist')->assertNotFound()
        ->assertJsonPath('component', 'Errors/Show')->assertJsonPath('props.status', 404);
});

it('explains a refusal with the app\'s own reason', function (): void {
    $clinic = makeProvider('Sunrise Medical Centre', ProviderType::Clinic, 'sunrise.clinicflow.test');
    $nurse = User::factory()->create();
    app(AddStaffMember::class)->handle($clinic, $nurse, StaffRole::Nurse);

    $this->actingAs($nurse)->get('http://sunrise.clinicflow.test/staff')->assertForbidden()
        ->assertSee('You don’t have access', false)->assertSee('Only the practice owner or a practice admin can manage staff.');
    $this->actingAs($nurse)->withHeaders(inertiaHeaders())->get('http://sunrise.clinicflow.test/staff')->assertForbidden()
        ->assertJsonPath('component', 'Errors/Show')->assertJsonPath('props.message', 'Only the practice owner or a practice admin can manage staff.');
});

it('never shows internal details of a server error in production, but keeps the debug page for developers', function (): void {
    Route::middleware('web')->get('/__test-boom', fn () => throw new RuntimeException('database password is hunter2'));
    config(['app.debug' => false]);
    $page = $this->get('http://localhost/__test-boom')->assertStatus(500);
    expect((string) $page->getContent())->toContain('Something went wrong')->not->toContain('hunter2');
    $this->withHeaders(inertiaHeaders())->get('http://localhost/__test-boom')->assertStatus(500)->assertJsonPath('component', 'Errors/Show')->assertJsonPath('props.message', null);

    config(['app.debug' => true]);
    // Developers (debug mode) get the full debug page with the details; production never does (checked above).
    expect((string) $this->get('http://localhost/__test-boom')->getContent())->toContain('hunter2');

    // API and JSON requests keep JSON (the platform's own error format), never the HTML page.
    $json = $this->getJson('http://localhost/this-page-does-not-exist')->assertNotFound()->assertHeader('Content-Type', 'application/json');
    expect(json_decode((string) $json->getContent(), true))->toBeArray()->and((string) $json->getContent())->not->toContain('<html');
});

it('has its own wording and actions for expired sessions, rate limits and maintenance', function (): void {
    $render = fn (string $code) => view("errors.{$code}", ['exception' => null])->render();
    expect($render('419'))->toContain('Your session expired')->toContain('Back to the page')
        ->and($render('429'))->toContain('Too many requests')->toContain('wait a minute')
        ->and($render('503'))->toContain('Back shortly')->toContain('Try again')
        ->and($render('500'))->toContain('Something went wrong');
});
