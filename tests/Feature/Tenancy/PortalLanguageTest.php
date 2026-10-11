<?php

use App\Domains\Platform\Enums\ProviderType;
use App\Domains\Platform\Models\Provider;
use App\Domains\Translation\Actions\Translations;
use App\Domains\Translation\Models\Language;
use App\Domains\Translation\Models\TranslatorLanguage;
use App\Domains\Translation\Support\PortalText;
use App\Domains\Translation\Support\TranslationUnits;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;

uses(DatabaseMigrations::class);

beforeEach(function (): void {
    if (config('database.default') !== 'mysql') {
        $this->markTestSkipped('Tenancy tests require MySQL.');
    }
    makeProvider('Sunrise Medical Centre', ProviderType::Clinic, 'sunrise.clinicflow.test');
    $this->reviewer = User::factory()->create();
    $this->clinical = User::factory()->create();
    TranslatorLanguage::query()->create(['user_id' => $this->reviewer->id, 'language' => 'zu', 'clinical' => false]);
    TranslatorLanguage::query()->create(['user_id' => $this->clinical->id, 'language' => 'zu', 'clinical' => true]);
    $this->t = app(Translations::class);
});

afterEach(function (): void {
    tenancy()->end();
    Provider::query()->get()->each->delete();
});

it('shows the portal in English until a translation is approved and the language is on', function (): void {
    $this->get('http://sunrise.clinicflow.test/my/login')->assertInertia(fn ($p) => $p->where('portalText', fn ($texts) => count($texts) === count(PortalText::STRINGS)
        && $texts['portal.login.sign_in'] === 'Sign in'));

    Language::query()->whereKey('zu')->update(['enabled' => true]);
    $this->post('http://sunrise.clinicflow.test/my/display-language', ['language' => 'zu']);
    $this->t->save($this->reviewer, 'zu', 'ui:portal.login.sign_in', 'Ngena');
    $this->get('http://sunrise.clinicflow.test/my/login')->assertInertia(fn ($p) => $p->where('portalText', fn ($texts) => $texts['portal.login.sign_in'] === 'Sign in')); // draft only

    $this->t->approve($this->reviewer, 'zu', 'ui:portal.login.sign_in');
    $this->get('http://sunrise.clinicflow.test/my/login')->assertInertia(fn ($p) => $p->where('portalLanguage', 'zu')
        ->where('portalText', fn ($texts) => $texts['portal.login.sign_in'] === 'Ngena' && $texts['portal.login.send_code'] === 'Send code'));
});

it('needs a clinical approval as well for results-page wording', function (): void {
    Language::query()->whereKey('zu')->update(['enabled' => true]);
    $unit = 'ui:portal.results.your_doctor_reviews_results_before_you_s';
    $this->t->save($this->reviewer, 'zu', $unit, 'Udokotela wakho uhlola imiphumela ngaphambi kokuthi uyibone.');
    $this->t->approve($this->reviewer, 'zu', $unit);
    expect(PortalText::dictionary('zu')['portal.results.your_doctor_reviews_results_before_you_s'])->toBe('Your doctor reviews results before you see them.');
    $this->t->approveClinical($this->clinical, 'zu', $unit);
    expect(PortalText::dictionary('zu')['portal.results.your_doctor_reviews_results_before_you_s'])->toBe('Udokotela wakho uhlola imiphumela ngaphambi kokuthi uyibone.');
});

it('offers portal texts to reviewers alongside messages', function (): void {
    $units = TranslationUnits::all();
    expect(collect($units)->keys()->filter(fn ($k) => str_starts_with($k, 'ui:portal.'))->count())->toBe(count(PortalText::STRINGS))
        ->and($units['ui:portal.login.sign_in']['context'])->toBe('Patient portal — Login page')
        ->and($units['ui:portal.results.your_results']['medical'])->toBeTrue();
});
