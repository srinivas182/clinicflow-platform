<?php

use App\Domains\Identity\Actions\AddStaffMember;
use App\Domains\Identity\Enums\StaffRole;
use App\Domains\Platform\Enums\ProviderType;
use App\Domains\Platform\Models\Provider;
use App\Domains\Telemedicine\Events\ChatMessagePosted;
use App\Domains\Visits\Events\VisitStageChanged;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Testing\TestResponse;

uses(DatabaseMigrations::class);

beforeEach(function (): void {
    if (config('database.default') !== 'mysql') {
        $this->markTestSkipped('Tenancy tests require MySQL.');
    }
    config(['broadcasting.default' => 'reverb', 'broadcasting.connections.reverb.key' => 'test-key', 'broadcasting.connections.reverb.secret' => 'test-secret',
        'broadcasting.connections.reverb.app_id' => 'clinicflow', 'broadcasting.connections.reverb.options.host' => 'ws.clinicflow.test',
        'broadcasting.connections.reverb.options.port' => 443, 'broadcasting.connections.reverb.options.useTLS' => true]);
    // Channel rules are registered on the active broadcaster at start-up; re-register for Reverb in this test.
    require base_path('routes/channels.php');
    $this->sunrise = makeProvider('Sunrise Medical Centre', ProviderType::Clinic, 'sunrise.clinicflow.test');
    $this->northside = makeProvider('Northside Clinic', ProviderType::Clinic, 'northside.clinicflow.test');
    $this->nurse = User::factory()->create();
    $this->other = User::factory()->create();
    app(AddStaffMember::class)->handle($this->sunrise, $this->nurse, StaffRole::Nurse);
    app(AddStaffMember::class)->handle($this->northside, $this->other, StaffRole::Nurse);
});

afterEach(function (): void {
    tenancy()->end();
    Provider::query()->get()->each->delete();
});

function channelAuth(object $t, string $domain, string $channel): TestResponse
{
    return $t->post("http://{$domain}/broadcasting/auth", ['socket_id' => '1234.5678', 'channel_name' => "private-{$channel}"]);
}

it('lets only staff of a practice listen to that practice\'s channels, on its own domain', function (): void {
    $queue = "provider.{$this->sunrise->id}.queue";
    $this->actingAs($this->nurse);
    channelAuth($this, 'sunrise.clinicflow.test', $queue)->assertOk()->assertJsonStructure(['auth']);
    channelAuth($this, 'sunrise.clinicflow.test', "provider.{$this->sunrise->id}.chat.42")->assertOk();
    channelAuth($this, 'sunrise.clinicflow.test', "provider.{$this->northside->id}.queue")->assertForbidden();

    $this->flushSession();
    $this->actingAs($this->other);
    channelAuth($this, 'northside.clinicflow.test', $queue)->assertForbidden();

    $this->flushSession();
    auth()->guard('web')->logout();
    expect(channelAuth($this, 'sunrise.clinicflow.test', $queue)->status())->toBeIn([302, 401, 403]);
});

it('sends short, practice-scoped events and no message text', function (): void {
    $queue = new VisitStageChanged($this->sunrise->id, 'v1', 'A001', 'triage');
    $chat = new ChatMessagePosted($this->sunrise->id, '42');
    expect($queue->broadcastAs())->toBe('queue.changed')->and($queue->broadcastOn()[0]->name)->toBe("private-provider.{$this->sunrise->id}.queue")
        ->and($chat->broadcastAs())->toBe('chat.posted')->and($chat->broadcastOn()[0]->name)->toBe("private-provider.{$this->sunrise->id}.chat.42")
        ->and(array_keys(get_object_vars($chat)))->toBe(['providerId', 'threadId', 'socket']);
});

it('shares real-time settings and allows the Reverb address only when real-time is on', function (): void {
    config(['clinicflow.security.csp' => true]);
    $this->actingAs($this->nurse);
    $page = $this->get('http://sunrise.clinicflow.test/workspace');
    $page->assertInertia(fn ($p) => $p->where('realtime.host', 'ws.clinicflow.test')->where('realtime.provider', $this->sunrise->id));
    expect((string) $page->headers->get('Content-Security-Policy'))->toContain('wss://ws.clinicflow.test');

    config(['broadcasting.default' => 'log']);
    $off = $this->get('http://sunrise.clinicflow.test/workspace');
    $off->assertInertia(fn ($p) => $p->where('realtime', null));
    expect((string) $off->headers->get('Content-Security-Policy'))->not->toContain('ws.clinicflow.test');
});
