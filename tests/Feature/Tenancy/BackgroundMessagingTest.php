<?php

use App\Domains\Identity\Actions\AddStaffMember;
use App\Domains\Identity\Enums\StaffRole;
use App\Domains\Messaging\Actions\SendMessage;
use App\Domains\Messaging\Contracts\MessageSender;
use App\Domains\Messaging\Jobs\DeliverMessage;
use App\Domains\Platform\Enums\ProviderType;
use App\Domains\Platform\Models\Provider;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Queue\Jobs\SyncJob;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

uses(DatabaseMigrations::class);

beforeEach(function (): void {
    if (config('database.default') !== 'mysql') {
        $this->markTestSkipped('Tenancy tests require MySQL.');
    }
    $this->outbox = new class implements MessageSender
    {
        public bool $up = true;

        /** @var list<array{0: string, 1: string}> */
        public array $sent = [];

        public function send(string $channel, string $recipient, ?string $subject, string $body): bool
        {
            if ($this->up) {
                $this->sent[] = [$channel, $recipient];
            }

            return $this->up;
        }
    };
    $this->app->instance(MessageSender::class, $this->outbox);
    $this->clinic = makeProvider('Sunrise Medical Centre', ProviderType::Clinic, 'sunrise.clinicflow.test');
});

afterEach(function (): void {
    tenancy()->end();
    Provider::query()->get()->each->delete();
});

it('queues messages, delivers them in the background and records the outcome', function (): void {
    config(['queue.default' => 'redis']);
    Queue::fake();
    $this->clinic->run(function (): void {
        expect(app(SendMessage::class)->handle('sms', '0821234567', 'Your appointment is tomorrow at 09:00.'))->toBeTrue();
        expect(DB::table('message_log')->value('status'))->toBe('queued')->and($this->outbox->sent)->toBe([]);
    });
    Queue::assertPushedOn('messages', DeliverMessage::class);

    $job = Queue::pushed(DeliverMessage::class)->first();
    $this->clinic->run(function () use ($job): void {
        $job->handle(app(SendMessage::class));
        expect(DB::table('message_log')->value('status'))->toBe('sent')->and($this->outbox->sent)->toBe([['sms', '0821234567']]);
    });
});

it('retries a failed send, then marks it failed', function (): void {
    config(['queue.default' => 'redis']);
    Queue::fake();
    $this->outbox->up = false;
    $this->clinic->run(fn () => app(SendMessage::class)->handle('email', 'patient@example.test', 'Your results are ready.', 'Results'));
    $job = Queue::pushed(DeliverMessage::class)->first();
    $delays = [];
    $job->setJob(new class($delays) extends SyncJob
    {
        /** @param  array<int, int>  $delays */
        public function __construct(public array &$delays) {}

        public function attempts(): int
        {
            return 1;
        }

        public function release($delay = 0): void
        {
            $this->delays[] = (int) $delay;
        }

        public function getJobId(): string
        {
            return 'test';
        }

        public function getRawBody(): string
        {
            return '{}';
        }
    });
    $this->clinic->run(function () use ($job): void {
        $job->handle(app(SendMessage::class));
        expect(DB::table('message_log')->value('status'))->toBe('queued');
        $job->failed(new RuntimeException('supplier down'));
        expect(DB::table('message_log')->value('status'))->toBe('failed');
    });
    expect($delays)->toBe([30]);
});

it('keeps the queue dashboard for the super admin only, and alerts admins about failed jobs', function (): void {
    $owner = User::factory()->create();
    app(AddStaffMember::class)->handle($this->clinic, $owner, StaffRole::Owner);
    $this->actingAs($owner)->get('http://localhost/admin/horizon')->assertForbidden();
    $admin = User::factory()->create(['email' => 'ops@clinicflow.test']);
    $admin->forceFill(['is_platform_admin' => true])->save();
    $this->actingAs($admin)->get('http://localhost/admin/horizon')->assertOk();

    DB::table('failed_jobs')->insert(['uuid' => (string) Str::uuid(), 'connection' => 'redis', 'queue' => 'messages',
        'payload' => json_encode(['displayName' => DeliverMessage::class]), 'exception' => 'supplier down', 'failed_at' => now()]);
    $this->artisan('queue:alert')->assertSuccessful();
    expect($this->outbox->sent)->toContain(['email', 'ops@clinicflow.test']);
});
