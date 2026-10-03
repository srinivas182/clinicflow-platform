<?php

use App\Domains\Messaging\Enums\MessagingDriver;
use App\Domains\Messaging\Gateways\EmailGateway;
use App\Domains\Messaging\Models\MessagingProvider;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

function emailSupplier(MessagingDriver $driver): MessagingProvider
{
    return new MessagingProvider(['driver' => $driver, 'channel' => 'email', 'mode' => 'live', 'credentials' => ['api_key' => 'key-123'], 'sender' => 'notifications@clinicflow.test']);
}

it('sends email through Twilio SendGrid with the provider name and reply-to', function (): void {
    Http::fake(['api.sendgrid.com/v3/mail/send' => Http::response('', 202)]);

    $result = EmailGateway::send(emailSupplier(MessagingDriver::SendGrid), 'thandi@example.test', 'Booking confirmed', 'See you at 11:40.', 'Sunrise Medical Centre', 'reception@sunrise.test');

    expect($result['ok'])->toBeTrue();
    Http::assertSent(fn (ClientRequest $r) => $r->hasHeader('Authorization', 'Bearer key-123')
        && $r['from'] === ['email' => 'notifications@clinicflow.test', 'name' => 'Sunrise Medical Centre']
        && $r['reply_to'] === ['email' => 'reception@sunrise.test']
        && $r['personalizations'][0]['to'][0]['email'] === 'thandi@example.test');
});

it('sends email through Brevo and reports its errors', function (): void {
    Http::fake(['api.brevo.com/v3/smtp/email' => Http::sequence()->push(['messageId' => 'm1'], 201)->push(['message' => 'Sender not verified'], 400)]);
    $supplier = emailSupplier(MessagingDriver::Brevo);

    expect(EmailGateway::send($supplier, 'a@example.test', 'S', 'B', 'Sunrise', null)['ok'])->toBeTrue();
    Http::assertSent(fn (ClientRequest $r) => $r->hasHeader('api-key', 'key-123') && $r['sender']['name'] === 'Sunrise' && $r['textContent'] === 'B');

    expect(EmailGateway::send($supplier, 'a@example.test', 'S', 'B', 'Sunrise', null))->toBe(['ok' => false, 'error' => 'Sender not verified']);
});

it('keeps only one active supplier per channel and keeps the other keys', function (): void {
    $admin = User::factory()->create();
    $admin->forceFill(['is_platform_admin' => true])->save();
    $save = fn (string $driver, bool $on) => $this->actingAs($admin)->put("http://localhost/admin/messaging/providers/{$driver}", [
        'mode' => 'test', 'enabled' => $on, 'is_default' => $on, 'credentials' => ['api_key' => "k-{$driver}"], 'sender' => 'notifications@clinicflow.test',
    ]);

    $save('sendgrid', true)->assertSessionHasNoErrors();
    $save('brevo', true)->assertSessionHasNoErrors();

    expect(MessagingProvider::query()->where('channel', 'email')->where('enabled', true)->pluck('driver')->map(fn ($d) => $d->value)->all())->toBe(['brevo'])
        ->and(MessagingProvider::query()->where('driver', 'sendgrid')->sole()->credentials)->toBe(['api_key' => 'k-sendgrid']);

    $this->actingAs($admin)->put('http://localhost/admin/messaging/providers/smtp', ['mode' => 'test', 'enabled' => true, 'is_default' => true, 'credentials' => []])
        ->assertSessionHasErrors('credentials');
});
