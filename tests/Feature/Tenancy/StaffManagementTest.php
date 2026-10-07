<?php

use App\Domains\Identity\Actions\AddStaffMember;
use App\Domains\Identity\Enums\StaffRole;
use App\Domains\Messaging\Contracts\MessageSender;
use App\Domains\Platform\Enums\ProviderType;
use App\Domains\Platform\Models\Provider;
use App\Http\Middleware\RequireRecentConfirmation;
use App\Models\User;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;

uses(DatabaseMigrations::class);

beforeEach(function (): void {
    if (config('database.default') !== 'mysql') {
        $this->markTestSkipped('Tenancy tests require MySQL.');
    }
    $this->outbox = new class implements MessageSender
    {
        /** @var list<array{0: string, 1: string}> */
        public array $sent = [];

        public function send(string $channel, string $recipient, ?string $subject, string $body): bool
        {
            $this->sent[] = [$recipient, $body];

            return true;
        }

        public function linkFor(string $recipient): ?string
        {
            foreach (array_reverse($this->sent) as [$to, $body]) {
                if ($to === $recipient && preg_match('#/invitations/([A-Za-z0-9]{48})#', $body, $m) === 1) {
                    return $m[1];
                }
            }

            return null;
        }
    };
    $this->app->instance(MessageSender::class, $this->outbox);
    $this->clinic = makeProvider('Sunrise Medical Centre', ProviderType::Clinic, 'sunrise.clinicflow.test');
    $this->owner = User::factory()->create(['name' => 'Dr Owner']);
    $this->admin = User::factory()->create(['name' => 'Practice Admin']);
    $this->nurse = User::factory()->create(['name' => 'Nurse Joy']);
    app(AddStaffMember::class)->handle($this->clinic, $this->owner, StaffRole::Owner);
    app(AddStaffMember::class)->handle($this->clinic, $this->admin, StaffRole::PracticeAdmin);
    app(AddStaffMember::class)->handle($this->clinic, $this->nurse, StaffRole::Nurse);
    $this->branch = $this->clinic->run(fn () => DB::table('branches')->insertGetId(['name' => 'Main', 'active' => true, 'created_at' => now(), 'updated_at' => now()]));
    $this->base = 'http://sunrise.clinicflow.test';
    $this->confirmed = [RequireRecentConfirmation::SESSION_KEY => now()->getTimestamp()];
});

/** Platform tables (invitations, memberships) live in the platform database. */
function staffPlatformDb(): ConnectionInterface
{
    return DB::connection((string) config('tenancy.database.central_connection'));
}

afterEach(function (): void {
    tenancy()->end();
    Provider::query()->get()->each->delete();
});

it('invites a new person who creates an account and joins with the chosen role and branch', function (): void {
    $this->actingAs($this->owner)->post("{$this->base}/staff/invitations", ['name' => 'Dr Mokoena', 'email' => 'mokoena@sunrise.test', 'role' => 'doctor', 'branches' => [$this->branch]])
        ->assertRedirect("{$this->base}/confirm-identity");
    $this->actingAs($this->owner)->withSession($this->confirmed)->post("{$this->base}/staff/invitations", ['name' => 'Dr Mokoena', 'email' => 'mokoena@sunrise.test', 'role' => 'doctor', 'branches' => [$this->branch]])
        ->assertSessionHasNoErrors();
    $token = $this->outbox->linkFor('mokoena@sunrise.test');
    expect($token)->not->toBeNull()->and(staffPlatformDb()->table('staff_invitations')->value('token_hash'))->toBe(hash('sha256', (string) $token));

    auth()->guard('web')->logout();
    $this->flushSession();
    $this->get("http://localhost/invitations/{$token}")->assertOk()->assertInertia(fn ($p) => $p->component('Auth/Invitation')->where('valid', true)
        ->where('practice', 'Sunrise Medical Centre')->where('role', 'Doctor')->where('existingAccount', false));
    $this->post("http://localhost/invitations/{$token}/register", ['name' => 'Dr Thabo Mokoena', 'password' => 'clinic-2026-strong', 'password_confirmation' => 'clinic-2026-strong'])
        ->assertRedirect('/login');

    $user = User::query()->where('email', 'mokoena@sunrise.test')->firstOrFail();
    expect(staffPlatformDb()->table('memberships')->where('user_id', $user->id)->where('tenant_id', $this->clinic->id)->value('role'))->toBe('doctor');
    $this->clinic->run(fn () => expect(DB::table('branch_staff')->where('staff_id', $user->id)->pluck('branch_id')->all())->toBe([$this->branch])
        ->and(DB::table('activity_log')->where('description', 'Staff member invited')->exists())->toBeTrue());
    $this->get("http://localhost/invitations/{$token}")->assertInertia(fn ($p) => $p->where('valid', false));
});

it('lets an existing account accept after signing in, only for the address it was sent to', function (): void {
    $existing = User::factory()->create(['email' => 'locum@example.test']);
    $stranger = User::factory()->create(['email' => 'someone@example.test']);
    $this->actingAs($this->admin)->withSession($this->confirmed)->post("{$this->base}/staff/invitations", ['name' => 'Locum', 'email' => 'locum@example.test', 'role' => 'nurse']);
    $token = (string) $this->outbox->linkFor('locum@example.test');

    $this->flushSession();
    $this->actingAs($stranger)->post("http://localhost/invitations/{$token}/accept")->assertSessionHasErrors('token');
    $this->flushSession();
    $this->actingAs($existing)->post("http://localhost/invitations/{$token}/accept")->assertRedirect('/workspaces');
    expect(staffPlatformDb()->table('memberships')->where('user_id', $existing->id)->where('tenant_id', $this->clinic->id)->value('role'))->toBe('nurse');
});

it('makes old, withdrawn and resent links stop working', function (): void {
    $send = fn (string $email) => $this->actingAs($this->owner)->withSession($this->confirmed)->post("{$this->base}/staff/invitations", ['name' => 'New', 'email' => $email, 'role' => 'receptionist']);
    $send('one@sunrise.test');
    $first = (string) $this->outbox->linkFor('one@sunrise.test');
    $id = (int) staffPlatformDb()->table('staff_invitations')->where('email', 'one@sunrise.test')->value('id');
    $this->actingAs($this->owner)->withSession($this->confirmed)->post("{$this->base}/staff/invitations/{$id}/resend")->assertSessionHasNoErrors();
    $second = (string) $this->outbox->linkFor('one@sunrise.test');
    expect($second)->not->toBe($first);
    $this->get("http://localhost/invitations/{$first}")->assertInertia(fn ($p) => $p->where('valid', false));
    $this->get("http://localhost/invitations/{$second}")->assertInertia(fn ($p) => $p->where('valid', true));

    $this->actingAs($this->owner)->withSession($this->confirmed)->post("{$this->base}/staff/invitations/{$id}/revoke");
    $this->get("http://localhost/invitations/{$second}")->assertInertia(fn ($p) => $p->where('valid', false));

    $send('two@sunrise.test');
    $old = (string) $this->outbox->linkFor('two@sunrise.test');
    $this->travel(8)->days();
    $this->get("http://localhost/invitations/{$old}")->assertInertia(fn ($p) => $p->where('valid', false));
});

it('protects the owner and people\'s own access, and keeps the page for owners and admins', function (): void {
    $this->actingAs($this->nurse)->get("{$this->base}/staff")->assertForbidden();
    $this->actingAs($this->admin)->get("{$this->base}/staff")->assertOk()->assertInertia(fn ($p) => $p->component('Staff/Index')->has('members', 3)
        ->where('roles', fn ($r) => collect($r)->pluck('value')->doesntContain('owner')));
    $admin = fn () => $this->actingAs($this->admin)->withSession($this->confirmed);
    $admin()->post("{$this->base}/staff/{$this->owner->id}/suspend")->assertSessionHasErrors('staff');
    $admin()->post("{$this->base}/staff/{$this->admin->id}", ['role' => 'nurse'])->assertSessionHasErrors('staff');
    $admin()->post("{$this->base}/staff/invitations", ['name' => 'X', 'email' => 'x@sunrise.test', 'role' => 'owner'])->assertSessionHasErrors('role');
    $admin()->post("{$this->base}/staff/invitations", ['name' => 'Nurse Joy', 'email' => $this->nurse->email, 'role' => 'nurse'])->assertSessionHasErrors('email');

    $admin()->post("{$this->base}/staff/{$this->nurse->id}", ['role' => 'receptionist', 'branches' => [$this->branch]])->assertSessionHasNoErrors();
    expect(staffPlatformDb()->table('memberships')->where('user_id', $this->nurse->id)->value('role'))->toBe('receptionist');
});

it('blocks a suspended person straight away and restores access when reactivated', function (): void {
    $this->actingAs($this->nurse)->get("{$this->base}/workspace")->assertOk();
    $this->flushSession();
    $this->actingAs($this->owner)->withSession($this->confirmed)->post("{$this->base}/staff/{$this->nurse->id}/suspend")->assertSessionHasNoErrors();
    $this->flushSession();
    expect($this->actingAs($this->nurse)->get("{$this->base}/workspace")->status())->toBeIn([302, 403]);
    $this->flushSession();
    $this->actingAs($this->owner)->withSession($this->confirmed)->post("{$this->base}/staff/{$this->nurse->id}/reactivate");
    $this->flushSession();
    $this->actingAs($this->nurse)->get("{$this->base}/workspace")->assertOk();
    $this->clinic->run(fn () => expect(DB::table('activity_log')->whereIn('description', ['Staff member suspended', 'Staff member reactivated'])->count())->toBe(2));
});
