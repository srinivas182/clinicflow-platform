<?php

use App\Domains\Messaging\Contracts\MessageSender;
use App\Domains\Messaging\Models\MessageTemplate;
use App\Domains\Patients\Models\Patient;
use App\Domains\Platform\Enums\ProviderType;
use App\Domains\Platform\Models\Provider;
use App\Domains\Translation\Models\Language;
use App\Domains\Translation\Support\Languages;
use Illuminate\Foundation\Testing\DatabaseMigrations;

uses(DatabaseMigrations::class);

beforeEach(function (): void {
    if (config('database.default') !== 'mysql') {
        $this->markTestSkipped('Tenancy tests require MySQL.');
    }
    $this->outbox = new class implements MessageSender
    {
        /** @var list<string> */
        public array $sent = [];

        public function send(string $channel, string $recipient, ?string $subject, string $body): bool
        {
            $this->sent[] = $body;

            return true;
        }
    };
    $this->app->instance(MessageSender::class, $this->outbox);
    $this->clinic = makeProvider('Sunrise Medical Centre', ProviderType::Clinic, 'sunrise.clinicflow.test');
    $this->patient = $this->clinic->run(fn () => registerTestPatient('Thandi', '880412'));
    // An approved isiZulu sign-in code message, as published by the review process.
    MessageTemplate::query()->create(['key' => 'portal.sign_in_code', 'channel' => 'sms', 'language' => 'zu',
        'body' => '{{ practice }}: ikhodi yakho yokungena ithi {{ code }}. Iphelelwa yisikhathi emizuzwini emi-5. Ungayabelani nabanye.']);
});

afterEach(function (): void {
    tenancy()->end();
    Provider::query()->get()->each->delete();
});

it('uses the patient\'s language only when it is switched on, otherwise English', function (): void {
    expect(Languages::for('zu'))->toBe('en');
    Language::query()->whereKey('zu')->update(['enabled' => true]);
    expect(Languages::for('zu'))->toBe('zu')->and(Languages::for('xh'))->toBe('en')->and(Languages::for(null))->toBe('en');
});

it('sends the portal sign-in code in the patient\'s language', function (): void {
    $this->clinic->run(fn () => Patient::query()->whereKey($this->patient->id)->update(['preferred_language' => 'zu']));
    $this->post('http://sunrise.clinicflow.test/my/login', ['cell' => '0825550147']);
    expect($this->outbox->sent[0])->toContain('your sign-in code is'); // isiZulu not switched on yet: English

    Language::query()->whereKey('zu')->update(['enabled' => true]);
    $this->post('http://sunrise.clinicflow.test/my/login', ['cell' => '0825550147']);
    expect($this->outbox->sent[1])->toContain('ikhodi yakho yokungena');
});

it('lets a signed-in patient choose the language of their messages, from languages that are on', function (): void {
    Language::query()->whereKey('zu')->update(['enabled' => true]);
    $portal = fn () => $this->withSession(['portal_cell' => '0825550147']);
    $portal()->get('http://sunrise.clinicflow.test/my')->assertInertia(fn ($p) => $p->where('language', 'en')
        ->where('languages', fn ($l) => collect($l)->pluck('code')->all() === ['en', 'zu']));
    $portal()->post('http://sunrise.clinicflow.test/my/language', ['language' => 'xh'])->assertSessionHasErrors('language');
    $portal()->post('http://sunrise.clinicflow.test/my/language', ['language' => 'zu'])->assertSessionHasNoErrors();
    expect($this->clinic->run(fn () => Patient::query()->whereKey($this->patient->id)->value('preferred_language')))->toBe('zu');
});
