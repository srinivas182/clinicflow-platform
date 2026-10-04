<?php

use App\Domains\Identity\Actions\AddStaffMember;
use App\Domains\Identity\Enums\StaffRole;
use App\Domains\Platform\Enums\ProviderType;
use App\Domains\Platform\Models\Provider;
use App\Domains\Platform\Models\Setting;
use App\Domains\Wellness\Actions\CorporateWellness;
use App\Domains\Wellness\Actions\EmployerReporting;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

uses(DatabaseMigrations::class);

beforeEach(function (): void {
    if (config('database.default') !== 'mysql') {
        $this->markTestSkipped('Tenancy tests require MySQL.');
    }
    $this->clinic = makeProvider('Sunrise Medical Centre', ProviderType::Clinic, 'sunrise.clinicflow.test');
    $this->nurseUser = User::factory()->create();
    $this->receptionUser = User::factory()->create();
    app(AddStaffMember::class)->handle($this->clinic, $this->nurseUser, StaffRole::Nurse);
    app(AddStaffMember::class)->handle($this->clinic, $this->receptionUser, StaffRole::Receptionist);
});

afterEach(function (): void {
    tenancy()->end();
    Provider::query()->get()->each->delete();
});

function wellnessDay(object $t, int $perSlot = 1): array
{
    return $t->clinic->run(function () use ($perSlot): array {
        $w = app(CorporateWellness::class);
        $account = $w->saveAccount(null, ['name' => 'Acme Mining', 'contact_name' => 'HR', 'contact_email' => 'hr@acme.test', 'contact_phone' => null, 'billing_address' => null, 'vat_number' => null, 'rate_cents' => 35000]);
        $event = $w->createEvent($account, 'Acme wellness day', 'Acme HQ', (string) CarbonImmutable::parse('tomorrow 09:00'), (string) CarbonImmutable::parse('tomorrow 10:00'), 30, $perSlot, ['bp', 'glucose', 'cholesterol', 'bmi']);

        return [$event, (string) DB::table('wellness_events')->where('id', $event)->value('token')];
    });
}

it('lets employees register themselves with their own consent, into free slots only', function (): void {
    [$event, $token] = wellnessDay($this);
    $this->clinic->run(fn () => registerTestPatient('Thandi', '880412', null, '0825550147'));
    $url = "http://sunrise.clinicflow.test/wellness/{$token}";
    $slot = (string) CarbonImmutable::parse('tomorrow 09:00');

    $this->get($url)->assertOk()->assertInertia(fn ($p) => $p->component('Wellness/Register')->has('slots', 2)->where('company', 'Acme Mining'));
    $this->post($url, ['first_names' => 'Thandi', 'surname' => 'Test', 'date_of_birth' => '1988-04-12', 'cell' => '0825550147', 'slot_at' => $slot])->assertSessionHasErrors('consent');
    $this->post($url, ['first_names' => 'Thandi', 'surname' => 'Test', 'date_of_birth' => '1988-04-12', 'cell' => '0825550147', 'slot_at' => $slot, 'consent' => '1'])->assertSessionHasNoErrors();
    $this->post($url, ['first_names' => 'Sipho', 'surname' => 'Dlamini', 'id_number' => saId('850101', '5547'), 'cell' => '0821112222', 'slot_at' => $slot, 'consent' => '1'])->assertSessionHasErrors('slot_at');
    $this->post($url, ['first_names' => 'Sipho', 'surname' => 'Dlamini', 'id_number' => saId('850101', '5547'), 'cell' => '0821112222', 'slot_at' => (string) CarbonImmutable::parse('tomorrow 09:30'), 'consent' => '1'])->assertSessionHasNoErrors();

    $this->clinic->run(function () use ($event): void {
        expect(DB::table('patients')->where('cell', '0825550147')->count())->toBe(1)
            ->and(DB::table('patients')->where('cell', '0821112222')->value('surname'))->toBe('Dlamini')
            ->and(DB::table('wellness_registrations')->where('wellness_event_id', $event)->count())->toBe(2)
            ->and(fn () => app(CorporateWellness::class)->register((string) DB::table('wellness_events')->value('token'), ['first_names' => 'Thandi', 'surname' => 'Test', 'id_number' => null,
                'date_of_birth' => '1988-04-12', 'cell' => '0825550147', 'email' => null, 'slot_at' => (string) CarbonImmutable::parse('tomorrow 09:30'), 'consent' => true]))->toThrow(ValidationException::class);
    });
});

it('records screenings with risk flags and shows each employee only their own results', function (): void {
    [, $token] = wellnessDay($this, 2);
    $this->post("http://sunrise.clinicflow.test/wellness/{$token}", ['first_names' => 'Thandi', 'surname' => 'Test', 'date_of_birth' => '1988-04-12', 'cell' => '0825550147',
        'slot_at' => (string) CarbonImmutable::parse('tomorrow 09:00'), 'consent' => '1'])->assertSessionHasNoErrors();
    $registration = $this->clinic->run(fn () => (int) DB::table('wellness_registrations')->value('id'));

    $this->actingAs($this->receptionUser)->post("http://sunrise.clinicflow.test/corporate-wellness/registrations/{$registration}/screen", ['bp_systolic' => 150, 'bp_diastolic' => 95])->assertForbidden();
    $this->actingAs($this->nurseUser)->post("http://sunrise.clinicflow.test/corporate-wellness/registrations/{$registration}/screen", [
        'bp_systolic' => 150, 'bp_diastolic' => 95, 'glucose' => 6.1, 'cholesterol' => 5.4, 'height_cm' => 165, 'weight_kg' => 82,
    ])->assertSessionHasNoErrors();

    $this->clinic->run(function () use ($registration): void {
        $row = DB::table('wellness_screenings')->where('wellness_registration_id', $registration)->first();
        expect(json_decode((string) $row->flags, true))->toBe(['bp_high', 'cholesterol_raised', 'bmi_obese'])
            ->and((float) $row->bmi)->toBe(30.1)
            ->and(DB::table('message_log')->where('recipient', '0825550147')->where('body', 'like', '%follow-up%')->exists())->toBeTrue();
    });

    $this->withSession(['portal_cell' => '0825550147'])->get('http://sunrise.clinicflow.test/my/care')
        ->assertInertia(fn ($p) => $p->where('wellness.0.bp', '150/95')->where('wellness.0.flags', ['bp_high', 'cholesterol_raised', 'bmi_obese']));
});

it('classifies screening values with the documented thresholds', function (): void {
    expect(CorporateWellness::flags(120, 80, 5.0, 4.2, 22.0))->toBe([])
        ->and(CorporateWellness::flags(132, 80, 8.0, 6.5, 26.0))->toBe(['bp_elevated', 'glucose_raised', 'cholesterol_high', 'bmi_overweight'])
        ->and(CorporateWellness::flags(null, null, 12.0, null, 17.0))->toBe(['glucose_high', 'bmi_underweight']);
});

/** Registers and screens $n employees for the event; $cholesterolFor limits how many get a cholesterol value. */
function screenEmployees(object $t, string $token, int $n, int $cholesterolFor): void
{
    $t->clinic->run(function () use ($token, $n, $cholesterolFor, $t): void {
        $w = app(CorporateWellness::class);
        for ($i = 0; $i < $n; $i++) {
            $reg = $w->register($token, ['first_names' => "Employee{$i}", 'surname' => 'Worker', 'id_number' => null, 'date_of_birth' => '1990-01-01',
                'cell' => '08200000'.str_pad((string) $i, 2, '0', STR_PAD_LEFT), 'email' => null, 'slot_at' => (string) CarbonImmutable::parse('tomorrow 09:00'), 'consent' => true]);
            $w->screen($reg, ['bp_systolic' => $i < 3 ? 150 : 120, 'bp_diastolic' => $i < 3 ? 95 : 78, 'glucose' => 5.2,
                'cholesterol' => $i < $cholesterolFor ? 5.5 : null, 'height_cm' => 170, 'weight_kg' => 70], $t->nurseUser->id);
        }
    });
}

it('invoices the employer once per event at the contracted rate, adding VAT only when the practice is VAT registered', function (): void {
    [$event, $token] = wellnessDay($this, 20);
    $reporting = app(EmployerReporting::class);
    $this->clinic->run(fn () => expect(fn () => $reporting->invoice($event))->toThrow(ValidationException::class));
    screenEmployees($this, $token, 2, 0);

    $this->clinic->run(function () use ($event, $reporting): void {
        $id = $reporting->invoice($event);
        $inv = DB::table('corporate_invoices')->find($id);
        expect($inv->subtotal_cents)->toBe(70000)->and($inv->vat_cents)->toBe(0)->and($inv->total_cents)->toBe(70000)
            ->and(fn () => $reporting->invoice($event))->toThrow(ValidationException::class)
            ->and(fn () => $reporting->markPaid($id, ''))->toThrow(ValidationException::class);
        $reporting->markPaid($id, 'EFT-ACME-1');
        expect(DB::table('corporate_invoices')->value('paid_at'))->not->toBeNull();

        Setting::put('vat', 'registered', true);
        DB::table('corporate_invoices')->delete();
        $vatInvoice = DB::table('corporate_invoices')->find($reporting->invoice($event));
        expect($vatInvoice->vat_cents)->toBe(10500)->and($vatInvoice->total_cents)->toBe(80500);
    });
});

it('reports only anonymised totals to the employer and withholds any figure based on fewer than 10 people', function (): void {
    [$small, $smallToken] = wellnessDay($this, 20);
    screenEmployees($this, $smallToken, 9, 9);
    $reporting = app(EmployerReporting::class);
    $this->clinic->run(fn () => expect($reporting->summary($small))->toBe(['screened' => 9, 'withheld' => true, 'checks' => []]));

    $big = $this->clinic->run(fn () => app(CorporateWellness::class)->createEvent((int) DB::table('corporate_accounts')->value('id'), 'Second day', 'Acme Plant',
        (string) CarbonImmutable::parse('tomorrow 09:00'), (string) CarbonImmutable::parse('tomorrow 10:00'), 30, 20, ['bp', 'cholesterol', 'bmi']));
    $bigToken = $this->clinic->run(fn () => (string) DB::table('wellness_events')->where('id', $big)->value('token'));
    screenEmployees($this, $bigToken, 12, 5);

    $this->clinic->run(function () use ($big, $reporting): void {
        $sum = $reporting->summary($big);
        expect($sum['screened'])->toBe(12)->and($sum['withheld'])->toBeFalse()
            ->and($sum['checks']['bp']['bands'])->toBe(['High' => 3, 'Elevated' => 0, 'Healthy range' => 9])
            ->and($sum['checks']['cholesterol']['withheld'])->toBeTrue()->and($sum['checks']['cholesterol']['bands'])->toBe([]);
        $pdf = $reporting->reportPdf($big);
        expect(str_starts_with($pdf, '%PDF'))->toBeTrue();
    });
});

it('emails the employer an expiring link to the summary and invoice', function (): void {
    [$event, $token] = wellnessDay($this, 20);
    screenEmployees($this, $token, 10, 10);
    $reporting = app(EmployerReporting::class);
    $link = $this->clinic->run(function () use ($event, $reporting): string {
        $reporting->invoice($event);

        return $reporting->sendToEmployer($event);
    });
    $this->clinic->run(fn () => expect(DB::table('message_log')->where('recipient', 'hr@acme.test')->where('body', 'like', '%totals only%')->exists())->toBeTrue());

    $this->get($link)->assertOk()->assertHeader('Content-Type', 'application/pdf');
    $this->get($link.'/invoice')->assertOk()->assertHeader('Content-Type', 'application/pdf');
    $this->travel(31)->days();
    $this->get($link)->assertNotFound();
});
