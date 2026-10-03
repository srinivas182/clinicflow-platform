<?php

use App\Domains\Billing\Actions\AddInvoiceLine;
use App\Domains\Billing\Enums\LineKind;
use App\Domains\Billing\Models\Invoice;
use App\Domains\Clinical\Models\Consultation;
use App\Domains\Hub\Actions\PharmacyComparison;
use App\Domains\Identity\Actions\AddStaffMember;
use App\Domains\Identity\Enums\StaffRole;
use App\Domains\Identity\Models\Staff;
use App\Domains\Messaging\Actions\SendMessage;
use App\Domains\Messaging\Contracts\MessageSender;
use App\Domains\Messaging\Support\LogMessageSender;
use App\Domains\Messaging\WhatsApp\WhatsAppClient;
use App\Domains\Messaging\WhatsApp\WhatsAppProvider;
use App\Domains\Messaging\WhatsApp\WhatsAppTemplate;
use App\Domains\Patients\Enums\Channel;
use App\Domains\Patients\Models\Patient;
use App\Domains\Pharmacy\Actions\ReceiveStock;
use App\Domains\Pharmacy\Delivery\CourierAccount;
use App\Domains\Pharmacy\Delivery\CourierPartner;
use App\Domains\Pharmacy\Delivery\Deliveries;
use App\Domains\Platform\Enums\ProviderType;
use App\Domains\Platform\Enums\SubscriptionStatus;
use App\Domains\Platform\Models\Package;
use App\Domains\Platform\Models\Provider;
use App\Domains\Platform\Models\Setting;
use App\Domains\Platform\Models\Subscription;
use App\Domains\Prescribing\Models\Medicine;
use App\Domains\Prescribing\Models\Prescription;
use App\Domains\Visits\Enums\PayerType;
use App\Domains\Wallet\Models\Wallet;
use App\Models\User;
use Database\Seeders\ClinicalReferenceSeeder;
use Database\Seeders\PackageSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

uses(DatabaseMigrations::class);

beforeEach(function (): void {
    if (config('database.default') !== 'mysql') {
        $this->markTestSkipped('Tenancy tests require MySQL.');
    }

    $this->artisan('migrate:fresh', ['--database' => 'hub', '--path' => 'database/migrations/hub'])->assertSuccessful();
    $this->seed([PackageSeeder::class, ClinicalReferenceSeeder::class]);
    $this->app->instance(MessageSender::class, $this->sms = new LogMessageSender);
    $this->clinic = makeProvider('Sunrise Medical Centre', ProviderType::Clinic, 'sunrise.clinicflow.test');
    $this->doctorUser = User::factory()->create();
    app(AddStaffMember::class)->handle($this->clinic, $this->doctorUser, StaffRole::Doctor);
    $this->sub = Subscription::create(['tenant_id' => $this->clinic->id, 'package_id' => Package::query()->where('code', 'clinic-pro')->value('id'), 'status' => SubscriptionStatus::Active, 'current_period_ends_at' => now()->addMonth(), 'addons' => []]);
    Wallet::for($this->clinic->id)->forceFill(['balance_cents' => 10000])->save();
    tenancy()->initialize($this->clinic);
    $this->doctor = Staff::query()->findOrFail($this->doctorUser->id);
});

afterEach(function (): void {
    tenancy()->end();
    Provider::query()->get()->each->delete();
});

function whatsappReady(object $t): Patient
{
    WhatsAppProvider::create(['driver' => 'meta', 'enabled' => true, 'sender' => '109876', 'credentials' => ['access_token' => 'META-TOKEN', 'waba_id' => 'WABA1']]);
    WhatsAppTemplate::create(['message_key' => 'booking.confirmation', 'template_name' => 'booking_confirmation', 'language' => 'en', 'category' => 'utility', 'status' => 'approved']);
    $patient = registerTestPatient('Thandi', '880412', null, '0825550147');
    $patient->forceFill(['preferred_channel' => Channel::WhatsApp, 'whatsapp_opt_in_at' => now()])->save();

    return $patient;
}

function sendBooking(): bool
{
    return app(SendMessage::class)->template('booking.confirmation', 'sms', '0825550147', ['patient' => 'Thandi', 'date' => '5 Oct', 'time' => '09:00', 'doctor' => 'Dr N']);
}

it('sends by WhatsApp only with the add-on, opt-in, an approved template and wallet funds, and charges the wallet', function (): void {
    $patient = whatsappReady($this);
    Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.1']]])]);

    expect(sendBooking())->toBeTrue()->and(count($this->sms->sent))->toBe(1);
    Http::assertNothingSent();

    $this->sub->forceFill(['addons' => ['whatsapp']])->save();
    expect(sendBooking())->toBeTrue()->and(count($this->sms->sent))->toBe(1)
        ->and(Wallet::for($this->clinic->id)->balance_cents)->toBe(10000 - 35)
        ->and(DB::table('message_log')->where('channel', 'whatsapp')->count())->toBe(1);
    Http::assertSent(fn ($r) => str_contains($r->url(), '/109876/messages') && $r['template']['name'] === 'booking_confirmation' && $r['to'] === '27825550147'
        && $r->header('Authorization')[0] === 'Bearer META-TOKEN');

    $patient->forceFill(['whatsapp_opt_in_at' => null])->save();
    sendBooking();
    expect(count($this->sms->sent))->toBe(2);

    $patient->forceFill(['whatsapp_opt_in_at' => now()])->save();
    Wallet::for($this->clinic->id)->forceFill(['balance_cents' => 10])->save();
    sendBooking();
    expect(count($this->sms->sent))->toBe(3);
});

it('falls back to SMS without charging when WhatsApp fails, and syncs template approvals from Meta', function (): void {
    whatsappReady($this);
    $this->sub->forceFill(['addons' => ['whatsapp']])->save();
    WhatsAppTemplate::query()->update(['status' => 'pending']);
    Http::fake([
        'graph.facebook.com/v20.0/WABA1/message_templates*' => Http::response(['data' => [['name' => 'booking_confirmation', 'status' => 'APPROVED']]]),
        'graph.facebook.com/*' => Http::response(['error' => 'down'], 500),
    ]);

    sendBooking();
    expect(count($this->sms->sent))->toBe(1);

    expect(app(WhatsAppClient::class)->syncMeta(WhatsAppProvider::query()->sole()))->toBe(1)
        ->and(WhatsAppTemplate::query()->sole()->status)->toBe('approved');
    sendBooking();
    expect(count($this->sms->sent))->toBe(2)->and(Wallet::for($this->clinic->id)->balance_cents)->toBe(10000);
});

it('applies the delivery fee rule, guards scheduled medicine and confirms delivery with the patient\'s code', function (): void {
    $visit = seenByDoctor($this, registerTestPatient('Thandi', '880412'), PayerType::Cash)->visit;
    $invoice = Invoice::query()->where('visit_id', $visit->id)->sole();
    app(AddInvoiceLine::class)->handle($invoice, LineKind::Medicine, 'Metformin 500mg x60', 30000);
    $deliveries = app(Deliveries::class);

    expect(fn () => $deliveries->request($visit, 'tcg', '12 Vilakazi St'))->toThrow(ValidationException::class);
    CourierPartner::create(['driver' => 'tcg', 'enabled' => true]);
    CourierAccount::create(['driver' => 'tcg', 'account_ref' => 'TCG-778']);

    $before = $invoice->fresh()?->total_cents;
    $delivery = $deliveries->request($visit, 'tcg', '12 Vilakazi St, Soweto');
    expect($delivery->payer)->toBe('patient')->and($invoice->fresh()?->total_cents)->toBe($before + 6500);
    preg_match('/code[^0-9]*(\d{4})/', (string) end($this->sms->sent)['body'], $m);

    $deliveries->book($delivery, 'WB123456');
    $deliveries->update($delivery->fresh(), 'collected');
    expect(fn () => $deliveries->confirm($delivery->fresh(), '0000'))->toThrow(ValidationException::class);
    $deliveries->confirm($delivery->fresh(), $m[1]);
    expect($delivery->fresh()?->status)->toBe('delivered');

    app(AddInvoiceLine::class)->handle($invoice->fresh(), LineKind::Medicine, 'Insulin', 30000);
    expect($deliveries->request($visit, 'manual', '12 Vilakazi St')->payer)->toBe('practice');

    $consult = Consultation::query()->where('visit_id', $visit->id)->sole();
    $rx = Prescription::create(['consultation_id' => $consult->id, 'patient_id' => $visit->patient_id, 'prescriber_staff_id' => $this->doctor->id, 'version' => 1, 'status' => Prescription::SIGNED, 'signed_at' => now()]);
    $tramadol = Medicine::query()->where('name', 'Tramadol')->sole();
    $rx->items()->create(['medicine_id' => $tramadol->id, 'nappi_code' => $tramadol->nappi_code, 'description' => 'Tramadol 50 mg', 'schedule' => 'S6', 'dose' => '1 as needed', 'quantity' => 10, 'repeats' => 0]);
    expect(fn () => $deliveries->request($visit, 'manual', '12 Vilakazi St'))->toThrow(ValidationException::class);
    Setting::put('delivery', 'allow_scheduled', true);
    expect($deliveries->request($visit, 'manual', '12 Vilakazi St')->status)->toBe('requested');
});

it('compares network pharmacies by published stock and estimated price', function (): void {
    tenancy()->end();
    $a = makeProvider('Corner Pharmacy', ProviderType::Pharmacy, 'corner.clinicflow.test');
    $b = makeProvider('Main Road Pharmacy', ProviderType::Pharmacy, 'mainroad.clinicflow.test');
    $metformin = Medicine::query()->where('name', 'Metformin')->sole();
    $amlodipine = Medicine::query()->where('name', 'Amlodipine')->sole();
    $a->run(function () use ($metformin, $amlodipine): void {
        Setting::put('pharmacy', 'publish_stock', true);
        app(ReceiveStock::class)->handle($metformin->id, 'M1', now()->addYear(), 100, 150);
        app(ReceiveStock::class)->handle($amlodipine->id, 'A1', now()->addYear(), 50, 300);
    });
    $b->run(fn () => app(ReceiveStock::class)->handle($metformin->id, 'M2', now()->addYear(), 100, 120));

    $comparison = app(PharmacyComparison::class);
    expect($comparison->publish($a))->toBe(2)->and($comparison->publish($b))->toBe(0);

    $rows = $comparison->compare([
        ['nappi_code' => $metformin->nappi_code, 'quantity' => 60, 'description' => 'Metformin'],
        ['nappi_code' => $amlodipine->nappi_code, 'quantity' => 30, 'description' => 'Amlodipine'],
    ]);
    expect($rows[0]['name'])->toBe('Corner Pharmacy')->and($rows[0]['has_all'])->toBeTrue()->and($rows[0]['estimate_cents'])->toBe(60 * 150 + 30 * 300)
        ->and(collect($rows)->firstWhere('name', 'Main Road Pharmacy')['publishes'])->toBeFalse();
});

it('charges each WhatsApp message separately even when a batch sends several in the same second', function (): void {
    whatsappReady($this);
    $this->sub->forceFill(['addons' => ['whatsapp']])->save();
    Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.x']]])]);
    $this->freezeTime();

    expect(sendBooking())->toBeTrue()->and(sendBooking())->toBeTrue()->and(sendBooking())->toBeTrue()
        ->and(Wallet::for($this->clinic->id)->balance_cents)->toBe(10000 - 3 * 35)
        ->and(DB::table('message_log')->where('channel', 'whatsapp')->count())->toBe(3);
});

it('makes WhatsApp the patient\'s channel when staff record opt-in, and lets patients opt in or out in the portal', function (): void {
    $patient = registerTestPatient('Thandi', '880412', null, '0825550147');
    $this->sub->forceFill(['addons' => ['whatsapp']])->save();
    $receptionist = User::factory()->create();
    app(AddStaffMember::class)->handle($this->clinic, $receptionist, StaffRole::Receptionist);
    tenancy()->end();

    $this->actingAs($receptionist)->post("http://sunrise.clinicflow.test/patients/{$patient->id}/whatsapp", ['opt_in' => true])->assertSessionHasNoErrors();
    $this->clinic->run(fn () => expect($patient->fresh()?->preferred_channel)->toBe(Channel::WhatsApp)->and($patient->fresh()?->getAttribute('whatsapp_opt_in_at'))->not->toBeNull());

    $this->withSession(['portal_cell' => '0825550147'])->post('http://sunrise.clinicflow.test/my/whatsapp', ['opt_in' => false])->assertSessionHasNoErrors();
    $this->clinic->run(fn () => expect($patient->fresh()?->preferred_channel)->toBe(Channel::Sms)->and($patient->fresh()?->getAttribute('whatsapp_opt_in_at'))->toBeNull());

    $this->withSession(['portal_cell' => '0825550147'])->get('http://sunrise.clinicflow.test/my/care')
        ->assertInertia(fn ($p) => $p->where('whatsapp.available', true)->where('whatsapp.optedIn', false));
});
