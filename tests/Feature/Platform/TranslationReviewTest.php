<?php

use App\Domains\Messaging\Contracts\MessageSender;
use App\Domains\Messaging\Models\MessageTemplate;
use App\Domains\Messaging\Support\TemplateResolver;
use App\Domains\Scribe\Models\AiProvider;
use App\Domains\Translation\Actions\Translations;
use App\Domains\Translation\Models\Language;
use App\Domains\Translation\Models\Translation;
use App\Domains\Translation\Models\TranslatorInvitation;
use App\Domains\Translation\Models\TranslatorLanguage;
use App\Domains\Translation\Support\TranslationUnits;
use App\Domains\Wallet\Support\WalletSettings;
use App\Http\Middleware\RequireRecentConfirmation;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

uses(DatabaseMigrations::class);

beforeEach(function (): void {
    $this->outbox = new class implements MessageSender
    {
        /** @var list<array{0: string, 1: string, 2: string}> */
        public array $sent = [];

        public function send(string $channel, string $recipient, ?string $subject, string $body): bool
        {
            $this->sent[] = [$channel, $recipient, $body];

            return true;
        }
    };
    $this->app->instance(MessageSender::class, $this->outbox);
    $this->t = app(Translations::class);
    $this->reviewer = User::factory()->create(['email' => 'zulu@reviewers.test', 'password' => 'review-pass-2026']);
    $this->clinical = User::factory()->create();
    TranslatorLanguage::query()->create(['user_id' => $this->reviewer->id, 'language' => 'zu', 'clinical' => false]);
    TranslatorLanguage::query()->create(['user_id' => $this->clinical->id, 'language' => 'zu', 'clinical' => true]);
    $this->admin = User::factory()->create();
    $this->admin->forceFill(['is_platform_admin' => true])->save();
});

$booking = 'message:booking.confirmation:sms';
$bookingZu = '{{ practice }}: ubhukhe ngomhla ka-{{ date }} ngo-{{ time }} no-{{ doctor }}. Phendula noma ushaye ucingo ukuze ushintshe.';

it('keeps placeholders exactly as in the English', function () use ($booking): void {
    expect(fn () => $this->t->save($this->reviewer, 'zu', $booking, 'Ubhukhe ngomhla ka-{{ date }} no-{{ doctor }}.'))
        ->toThrow(ValidationException::class, 'Please keep {{ practice }}, {{ time }} exactly as in the English');
    expect(fn () => $this->t->save($this->reviewer, 'zu', $booking, '{{ practice }} {{ date }} {{ time }} {{ doctor }} {{ ticket }}'))
        ->toThrow(ValidationException::class, 'Please remove {{ ticket }}');
});

it('publishes a translation only when an assigned reviewer approves it, and sends it', function () use ($booking, $bookingZu): void {
    expect(fn () => $this->t->save($this->admin, 'zu', $booking, $bookingZu))->toThrow(ValidationException::class, 'not a reviewer');
    $this->t->save($this->reviewer, 'zu', $booking, $bookingZu);
    expect(TemplateResolver::resolve('booking.confirmation', 'sms', 'zu')['body'])->not->toBe($bookingZu);
    $this->t->approve($this->reviewer, 'zu', $booking);
    expect(TemplateResolver::resolve('booking.confirmation', 'sms', 'zu')['body'])->toBe($bookingZu);

    // Changing it resets the approval: English until approved again.
    $this->t->save($this->reviewer, 'zu', $booking, $bookingZu.' Siyabonga.');
    expect(TemplateResolver::resolve('booking.confirmation', 'sms', 'zu')['body'])->not->toContain('ubhukhe');
});

it('needs a clinical reviewer as well for medical wording', function (): void {
    $unit = 'message:lab.results_ready:sms';
    $text = '{{ practice }}: imiphumela yakho yaselabhorethri isilungile. Vula uhlelo lokusebenza lwe-Dr Business Flow ukuze uyibone.';
    $this->t->save($this->reviewer, 'zu', $unit, $text);
    $this->t->approve($this->reviewer, 'zu', $unit);
    expect(MessageTemplate::query()->where(['key' => 'lab.results_ready', 'channel' => 'sms', 'language' => 'zu'])->exists())->toBeFalse();
    expect(fn () => $this->t->approveClinical($this->reviewer, 'zu', $unit))->toThrow(ValidationException::class, 'Only a clinical reviewer');
    $this->t->approveClinical($this->clinical, 'zu', $unit);
    expect(TemplateResolver::resolve('lab.results_ready', 'sms', 'zu')['body'])->toBe($text);
});

it('publishes an email only when its subject and body are both approved', function (): void {
    $body = "Sawubona {{ patient }},\n\nUkuqokwa kwakho no-{{ doctor }} e-{{ practice }} kungomhla ka-{{ date }} ngo-{{ time }}.\n\n{{ practice }}";
    $this->t->save($this->reviewer, 'zu', 'message:booking.confirmation:email', $body);
    $this->t->approve($this->reviewer, 'zu', 'message:booking.confirmation:email');
    expect(MessageTemplate::query()->where(['key' => 'booking.confirmation', 'channel' => 'email', 'language' => 'zu'])->exists())->toBeFalse();
    $this->t->save($this->reviewer, 'zu', 'message:booking.confirmation:subject', 'Ukubhukha kwakho kuqinisekisiwe');
    $this->t->approve($this->reviewer, 'zu', 'message:booking.confirmation:subject');
    $email = TemplateResolver::resolve('booking.confirmation', 'email', 'zu');
    expect($email['subject'])->toBe('Ukubhukha kwakho kuqinisekisiwe')->and($email['body'])->toBe($body);
});

it('falls back to English when the English changes, until a reviewer re-checks', function () use ($booking, $bookingZu): void {
    $this->t->save($this->reviewer, 'zu', $booking, $bookingZu);
    $this->t->approve($this->reviewer, 'zu', $booking);
    $confirmed = [RequireRecentConfirmation::SESSION_KEY => now()->getTimestamp()];
    $this->actingAs($this->admin)->withSession($confirmed)->put('http://localhost/admin/messaging/templates', [
        'key' => 'booking.confirmation', 'channel' => 'sms', 'language' => 'en', 'body' => '{{ practice }}: you are booked for {{ date }} at {{ time }} with {{ doctor }}.',
    ])->assertRedirect()->assertSessionHasNoErrors()->assertSessionHas('success');
    expect(TemplateResolver::resolve('booking.confirmation', 'sms', 'zu')['body'])->toBe('{{ practice }}: you are booked for {{ date }} at {{ time }} with {{ doctor }}.');
    expect(fn () => $this->t->approve($this->reviewer, 'zu', $booking))->toThrow(ValidationException::class, 'English has changed');
    // The admin editor no longer writes other languages directly (they go through review).
    $this->actingAs($this->admin)->withSession($confirmed)->put('http://localhost/admin/messaging/templates', [
        'key' => 'booking.confirmation', 'channel' => 'sms', 'language' => 'zu', 'body' => 'x {{ practice }}',
    ])->assertSessionHasErrors('language');
});

it('invites a reviewer with a single-use link that grants only the invited languages', function (): void {
    $confirmed = [RequireRecentConfirmation::SESSION_KEY => now()->getTimestamp()];
    $this->actingAs($this->admin)->withSession($confirmed)->post('http://localhost/admin/languages/invite', [
        'name' => 'Nomsa Dlamini', 'email' => 'nomsa@reviewers.test', 'languages' => ['xh'], 'clinical' => true,
    ])->assertSessionHasNoErrors();
    preg_match('#/translate/invite/([A-Za-z0-9]+)#', $this->outbox->sent[0][2], $m);
    $this->post('http://localhost/logout');
    $this->flushSession();

    $this->get("http://localhost/translate/invite/{$m[1]}")->assertInertia(fn ($p) => $p->where('valid', true)->where('name', 'Nomsa Dlamini'));
    $this->post("http://localhost/translate/invite/{$m[1]}/register", ['email' => 'nomsa@reviewers.test', 'phone' => '0821230000',
        'password' => 'isiXhosa-2026', 'password_confirmation' => 'isiXhosa-2026'])->assertRedirect('/translate');
    $nomsa = User::query()->where('email', 'nomsa@reviewers.test')->firstOrFail();
    expect(TranslatorLanguage::query()->where('user_id', $nomsa->id)->get(['language', 'clinical'])->toArray())->toBe([['language' => 'xh', 'clinical' => true]]);
    $this->get('http://localhost/translate')->assertOk()->assertInertia(fn ($p) => $p->where('language', 'xh')->where('clinicalReviewer', true));
    $this->get('http://localhost/translate?language=zu')->assertForbidden();
    $this->get('http://localhost/admin/languages')->assertForbidden();

    $this->post('http://localhost/logout');
    $this->get("http://localhost/translate/invite/{$m[1]}")->assertInertia(fn ($p) => $p->where('valid', false));
    TranslatorInvitation::query()->update(['accepted_at' => null, 'expires_at' => now()->subMinute()]);
    $this->get("http://localhost/translate/invite/{$m[1]}")->assertInertia(fn ($p) => $p->where('valid', false));
});

it('sends a reviewer straight to the review screen after sign-in', function (): void {
    WalletSettings::put('security.two_factor_enabled', false);
    $this->post('http://localhost/login', ['login' => 'zulu@reviewers.test', 'password' => 'review-pass-2026'])->assertRedirect('/translate');
});

it('lets the super admin switch languages on and off, but never English', function (): void {
    $confirmed = [RequireRecentConfirmation::SESSION_KEY => now()->getTimestamp()];
    $this->actingAs($this->admin)->get('http://localhost/admin/languages')->assertOk()
        ->assertInertia(fn ($p) => $p->where('languages', fn ($l) => collect($l)->firstWhere('code', 'zu')['total'] === count(TranslationUnits::all())));
    $this->actingAs($this->admin)->withSession($confirmed)->post('http://localhost/admin/languages/zu', ['enabled' => true])->assertSessionHasNoErrors();
    expect(Language::query()->find('zu')?->enabled)->toBeTrue();
    $this->actingAs($this->admin)->withSession($confirmed)->post('http://localhost/admin/languages/en', ['enabled' => false])->assertStatus(422);
    expect(Translation::query()->where('language', 'zu')->count())->toBe(count(TranslationUnits::all()));
});

it('creates AI drafts only for untranslated texts, discarding any that lose a placeholder', function () use ($booking, $bookingZu): void {
    $confirmed = [RequireRecentConfirmation::SESSION_KEY => now()->getTimestamp()];
    $this->actingAs($this->admin)->withSession($confirmed)->post('http://localhost/admin/languages/zu/drafts')->assertSessionHasErrors('drafts');

    AiProvider::query()->create(['driver' => 'anthropic', 'kind' => 'notes', 'enabled' => true, 'credentials' => ['api_key' => 'sk-test']]);
    // A reviewer already started this one: AI must not overwrite it.
    $this->t->save($this->reviewer, 'zu', 'message:booking.reminder:sms', 'Isikhumbuzo esivela ku-{{ practice }}: {{ date }} ngo-{{ time }} no-{{ doctor }}.');
    Http::fake(['api.anthropic.com/*' => Http::response(['content' => [['type' => 'text', 'text' => json_encode([
        $booking => $bookingZu,
        'message:queue.next:sms' => '{{ practice }}: ithikithi lakho, sicela uze egumbini.', // lost {{ ticket }} and {{ room }}
        'message:booking.reminder:sms' => 'AI version {{ practice }} {{ date }} {{ time }} {{ doctor }}',
    ])]]])]);
    $this->actingAs($this->admin)->withSession($confirmed)->post('http://localhost/admin/languages/zu/drafts')->assertSessionHasNoErrors();

    $status = fn (string $unit) => Translation::query()->where(['language' => 'zu', 'unit' => $unit])->value('status');
    expect($status($booking))->toBe('ai_draft')
        ->and($status('message:queue.next:sms'))->toBe('missing')
        ->and($status('message:booking.reminder:sms'))->toBe('draft')
        ->and(Translation::query()->where(['language' => 'zu', 'unit' => 'message:booking.reminder:sms'])->value('text'))->not->toContain('AI version')
        // An AI draft is never sent until a reviewer approves it.
        ->and(TemplateResolver::resolve('booking.confirmation', 'sms', 'zu')['body'])->not->toBe($bookingZu);
    $this->t->approve($this->reviewer, 'zu', $booking);
    expect(TemplateResolver::resolve('booking.confirmation', 'sms', 'zu')['body'])->toBe($bookingZu);
});
