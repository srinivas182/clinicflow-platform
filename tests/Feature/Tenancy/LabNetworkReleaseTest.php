<?php

use App\Domains\Billing\Models\Invoice;
use App\Domains\Hub\Actions\NetworkIdentity;
use App\Domains\Hub\Actions\NetworkLabs;
use App\Domains\Hub\Models\HubLabOrder;
use App\Domains\Identity\Actions\AddStaffMember;
use App\Domains\Identity\Enums\StaffRole;
use App\Domains\Identity\Models\Staff;
use App\Domains\Lab\Actions\LabCatalog;
use App\Domains\Lab\Actions\LabReleaseRules;
use App\Domains\Lab\Actions\LabWorkflow;
use App\Domains\Lab\Models\CatalogTest;
use App\Domains\Lab\Models\LabOrder;
use App\Domains\Messaging\Contracts\MessageSender;
use App\Domains\Messaging\Support\LogMessageSender;
use App\Domains\Patients\Enums\Channel;
use App\Domains\Patients\Enums\IdType;
use App\Domains\Patients\Models\Patient;
use App\Domains\Platform\Enums\ProviderType;
use App\Domains\Platform\Models\Provider;
use App\Domains\Platform\Models\Setting;
use App\Domains\Visits\Actions\TransitionVisit;
use App\Domains\Visits\Enums\PayerType;
use App\Domains\Visits\Enums\VisitStage;
use App\Models\User;
use Database\Seeders\ClinicalReferenceSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Validation\ValidationException;

uses(DatabaseMigrations::class);

beforeEach(function (): void {
    if (config('database.default') !== 'mysql') {
        $this->markTestSkipped('Tenancy tests require MySQL.');
    }

    $this->artisan('migrate:fresh', ['--database' => 'hub', '--path' => 'database/migrations/hub'])->assertSuccessful();
    $this->seed(ClinicalReferenceSeeder::class);
    $this->app->instance(MessageSender::class, new LogMessageSender);
    $this->clinic = makeProvider('Sunrise Medical Centre', ProviderType::Clinic, 'sunrise.clinicflow.test');
    $this->labProvider = makeProvider('Precise Pathology', ProviderType::Lab, 'precise.clinicflow.test');
    $add = app(AddStaffMember::class);
    foreach (['doctorUser' => StaffRole::Doctor, 'coverUser' => StaffRole::Doctor, 'tech1User' => StaffRole::LabTechnician, 'tech2User' => StaffRole::LabTechnician, 'managerUser' => StaffRole::Manager] as $k => $role) {
        $this->{$k} = User::factory()->create();
        $add->handle($this->clinic, $this->{$k}, $role);
    }
    foreach (['labTechA' => StaffRole::LabTechnician, 'labTechB' => StaffRole::LabTechnician, 'labOwner' => StaffRole::Owner, 'labManager' => StaffRole::Manager] as $k => $role) {
        $this->{$k} = User::factory()->create();
        $add->handle($this->labProvider, $this->{$k}, $role);
    }
    tenancy()->initialize($this->clinic);
    $this->doctor = Staff::query()->findOrFail($this->doctorUser->id);
    $this->tech1 = Staff::query()->findOrFail($this->tech1User->id);
    $this->tech2 = Staff::query()->findOrFail($this->tech2User->id);
    $this->lab = app(LabWorkflow::class);
});

afterEach(function (): void {
    tenancy()->end();
    Provider::query()->get()->each->delete();
});

/**
 * In-house order taken to "resulted" or "verified".
 *
 * @param  array<string, mixed>  $values
 * @param  array<string, string>  $flags
 */
function inHouse(object $t, array $codes, array $values, array $flags = [], bool $verify = true, ?Patient $patient = null): LabOrder
{
    $patient ??= Patient::query()->where('cell', '0825550147')->first() ?? registerTestPatient('Thandi', '880412');
    $visit = seenByDoctor($t, $patient, PayerType::Cash)->visit;
    $order = $t->lab->order($visit, $t->doctor, $codes);
    // Close the visit so the same patient can be seen again today.
    app(TransitionVisit::class)->handle($visit->fresh(), VisitStage::Done);
    $t->lab->collect($order, $t->tech1);
    $t->lab->enterResults($order->fresh(), $values, $t->tech1, $flags);
    if ($verify) {
        $t->lab->verify($order->fresh(), $t->tech2);
    }

    return $order->fresh();
}

it('keeps each lab\'s own templates and changes ranges only with a second person\'s approval', function (): void {
    $catalog = app(LabCatalog::class);
    $first = inHouse($this, ['K'], ['K' => 5.0]);
    expect($first->results()->sole()->reference)->toBe('3.50–5.10');

    $test = CatalogTest::query()->where('code', 'K')->sole();
    $change = $catalog->proposeRanges($test, [['sex' => null, 'age_min_months' => 0, 'age_max_months' => 1500, 'ref_low' => 3.5, 'ref_high' => 4.8, 'critical_low' => 2.8, 'critical_high' => 6.0]], $this->managerUser->id);
    expect(fn () => $catalog->approve($change, $this->managerUser->id))->toThrow(ValidationException::class);

    $catalog->approve($change, $this->doctorUser->id);
    expect($test->fresh()?->version)->toBe(2)
        ->and($first->results()->sole()->reference)->toBe('3.50–5.10');

    $second = inHouse($this, ['K'], ['K' => 5.0]);
    expect($second->results()->sole()->flag)->toBe('high');

    $this->labProvider->run(fn () => expect(app(LabCatalog::class)->importFromMaster(['K', 'HB', 'URINE-MC']))->toHaveCount(3)
        ->and(CatalogTest::query()->where('code', 'K')->sole()->version)->toBe(1));
});

it('flags by the patient\'s sex and age, rejects impossible values and lets the lab only raise', function (): void {
    $adult = inHouse($this, ['HB', 'K'], ['HB' => 11.0, 'K' => 6.5], ['K' => 'normal']);
    expect($adult->results()->where('test_code', 'HB')->value('flag'))->toBe('low')
        ->and($adult->results()->where('test_code', 'K')->value('flag'))->toBe('critical_high')
        ->and($adult->getAttribute('classification'))->toBe('critical');

    $child = Patient::create([
        'first_names' => 'Lwazi', 'surname' => 'Test', 'id_type' => IdType::None, 'date_of_birth' => now()->subYears(10),
        'sex' => 'male', 'cell' => '0826660000', 'no_cell' => false, 'preferred_language' => 'en', 'preferred_channel' => Channel::Sms,
    ]);
    $kid = inHouse($this, ['K'], ['K' => 4.9], [], true, $child);
    expect($kid->results()->sole()->flag)->toBe('high');

    expect(fn () => inHouse($this, ['K'], ['K' => 65]))->toThrow(ValidationException::class);

    $raised = inHouse($this, ['CRP'], ['CRP' => 2], ['CRP' => 'abnormal']);
    expect($raised->results()->sole()->flag)->toBe('abnormal')->and((bool) $raised->results()->sole()->raised_by_lab)->toBeTrue();

    expect(fn () => inHouse($this, ['URINE-MC'], ['URINE-MC' => 'Lots of bugs'], ['URINE-MC' => 'abnormal']))->toThrow(ValidationException::class)
        ->and(fn () => inHouse($this, ['URINE-MC'], ['URINE-MC' => 'Significant growth']))->toThrow(ValidationException::class);
    expect(inHouse($this, ['URINE-MC'], ['URINE-MC' => 'Significant growth'], ['URINE-MC' => 'abnormal'])->getAttribute('classification'))->toBe('abnormal');

    $pdfOnly = inHouse($this, ['GLU'], [], [], false);
})->throws(ValidationException::class);

it('treats a PDF-only result as unclassified', function (): void {
    $patient = registerTestPatient('Thandi', '880412');
    $order = $this->lab->order(seenByDoctor($this, $patient, PayerType::Cash)->visit, $this->doctor, ['GLU']);
    $this->lab->collect($order, $this->tech1);
    $this->lab->enterResults($order->fresh(), [], $this->tech1, [], 'lab-reports/x.pdf');

    expect($order->fresh()?->getAttribute('classification'))->toBe('unclassified');
});

it('holds results for the doctor, lets the patient ask after 24 hours, auto-releases normal at 48 and escalates the rest', function (): void {
    $rules = app(LabReleaseRules::class);
    $normal = inHouse($this, ['K'], ['K' => 4.2]);
    $abnormal = inHouse($this, ['CHOL'], ['CHOL' => 6.4]);
    $critical = inHouse($this, ['K'], ['K' => 6.8]);

    tenancy()->end();
    $page = fn () => $this->withSession(['portal_cell' => '0825550147'])->get('http://sunrise.clinicflow.test/my/results');
    $page()->assertInertia(fn ($p) => $p->component('Portal/Results')->where('orders.0.results', [])->where('orders.0.canRequest', false));
    $this->withSession(['portal_cell' => '0825550147'])->get("http://sunrise.clinicflow.test/my/results/{$normal->id}/download/report")->assertForbidden();
    tenancy()->initialize($this->clinic);

    expect(fn () => $rules->patientRequest($normal->fresh()))->toThrow(ValidationException::class);
    $this->travel(25)->hours();
    $rules->patientRequest($normal->fresh());
    expect($normal->fresh()?->getAttribute('patient_requested_at'))->not->toBeNull();

    $this->travel(2)->hours();
    expect($rules->run())->toBe(['released' => 0, 'escalated' => 1]);

    $this->travel(22)->hours();
    expect($rules->run())->toBe(['released' => 1, 'escalated' => 1])
        ->and($normal->fresh()?->status)->toBe('released')
        ->and($normal->fresh()?->getAttribute('doctor_note'))->toBe(LabReleaseRules::NORMAL_NOTE)
        ->and($abnormal->fresh()?->status)->toBe('verified')
        ->and($abnormal->fresh()?->getAttribute('escalated_at'))->not->toBeNull()
        ->and($critical->fresh()?->getAttribute('escalated_at'))->not->toBeNull();

    tenancy()->end();
    $this->withSession(['portal_cell' => '0825550147'])->get("http://sunrise.clinicflow.test/my/results/{$normal->id}/download/report")
        ->assertOk()->assertHeader('Content-Type', 'application/pdf');
});

it('releases abnormal results automatically only when the practice allows it, never critical', function (): void {
    Setting::put('lab', 'auto_release_abnormal', true);
    $abnormal = inHouse($this, ['CHOL'], ['CHOL' => 6.4]);
    $critical = inHouse($this, ['K'], ['K' => 6.8]);
    $this->travel(49)->hours();
    app(LabReleaseRules::class)->run();

    expect($abnormal->fresh()?->status)->toBe('released')
        ->and($abnormal->fresh()?->getAttribute('doctor_note'))->toBe(LabReleaseRules::ABNORMAL_NOTE)
        ->and($critical->fresh()?->status)->toBe('verified');
});

it('lets the doctor hold values back to discuss, and the covering doctor act while away', function (): void {
    $order = inHouse($this, ['HBA1C'], ['HBA1C' => 9.1]);
    $this->lab->discuss($order, $this->doctor, 'Please book a follow-up so we can discuss your results.');

    tenancy()->end();
    $this->withSession(['portal_cell' => '0825550147'])->get('http://sunrise.clinicflow.test/my/results')
        ->assertInertia(fn ($p) => $p->where('orders.0.results', [])->where('orders.0.note', 'Please book a follow-up so we can discuss your results.'));

    $this->actingAs($this->coverUser)->post("http://sunrise.clinicflow.test/lab-orders/{$order->id}/release")->assertForbidden();
    $this->actingAs($this->doctorUser)->put('http://sunrise.clinicflow.test/results/cover', ['covering_staff_id' => $this->coverUser->id, 'away_until' => now()->addWeek()->toDateString()]);
    $this->actingAs($this->coverUser)->get('http://sunrise.clinicflow.test/results')->assertInertia(fn ($p) => $p->where('orders.0.covering', true));
    $this->actingAs($this->coverUser)->post("http://sunrise.clinicflow.test/lab-orders/{$order->id}/release", ['note' => 'Seen after your visit'])->assertSessionHasNoErrors();

    $this->clinic->run(fn () => expect($order->fresh()?->status)->toBe('released'));
});

it('sends network lab requests for linked patients and delivers results back without keeping them in the Hub', function (): void {
    $this->labProvider->run(function (): void {
        app(LabCatalog::class)->importFromMaster(['K', 'HB']);
        Setting::put('lab', 'home_collection', true);
        Setting::put('lab', 'home_fee_cents', 18000);
    });
    $patient = registerTestPatient('Thandi', '880412');
    $visit = seenByDoctor($this, $patient, PayerType::Cash)->visit;
    $labs = app(NetworkLabs::class);

    expect(fn () => $labs->send($visit, $this->doctor, $this->clinic, $this->labProvider->id, ['K']))->toThrow(ValidationException::class);
    app(NetworkIdentity::class)->register($patient->fresh(), $this->clinic);
    expect(fn () => $labs->send($visit, $this->doctor, $this->clinic, $this->labProvider->id, ['CHOL']))->toThrow(ValidationException::class);

    $home = ['address' => '12 Vilakazi St, Soweto', 'date' => now()->addDay()->toDateString(), 'window' => '08:00–10:00'];
    $order = $labs->send($visit, $this->doctor, $this->clinic, $this->labProvider->id, ['K', 'HB'], $home);
    expect($order->status)->toBe('sent')->and(HubLabOrder::query()->sole()->payload['home_collection'])->toBe($home);
    tenancy()->end();

    $this->labProvider->run(function () use ($labs): void {
        $hub = HubLabOrder::query()->sole();
        $labOrder = $labs->accept($hub, $this->labProvider);
        $patient = Patient::query()->sole();
        expect($patient->needs_consent)->toBeTrue()
            ->and(Invoice::query()->sole()->total_cents)->toBe(11000 + 18000 + 18000);

        $a = Staff::query()->findOrFail($this->labTechA->id);
        $b = Staff::query()->findOrFail($this->labTechB->id);
        $wf = app(LabWorkflow::class);
        $wf->collect($labOrder, $a);
        $wf->enterResults($labOrder->fresh(), ['K' => 4.2, 'HB' => 13.0], $a);
        $wf->verify($labOrder->fresh(), $b);
    });

    $this->clinic->run(function () use ($order): void {
        $issuer = $order->fresh();
        expect($issuer?->status)->toBe('verified')
            ->and($issuer?->getAttribute('classification'))->toBe('normal')
            ->and($issuer?->results()->where('test_code', 'K')->value('value'))->toEqual('4.20');
    });
    expect(HubLabOrder::query()->sole()->result_payload)->toBeNull()
        ->and(HubLabOrder::query()->sole()->status)->toBe('resulted');

    $this->actingAs($this->doctorUser)->get('http://sunrise.clinicflow.test/results')->assertInertia(fn ($p) => $p->where('orders.0.id', $order->id));
});

it('releases patient-requested lab tests straight to the patient after verification', function (): void {
    tenancy()->end();
    $this->labProvider->run(function (): void {
        $patient = registerTestPatient('Thandi', '880412');
        $wf = app(LabWorkflow::class);
        $a = Staff::query()->findOrFail($this->labTechA->id);
        $order = $wf->orderSelf($patient, ['CHOL'], $a);
        $wf->collect($order, $a);
        $wf->enterResults($order->fresh(), ['CHOL' => 4.1], $a);
        $wf->verify($order->fresh(), Staff::query()->findOrFail($this->labTechB->id));

        expect($order->fresh()?->status)->toBe('released');
    });
});
