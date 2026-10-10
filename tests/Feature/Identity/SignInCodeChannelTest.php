<?php

use App\Domains\Identity\Contracts\OtpSender;
use App\Domains\Messaging\Contracts\MessageSender;
use App\Domains\Wallet\Support\WalletSettings;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;

uses(DatabaseMigrations::class);

beforeEach(function (): void {
    $this->outbox = new class implements MessageSender
    {
        /** @var list<array{0: string, 1: string}> */
        public array $sent = [];

        public function send(string $channel, string $recipient, ?string $subject, string $body): bool
        {
            $this->sent[] = [$channel, $recipient];

            return true;
        }
    };
    $this->app->instance(MessageSender::class, $this->outbox);
    $this->app->forgetInstance(OtpSender::class);
    $this->user = User::factory()->create(['email' => 'nurse@drbusinessflow.com', 'phone' => '0821234567']);
});

it('emails sign-in codes while no SMS supplier is set up', function (): void {
    app(OtpSender::class)->send($this->user, '123456');
    expect($this->outbox->sent)->toBe([['email', 'nurse@drbusinessflow.com']]);
});

it('follows the method order set by the super admin', function (): void {
    WalletSettings::put('security.two_factor_methods', ['sms', 'email']);
    app(OtpSender::class)->send($this->user, '123456');
    expect($this->outbox->sent)->toBe([['sms', '0821234567']]);
});
