<?php

use App\Domains\Billing\Models\Invoice;
use App\Domains\Clinical\Actions\AcceptRedAlert;
use App\Domains\Clinical\Actions\CallNextPatient;
use App\Domains\Clinical\Actions\ManageAllergies;
use App\Domains\Clinical\Actions\RecordTriage;
use App\Domains\Clinical\Enums\TriageColour;
use App\Domains\Clinical\Events\RedTriageAlert;
use App\Domains\Clinical\Models\TriageRecord;
use App\Domains\Clinical\Support\Vitals;
use App\Domains\Documents\Actions\PublishTemplate;
use App\Domains\Documents\Actions\RenderDocument;
use App\Domains\Documents\Models\DocumentTemplate;
use App\Domains\Documents\Models\IssuedDocument;
use App\Domains\Documents\Support\DocumentType;
use App\Domains\Identity\Actions\AddStaffMember;
use App\Domains\Identity\Enums\StaffRole;
use App\Domains\Identity\Models\Staff;
use App\Domains\Platform\Enums\ProviderType;
use App\Domains\Platform\Models\Provider;
use App\Domains\Platform\Models\Setting;
use App\Domains\Visits\Actions\CheckInPatient;
use App\Domains\Visits\Actions\TransitionVisit;
use App\Domains\Visits\Enums\VisitStage;
use App\Domains\Visits\Models\Visit;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;

uses(DatabaseMigrations::class);

beforeEach(function (): void {
    if (config('database.default') !== 'mysql') {
        $this->markTestSkipped('Tenancy tests require MySQL.');
    }

    $this->clinic = makeProvider('Sunrise Medical Centre', ProviderType::Clinic, 'sunrise.clinicflow.test');
    $add = app(AddStaffMember::class);
    foreach (['nurseUser' => StaffRole::Nurse, 'drAUser' => StaffRole::Doctor, 'drBUser' => StaffRole::Doctor, 'recepUser' => StaffRole::Receptionist, 'ownerUser' => StaffRole::Owner] as $key => $role) {
        $this->{$key} = User::factory()->create(['name' => $key]);
        $add->handle($this->clinic, $this->{$key}, $role);
    }

    tenancy()->initialize($this->clinic);
    Setting::put('billing', 'payment_timing', 'end');
    $this->drA = Staff::query()->findOrFail($this->drAUser->id);
    $this->drB = Staff::query()->findOrFail($this->drBUser->id);
});

afterEach(function (): void {
    tenancy()->end();
    Provider::query()->get()->each->delete();
});

function waitingForDoctor(string $first, string $dob, ?string $colour, ?int $preferred = null, bool $booked = false): Visit
{
    $patient = registerTestPatient($first, $dob, null, '08'.substr($dob, 0, 6).'00');
    $visit = app(CheckInPatient::class)->handle($patient, null, null, $preferred);
    if ($booked) {
        $visit->forceFill(['appointment_id' => null])->save();
    }
    app(TransitionVisit::class)->handle($visit, VisitStage::Triage);
    app(TransitionVisit::class)->handle($visit, VisitStage::Doctor);
    $visit->forceFill(['triage_colour' => $colour])->save();

    return $visit;
}

it('records triage, moves the patient to the doctor queue and keeps the suggestion', function (): void {
    $patient = registerTestPatient('Johan', '590101');
    $visit = app(CheckInPatient::class)->handle($patient);

    expect(fn () => app(RecordTriage::class)->handle($visit, new Vitals(172, 112, 96, 37.2), TriageColour::Orange))->toThrow(ValidationException::class);

    app(TransitionVisit::class)->handle($visit, VisitStage::Triage);
    expect(fn () => app(RecordTriage::class)->handle($visit, new Vitals(120, 130, 96, 37.2), TriageColour::Green))->toThrow(ValidationException::class);

    app(RecordTriage::class)->handle($visit, new Vitals(172, 112, 96, 37.2), TriageColour::Yellow);

    $record = TriageRecord::query()->sole();
    expect($visit->fresh()?->stage)->toBe(VisitStage::Doctor)
        ->and($visit->fresh()?->triage_colour)->toBe('yellow')
        ->and($record->suggested_colour)->toBe(TriageColour::Orange)
        ->and($record->colour)->toBe(TriageColour::Yellow);
});

it('alerts every doctor on shift for red triage, and the first to accept takes the patient', function (): void {
    Event::fake([RedTriageAlert::class]);
    $patient = registerTestPatient('Bongani', '680101');
    $visit = app(CheckInPatient::class)->handle($patient);
    app(TransitionVisit::class)->handle($visit, VisitStage::Triage);

    app(RecordTriage::class)->handle($visit, new Vitals(85, 50, 128, 36.9, spo2: 89), TriageColour::Red);
    Event::assertDispatched(RedTriageAlert::class, fn (RedTriageAlert $e) => $e->ticket === $visit->ticket);

    app(AcceptRedAlert::class)->handle($visit, $this->drB);
    expect(fn () => app(AcceptRedAlert::class)->handle($visit, $this->drA))->toThrow(ValidationException::class)
        ->and($visit->fresh()?->doctor_id)->toBe($this->drB->id);
});

it('calls bookings first, then patients asking for the doctor, then the pool by colour', function (): void {
    $poolGreen = waitingForDoctor('PoolGreen', '700101', 'green');
    $this->travel(1)->minutes();
    $poolOrange = waitingForDoctor('PoolOrange', '710101', 'orange');
    $asked = waitingForDoctor('Asked', '720101', 'green', $this->drA->id);
    $booked = waitingForDoctor('Booked', '730101', 'green', $this->drA->id);
    $booked->forceFill(['appointment_id' => null])->save();
    Visit::query()->whereKey($booked->id)->update(['appointment_id' => null]);

    $call = app(CallNextPatient::class);
    $next = fn (Staff $d) => tap($call->handle($d), fn (?Visit $v) => $v && app(TransitionVisit::class)->handle($v, VisitStage::Done));

    expect($next($this->drA)?->id)->toBe($asked->id)
        ->and($next($this->drA)?->id)->toBe($booked->id)
        ->and($next($this->drB)?->id)->toBe($poolOrange->id)
        ->and($next($this->drA)?->id)->toBe($poolGreen->id)
        ->and($call->handle($this->drA))->toBeNull();
});

it('does not let a doctor call a second patient while one is with them', function (): void {
    waitingForDoctor('One', '700101', 'green');
    waitingForDoctor('Two', '710101', 'green');
    app(CallNextPatient::class)->handle($this->drA);

    expect(fn () => app(CallNextPatient::class)->handle($this->drA))->toThrow(ValidationException::class);
});

it('keeps allergies unless removed with a reason', function (): void {
    $patient = registerTestPatient('Thandi', '880412');
    $allergies = app(ManageAllergies::class);
    $penicillin = $allergies->add($patient, 'Penicillin', 'Rash');

    expect($allergies->add($patient, 'penicillin')->id)->toBe($penicillin->id)
        ->and(fn () => $allergies->remove($penicillin, ''))->toThrow(ValidationException::class);

    $allergies->remove($penicillin, 'Confirmed intolerance, not allergy');
    expect($penicillin->fresh()?->status)->toBe('removed');
});

it('seeds templates, versions them and always adds the legal block', function (): void {
    expect(DocumentTemplate::query()->where('is_active', true)->count())->toBe(4);

    $v2 = app(PublishTemplate::class)->handle(DocumentType::Prescription, '<p>{{ patient.name }}</p><script>x</script>', 'A5');
    expect($v2->version)->toBe(2)
        ->and($v2->body)->not->toContain('script')
        ->and(DocumentTemplate::query()->where('type', 'prescription')->where('version', 1)->value('is_active'))->toBeFalse();

    $html = app(RenderDocument::class)->html($v2, DocumentType::Prescription->sampleData());
    expect($html)->toContain('HPCSA MP 0654321')->toContain('advanced electronic signature')
        ->and(app(RenderDocument::class)->pdf($v2, DocumentType::Prescription->sampleData()))->toStartWith('%PDF');
});

it('issues a branded invoice PDF and records the template version', function (): void {
    $patient = registerTestPatient('Ayesha', '970314');
    $visit = app(CheckInPatient::class)->handle($patient);
    $invoice = Invoice::query()->where('visit_id', $visit->id)->sole();
    tenancy()->end();

    $response = $this->actingAs($this->recepUser)->get("http://sunrise.clinicflow.test/invoices/{$invoice->id}/pdf");
    $response->assertOk();
    expect($response->headers->get('Content-Type'))->toContain('application/pdf');

    $this->clinic->run(fn () => expect(IssuedDocument::query()->sole()->template_version)->toBe(1));
});

it('lets the owner preview templates but not reception', function (): void {
    tenancy()->end();

    $this->actingAs($this->ownerUser)->get('http://sunrise.clinicflow.test/settings/templates/sick_note/preview')->assertOk();
    $this->actingAs($this->recepUser)->get('http://sunrise.clinicflow.test/settings/templates/sick_note/preview')->assertForbidden();
});
