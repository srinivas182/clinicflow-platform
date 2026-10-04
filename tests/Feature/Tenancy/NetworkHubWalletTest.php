<?php

use App\Domains\Hub\Actions\NetworkIdentity;
use App\Domains\Hub\Models\HubConsent;
use App\Domains\Hub\Models\HubIdentity;
use App\Domains\Hub\Models\HubLink;
use App\Domains\Hub\Models\HubLinkRequest;
use App\Domains\Identity\Actions\AddStaffMember;
use App\Domains\Identity\Enums\StaffRole;
use App\Domains\Messaging\Contracts\MessageSender;
use App\Domains\Messaging\Support\LogMessageSender;
use App\Domains\Patients\Models\Patient;
use App\Domains\Platform\Enums\ProviderType;
use App\Domains\Platform\Models\Provider;
use App\Domains\Wallet\Actions\StartTopup;
use App\Domains\Wallet\Actions\WalletLedger;
use App\Domains\Wallet\Models\Wallet;
use App\Domains\Wallet\Models\WalletReservation;
use App\Domains\Wallet\Models\WalletTransaction;
use App\Domains\Wallet\Support\WalletSettings;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

uses(DatabaseMigrations::class);

beforeEach(function (): void {
    if (config('database.default') !== 'mysql') {
        $this->markTestSkipped('Tenancy tests require MySQL.');
    }

    $this->artisan('migrate:fresh', ['--database' => 'hub', '--path' => 'database/migrations/hub'])->assertSuccessful();
    $this->app->instance(MessageSender::class, $this->sms = new LogMessageSender);

    $this->clinicA = makeProvider('Sunrise Medical Centre', ProviderType::Clinic, 'sunrise.clinicflow.test');
    $this->clinicB = makeProvider('Ubuntu Kids Clinic', ProviderType::Clinic, 'ubuntu.clinicflow.test');
    // Fixed name: the page shows the signed-in user, and a random name must never collide with the patient's.
    $this->receptionB = User::factory()->create(['name' => 'Reception B', 'email' => 'reception.b@ubuntu.test']);
    $this->ownerA = User::factory()->create(['email' => 'owner@sunrise.test']);
    app(AddStaffMember::class)->handle($this->clinicB, $this->receptionB, StaffRole::Receptionist);
    app(AddStaffMember::class)->handle($this->clinicA, $this->ownerA, StaffRole::Owner);
});

afterEach(function (): void {
    tenancy()->end();
    Provider::query()->get()->each->delete();
});

function registerAt(Provider $provider, string $dob = '880412'): Patient
{
    return $provider->run(function () use ($provider, $dob): Patient {
        $patient = registerTestPatient('Thandi', $dob, null, '0825550147');
        app(NetworkIdentity::class)->register($patient, $provider);

        return $patient;
    });
}

it('creates a network identity and link when a person is new to the network', function (): void {
    $patient = registerAt($this->clinicA);
    $identity = HubIdentity::query()->sole();

    expect($identity->cell)->toBe('0825550147')
        ->and($identity->sa_id_hash)->not->toBeNull()
        ->and(HubLink::query()->where('tenant_id', $this->clinicA->id)->where('patient_id', $patient->id)->value('status'))->toBe('active')
        ->and(HubConsent::query()->where('tenant_id', $this->clinicA->id)->value('scope'))->toBe(HubConsent::LINK);
});

it('shows another practice only a masked match and links only with the patient\'s code', function (): void {
    registerAt($this->clinicA);
    $identity = HubIdentity::query()->sole();

    $this->actingAs($this->receptionB)->get('http://ubuntu.clinicflow.test/network?cell=0825550147')
        ->assertInertia(fn ($page) => $page->component('Patients/Network')->where('match.linked', false)->where('match.masked', fn ($m) => str_starts_with($m, 'T*** T***')))
        ->assertDontSee('Thandi');

    $this->actingAs($this->receptionB)->post("http://ubuntu.clinicflow.test/network/identities/{$identity->id}/request")->assertSessionHas('link_request_id');
    expect($this->sms->sent)->toHaveCount(1)->and($this->sms->sent[0]['recipient'])->toBe('0825550147')->and($this->sms->sent[0]['body'])->toContain('Ubuntu Kids Clinic');

    $request = HubLinkRequest::query()->sole();
    $request->forceFill(['code_hash' => Hash::make('123456')])->save();

    $this->actingAs($this->receptionB)->post('http://ubuntu.clinicflow.test/network/confirm', ['request_id' => $request->id, 'code' => '000000'])->assertSessionHasErrors('code');
    $this->actingAs($this->receptionB)->post('http://ubuntu.clinicflow.test/network/confirm', ['request_id' => $request->id, 'code' => '123456'])->assertSessionHasNoErrors();

    $this->clinicB->run(function () use ($identity): void {
        $local = Patient::query()->sole();
        expect($local->getAttribute('hub_identity_id'))->toBe($identity->id)
            ->and((bool) $local->getAttribute('needs_consent'))->toBeTrue();
    });
    expect(app(NetworkIdentity::class)->isLinked($identity, $this->clinicB->id))->toBeTrue()
        ->and(fn () => app(NetworkIdentity::class)->requestLink($identity, $this->clinicB))->toThrow(ValidationException::class)
        ->and(collect(app(NetworkIdentity::class)->linkedProviders($identity))->pluck('provider')->sort()->values()->all())->toBe(['Sunrise Medical Centre', 'Ubuntu Kids Clinic']);
});

it('lets the patient revoke a practice and withdraws the consent', function (): void {
    registerAt($this->clinicA);
    $identity = HubIdentity::query()->sole();

    app(NetworkIdentity::class)->revoke($identity, $this->clinicA->id);

    expect(app(NetworkIdentity::class)->isLinked($identity, $this->clinicA->id))->toBeFalse()
        ->and(HubConsent::query()->where('tenant_id', $this->clinicA->id)->whereNull('withdrawn_at')->count())->toBe(0);
});

it('blocks online bookings below the threshold and charges actual usage without cutting calls', function (): void {
    $wallet = Wallet::for($this->clinicA->id);
    $ledger = app(WalletLedger::class);

    expect(fn () => $ledger->reserve($wallet, 'appt-1', 'video', 15))->toThrow(ValidationException::class);

    $topup = app(StartTopup::class)->create($wallet, 100000);
    expect($topup->bonus_cents)->toBe(5000)->and($topup->vat_cents)->toBe(15000);
    $ledger->creditTopup($topup, 'paystack', 'ref-1');
    $ledger->creditTopup($topup->fresh(), 'paystack', 'ref-1');
    expect($wallet->fresh()?->balance_cents)->toBe(105000);

    $reservation = $ledger->reserve($wallet->fresh(), 'appt-1', 'video', 15);
    expect($reservation->amount_cents)->toBe(15 * 250)->and($wallet->fresh()?->reserved_cents)->toBe(3750);

    $ledger->capture($reservation, 20);
    expect($wallet->fresh()?->balance_cents)->toBe(105000 - 5000)
        ->and($wallet->fresh()?->reserved_cents)->toBe(0)
        ->and(fn () => $ledger->capture($reservation->fresh(), 5))->toThrow(ValidationException::class);

    $chat = $ledger->reserve($wallet->fresh(), 'appt-2', 'chat', 1);
    $ledger->release($chat, 'Patient cancelled');
    expect($wallet->fresh()?->reserved_cents)->toBe(0)
        ->and(WalletTransaction::query()->where('wallet_id', $wallet->id)->pluck('type')->all())->toBe(['topup', 'reserve', 'charge', 'reserve', 'release'])
        ->and(fn () => WalletTransaction::query()->firstOrFail()->forceFill(['amount_cents' => 1])->save())->toThrow(LogicException::class);
});

it('emails the owner once when usage drops the wallet below the minimum, and uses super-admin pricing', function (): void {
    WalletSettings::put('wallet.price_audio_per_minute_cents', 1000);
    $wallet = Wallet::for($this->clinicA->id);
    $wallet->forceFill(['balance_cents' => 20000])->save();
    $ledger = app(WalletLedger::class);

    $ledger->capture($ledger->reserve($wallet, 'a-1', 'audio', 3), 8);
    $ledger->capture(WalletReservation::create(['wallet_id' => $wallet->id, 'reference' => 'a-2', 'consult_type' => 'audio', 'amount_cents' => 0, 'status' => 'held']), 1);

    expect($wallet->fresh()?->balance_cents)->toBe(20000 - 8000 - 1000)
        ->and($wallet->fresh()?->acceptsOnlineBookings())->toBeFalse()
        ->and(collect($this->sms->sent)->where('channel', 'email')->where('recipient', 'owner@sunrise.test')->count())->toBe(1);
});

it('lets super admin set prices and threshold, and the owner see the wallet', function (): void {
    $admin = User::factory()->create();
    $admin->forceFill(['is_platform_admin' => true])->save();

    $this->actingAs($admin)->put('http://localhost/admin/wallet', [
        'video' => 3, 'audio' => 1.5, 'chat' => 15, 'threshold' => 200, 'packs' => [['amount' => 500, 'bonus' => 0], ['amount' => 1000, 'bonus' => 100]],
    ])->assertSessionHasNoErrors();

    expect(WalletSettings::priceCents('video', 10))->toBe(3000)->and(WalletSettings::thresholdCents())->toBe(20000)->and(WalletSettings::packs()[1])->toBe(['amount' => 100000, 'bonus' => 10000]);

    $this->actingAs($this->ownerA)->get('http://sunrise.clinicflow.test/settings/wallet')
        ->assertInertia(fn ($page) => $page->component('Settings/Wallet')->where('wallet.threshold', 200)->where('wallet.acceptsOnline', false));
    $this->actingAs($this->receptionB)->get('http://ubuntu.clinicflow.test/settings/wallet')->assertForbidden();
});
