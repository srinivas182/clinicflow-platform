<?php

use App\Domains\Identity\Actions\AddStaffMember;
use App\Domains\Identity\Enums\StaffRole;
use App\Domains\Messaging\Actions\SendMessage;
use App\Domains\Messaging\Contracts\MessageSender;
use App\Domains\Messaging\Enums\MessagingDriver;
use App\Domains\Messaging\Gateways\SmsGateway;
use App\Domains\Messaging\Mail\PlatformMessageMail;
use App\Domains\Messaging\Models\MessageTemplate;
use App\Domains\Messaging\Models\MessageUsage;
use App\Domains\Messaging\Models\MessagingProvider;
use App\Domains\Platform\Enums\ProviderType;
use App\Domains\Platform\Enums\SubscriptionStatus;
use App\Domains\Platform\Models\Package;
use App\Domains\Platform\Models\Provider;
use App\Domains\Platform\Models\Setting;
use App\Domains\Platform\Models\Subscription;
use App\Models\User;
use Database\Seeders\PackageSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

uses(DatabaseMigrations::class);

beforeEach(function (): void {
    if (config('database.default') !== 'mysql') {
        $this->markTestSkipped('Tenancy tests require MySQL.');
    }

    $this->seed(PackageSeeder::class);
    $this->clinic = makeProvider('Sunrise Medical Centre', ProviderType::Clinic, 'sunrise.clinicflow.test');
    $this->package = Package::query()->where('code', 'clinic-starter')->sole();
    Subscription::create(['tenant_id' => $this->clinic->id, 'package_id' => $this->package->id, 'status' => SubscriptionStatus::Active, 'current_period_ends_at' => now()->addMonth()]);
    $this->owner = User::factory()->create(['email' => 'owner@sunrise.test']);
    $this->reception = User::factory()->create();
    app(AddStaffMember::class)->handle($this->clinic, $this->owner, StaffRole::Owner);
    app(AddStaffMember::class)->handle($this->clinic, $this->reception, StaffRole::Receptionist);
    $this->admin = User::factory()->create();
    $this->admin->forceFill(['is_platform_admin' => true])->save();
});

afterEach(function (): void {
    tenancy()->end();
    Provider::query()->get()->each->delete();
});

function supplier(object $t, string $driver, array $overrides = []): void
{
    $t->actingAs($t->admin)->put("http://localhost/admin/messaging/providers/{$driver}", array_merge([
        'mode' => 'test', 'enabled' => true, 'is_default' => true, 'sender' => $driver === 'ses' ? 'no-reply@clinicflow.test' : 'ClinicFlow',
        'test_recipients' => '0825550000', 'credentials' => ['account_sid' => 'AC123', 'auth_token' => 'tok', 'region' => 'af-south-1', 'smtp_username' => 'u', 'smtp_password' => 'p'],
    ], $overrides))->assertSessionHasNoErrors();
}

it('lets only the super admin configure suppliers and keeps secrets out of the page', function (): void {
    $this->actingAs($this->owner)->put('http://localhost/admin/messaging/providers/twilio', ['mode' => 'test'])->assertForbidden();
    supplier($this, 'twilio');

    $row = MessagingProvider::query()->sole();
    expect($row->credentials['auth_token'])->toBe('tok')
        ->and($row->getRawOriginal('credentials'))->not->toContain('tok');
    $this->actingAs($this->admin)->get('http://localhost/admin/messaging')->assertOk()->assertDontSee('"tok"', false);
});

it('delivers only to test recipients in test mode and to anyone in live mode', function (): void {
    supplier($this, 'twilio');
    Http::fake(['api.twilio.com/*' => Http::response(['sid' => 'SM1'], 201)]);
    tenancy()->initialize($this->clinic);

    app(SendMessage::class)->template('lab.results_ready', 'sms', '0821112222', ['patient' => 'Sipho']);
    Http::assertNothingSent();
    expect(app(MessageSender::class)->sent[0]['status'])->toBe('suppressed');

    app(SendMessage::class)->template('lab.results_ready', 'sms', '0825550000', ['patient' => 'Sipho']);
    Http::assertSent(fn (ClientRequest $r) => $r['To'] === '+27825550000' && $r['From'] === 'ClinicFlow' && str_contains($r['Body'], 'Sunrise Medical Centre: your lab results are ready'));

    tenancy()->end();
    supplier($this, 'twilio', ['mode' => 'live']);
    tenancy()->initialize($this->clinic);
    app(SendMessage::class)->template('lab.results_ready', 'sms', '0821112222', ['patient' => 'Sipho']);
    Http::assertSent(fn (ClientRequest $r) => $r['To'] === '+27821112222');
});

it('formats requests for Clickatell, BulkSMS and SMSPortal', function (): void {
    Http::fake([
        'platform.clickatell.com/*' => Http::response([], 202),
        'api.bulksms.com/*' => Http::response([], 201),
        'rest.smsportal.com/Authentication' => Http::response(['token' => 'jwt']),
        'rest.smsportal.com/BulkMessages' => Http::response([], 200),
    ]);

    $make = fn (MessagingDriver $d, array $c) => new MessagingProvider(['driver' => $d, 'channel' => 'sms', 'mode' => 'live', 'credentials' => $c]);
    expect(SmsGateway::send($make(MessagingDriver::Clickatell, ['api_key' => 'ck']), '0821112222', 'Hi')['ok'])->toBeTrue()
        ->and(SmsGateway::send($make(MessagingDriver::BulkSms, ['token_id' => 'i', 'token_secret' => 's']), '0821112222', 'Hi')['ok'])->toBeTrue()
        ->and(SmsGateway::send($make(MessagingDriver::SmsPortal, ['client_id' => 'i', 'client_secret' => 's']), '0821112222', 'Hi')['ok'])->toBeTrue();

    Http::assertSent(fn (ClientRequest $r) => str_contains($r->url(), 'clickatell') && $r->header('Authorization')[0] === 'ck' && $r['messages'][0]['to'] === '27821112222');
    Http::assertSent(fn (ClientRequest $r) => str_contains($r->url(), 'bulksms') && $r['to'] === '+27821112222');
    Http::assertSent(fn (ClientRequest $r) => str_contains($r->url(), 'BulkMessages') && $r->hasHeader('Authorization', 'Bearer jwt') && $r['messages'][0]['destination'] === '27821112222');
});

it("sends email from the platform address with the provider's name and reply-to", function (): void {
    Mail::fake();
    supplier($this, 'ses', ['mode' => 'live']);
    tenancy()->initialize($this->clinic);
    Setting::put('messaging', 'from_name', 'Sunrise MC');
    Setting::put('messaging', 'reply_to', 'reception@sunrise.test');

    app(SendMessage::class)->template('lab.results_ready', 'email', 'sipho@example.test', ['patient' => 'Sipho']);

    Mail::assertSent(PlatformMessageMail::class, fn (PlatformMessageMail $m) => $m->hasTo('sipho@example.test')
        && $m->fromAddress === 'no-reply@clinicflow.test' && $m->fromName === 'Sunrise MC' && $m->replyToAddress === 'reception@sunrise.test'
        && $m->subjectLine === 'Your results are ready' && str_contains($m->text, 'Dear Sipho'));
});

it('sends only messages the package includes and honours marketing opt-outs', function (): void {
    tenancy()->initialize($this->clinic);
    $send = app(SendMessage::class);

    expect($send->template('booking.confirmation', 'sms', '0821112222', ['date' => '3 Oct', 'time' => '09:00', 'doctor' => 'Dr M']))->toBeTrue()
        ->and($send->template('queue.next', 'sms', '0821112222', ['ticket' => 'A001', 'room' => 'Room 4']))->toBeFalse()
        ->and(DB::table('message_log')->where('status', 'not_in_package')->count())->toBe(1);

    tenancy()->end();
    $this->actingAs($this->admin)->put("http://localhost/admin/messaging/packages/{$this->package->id}", [
        'sms' => 200, 'email' => 500, 'sms_overage' => 0.35, 'email_overage' => 0.05, 'message_types' => ['msg_booking', 'msg_recalls'],
    ])->assertSessionHasNoErrors();
    tenancy()->initialize($this->clinic);

    DB::table('message_opt_outs')->insert(['channel' => 'sms', 'recipient' => '0821112222', 'opted_out_at' => now()]);
    expect($send->template('recall.reminder', 'sms', '0821112222', ['reason' => 'BP check']))->toBeFalse()
        ->and($send->template('recall.reminder', 'sms', '0839998888', ['reason' => 'BP check']))->toBeTrue();
});

it('lets providers edit only included, editable wording with allowed placeholders', function (): void {
    $put = fn (array $data) => $this->actingAs($this->owner)->put('http://sunrise.clinicflow.test/settings/messaging/wording', $data);

    $put(['key' => 'booking.confirmation', 'channel' => 'sms', 'body' => 'See you {{ date }} at {{ time }} — {{ practice }}'])->assertSessionHasNoErrors();
    $put(['key' => 'booking.confirmation', 'channel' => 'sms', 'body' => 'Your ID {{ id_number }}'])->assertSessionHasErrors('body');
    $put(['key' => 'portal.sign_in_code', 'channel' => 'sms', 'body' => 'Code {{ code }}'])->assertSessionHasErrors('key');
    $put(['key' => 'queue.next', 'channel' => 'sms', 'body' => 'Come in {{ ticket }}'])->assertSessionHasErrors('key');
    $this->actingAs($this->reception)->get('http://sunrise.clinicflow.test/settings/messaging')->assertForbidden();

    tenancy()->initialize($this->clinic);
    app(SendMessage::class)->template('booking.confirmation', 'sms', '0821112222', ['date' => '3 Oct', 'time' => '09:00', 'doctor' => 'Dr M']);
    expect(end(app(MessageSender::class)->sent)['body'])->toBe('See you 3 Oct at 09:00 — Sunrise Medical Centre');
});

it("uses the super admin's wording in the patient's language", function (): void {
    MessageTemplate::create(['key' => 'lab.results_ready', 'channel' => 'sms', 'language' => 'zu', 'body' => '{{ practice }}: imiphumela yakho ilungile.']);
    tenancy()->initialize($this->clinic);

    app(SendMessage::class)->template('lab.results_ready', 'sms', '0821112222', [], 'zu');
    app(SendMessage::class)->template('lab.results_ready', 'sms', '0821112222', [], 'af');

    $sent = app(MessageSender::class)->sent;
    expect($sent[0]['body'])->toBe('Sunrise Medical Centre: imiphumela yakho ilungile.')
        ->and($sent[1]['body'])->toContain('your lab results are ready');
});

it('counts SMS and email separately and warns the owner at 80% once', function (): void {
    tenancy()->end();
    $this->actingAs($this->admin)->put("http://localhost/admin/messaging/packages/{$this->package->id}", [
        'sms' => 10, 'email' => 500, 'sms_overage' => 0.35, 'email_overage' => 0.05, 'message_types' => ['msg_booking'],
    ]);
    tenancy()->initialize($this->clinic);

    foreach (range(1, 9) as $i) {
        app(SendMessage::class)->template('lab.results_ready', 'sms', '08211122'.str_pad((string) $i, 2, '0', STR_PAD_LEFT));
    }
    app(SendMessage::class)->template('lab.results_ready', 'email', 'p@example.test', ['patient' => 'P']);

    $usage = MessageUsage::query()->where('tenant_id', $this->clinic->id)->sole();
    $alerts = array_filter(app(MessageSender::class)->sent, fn ($m) => $m['recipient'] === 'owner@sunrise.test');

    expect($usage->getAttribute('sms_units'))->toBe(9)
        ->and($usage->getAttribute('email_units'))->toBe(1)
        ->and($alerts)->toHaveCount(1)
        ->and(array_values($alerts)[0]['body'])->toContain('80% of its SMS allowance');
});
