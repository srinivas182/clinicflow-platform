<?php

use App\Domains\Identity\Actions\AddStaffMember;
use App\Domains\Identity\Enums\StaffRole;
use App\Domains\Platform\Enums\ProviderType;
use App\Domains\Platform\Models\Provider;
use App\Domains\Platform\Support\StatusPage;
use App\Domains\Platform\SupportDesk\SupportDesk;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

uses(DatabaseMigrations::class);

beforeEach(function (): void {
    if (config('database.default') !== 'mysql') {
        $this->markTestSkipped('Tenancy tests require MySQL.');
    }
    $this->clinic = makeProvider('Sunrise Medical Centre', ProviderType::Clinic, 'sunrise.clinicflow.test');
    $this->ownerUser = User::factory()->create();
    app(AddStaffMember::class)->handle($this->clinic, $this->ownerUser, StaffRole::Owner);
    $this->admin = User::factory()->create();
    $this->admin->forceFill(['is_platform_admin' => true])->save();
    $this->central = fn () => DB::connection((string) config('tenancy.database.central_connection'));
});

afterEach(function (): void {
    tenancy()->end();
    Provider::query()->get()->each->delete();
});

it('lets a practice raise tickets and grant time-limited, read-only, logged support access it can end', function (): void {
    $base = 'http://sunrise.clinicflow.test';
    $this->actingAs($this->ownerUser)->post("{$base}/support/open", ['subject' => 'Cannot print invoices', 'body' => 'The PDF is blank.'])->assertSessionHasNoErrors();
    $ticket = ($this->central)()->table('support_tickets')->sole();
    $this->actingAs($this->admin)->post('http://localhost/admin/support/reply', ['ticket_id' => $ticket->id, 'body' => 'Looking now.'])->assertSessionHasNoErrors();
    expect(($this->central)()->table('support_tickets')->value('status'))->toBe('answered');

    $desk = app(SupportDesk::class);
    expect(fn () => $desk->handoff(999, $this->admin))->toThrow(ValidationException::class);
    $this->actingAs($this->ownerUser)->post("{$base}/support/grant", ['hours' => 100])->assertSessionHasErrors('hours');
    $this->actingAs($this->ownerUser)->post("{$base}/support/grant", ['hours' => 24, 'ticket_id' => $ticket->id])->assertSessionHasNoErrors();
    $grant = ($this->central)()->table('support_grants')->sole();

    $url = $desk->handoff((int) $grant->id, $this->admin);
    expect(fn () => $desk->handoff((int) $grant->id, $this->ownerUser))->toThrow(ValidationException::class);
    auth()->logout();
    $this->get($url)->assertRedirect()->assertSessionHas('support_grant_id', (int) $grant->id);
    $this->assertAuthenticatedAs($this->admin);

    $session = ['support_grant_id' => (int) $grant->id];
    $this->withSession($session)->actingAs($this->admin)->get("{$base}/settings/branches")->assertOk();
    $this->withSession($session)->actingAs($this->admin)->post("{$base}/settings/branches", ['name' => 'Sneaky'])->assertForbidden();
    $this->clinic->run(fn () => expect(DB::table('activity_log')->where('description', 'Support viewed a page')->exists())->toBeTrue()
        ->and(DB::table('activity_log')->where('description', 'Support access granted')->exists())->toBeTrue());

    $this->actingAs($this->ownerUser)->post("{$base}/support/revoke", ['grant_id' => $grant->id])->assertSessionHasNoErrors();
    $this->withSession($session)->actingAs($this->admin)->get("{$base}/settings/branches")->assertRedirect()->assertSessionHas('error', 'Support access has ended.');
});

it('checks components automatically, keeps manual overrides and publishes incidents', function (): void {
    $status = app(StatusPage::class);
    $status->check();
    expect(($this->central)()->table('status_components')->where('key', 'app')->value('status'))->toBe('operational');

    $status->setManual('payments', 'outage');
    $status->check();
    expect(($this->central)()->table('status_components')->where('key', 'payments')->value('status'))->toBe('outage');

    $this->actingAs($this->admin)->post('http://localhost/admin/status/report', ['title' => 'Card payments failing', 'kind' => 'incident', 'impact' => 'major', 'components' => ['payments'], 'body' => 'Investigating.'])->assertSessionHasNoErrors();
    $incident = ($this->central)()->table('status_incidents')->sole();

    $this->get('http://localhost/status')->assertOk()->assertInertia(fn ($p) => $p->component('Status/Show')->where('overall', 'outage')->where('incidents.0.title', 'Card payments failing'));
    $this->get('http://localhost/status.json')->assertOk()->assertHeader('Access-Control-Allow-Origin', '*')->assertJsonPath('overall', 'outage');

    $status->update((int) $incident->id, 'resolved', 'Fixed with the payment provider.');
    $status->setManual('payments', null);
    $status->check();
    expect(($this->central)()->table('status_incidents')->value('resolved_at'))->not->toBeNull()
        ->and($status->summary()['components']->firstWhere('key', 'payments')->status)->toBe('operational');
});

it('lets only the practice owner grant support access, while a practice admin may end it', function (): void {
    $practiceAdmin = User::factory()->create();
    app(AddStaffMember::class)->handle($this->clinic, $practiceAdmin, StaffRole::PracticeAdmin);
    $base = 'http://sunrise.clinicflow.test';

    $this->actingAs($practiceAdmin)->post("{$base}/support/grant", ['hours' => 24])->assertForbidden();
    expect(($this->central)()->table('support_grants')->count())->toBe(0);

    $this->actingAs($this->ownerUser)->post("{$base}/support/grant", ['hours' => 24])->assertSessionHasNoErrors();
    $grant = ($this->central)()->table('support_grants')->sole();
    $this->actingAs($practiceAdmin)->post("{$base}/support/revoke", ['grant_id' => $grant->id])->assertSessionHasNoErrors();
    expect(($this->central)()->table('support_grants')->value('revoked_at'))->not->toBeNull();
});

it('never lets a support grant from one practice open another practice', function (): void {
    $other = makeProvider('Northside Clinic', ProviderType::Clinic, 'northside.clinicflow.test');
    $this->actingAs($this->ownerUser)->post('http://sunrise.clinicflow.test/support/grant', ['hours' => 24])->assertSessionHasNoErrors();
    $grant = ($this->central)()->table('support_grants')->sole();

    $this->withSession(['support_grant_id' => (int) $grant->id])->actingAs($this->admin)->get('http://northside.clinicflow.test/settings/branches')
        ->assertRedirect()->assertSessionHas('error', 'Support access has ended.');
    expect($other->id)->not->toBe($this->clinic->id);
});
