<?php

use App\Domains\Clinical\Actions\BreakGlass;
use App\Domains\Clinical\Actions\ClinicianMessaging;
use App\Domains\Clinical\Actions\ManageAllergies;
use App\Domains\Clinical\Actions\Referrals;
use App\Domains\Clinical\Care\ChronicCare;
use App\Domains\Clinical\Care\ChronicRegistration;
use App\Domains\Clinical\Care\Pregnancy;
use App\Domains\Clinical\Care\Prevention;
use App\Domains\Clinical\Models\MessageThread;
use App\Domains\Clinical\Models\Referral;
use App\Domains\Clinical\Support\PracticeCrypto;
use App\Domains\Hub\Actions\NetworkIdentity;
use App\Domains\Hub\Actions\ShareConsent;
use App\Domains\Hub\Models\HubIdentity;
use App\Domains\Hub\Models\HubLink;
use App\Domains\Identity\Actions\AddStaffMember;
use App\Domains\Identity\Contracts\OtpSender;
use App\Domains\Identity\Enums\StaffRole;
use App\Domains\Identity\Models\Staff;
use App\Domains\Messaging\Contracts\MessageSender;
use App\Domains\Messaging\Support\LogMessageSender;
use App\Domains\Patients\Enums\Channel;
use App\Domains\Patients\Enums\IdType;
use App\Domains\Patients\Models\Patient;
use App\Domains\Platform\Enums\ProviderType;
use App\Domains\Platform\Enums\SubscriptionStatus;
use App\Domains\Platform\Models\Package;
use App\Domains\Platform\Models\Provider;
use App\Domains\Platform\Models\Subscription;
use App\Domains\Prescribing\Actions\RequestSigningPin;
use App\Domains\Prescribing\Actions\SaveDraftPrescription;
use App\Domains\Prescribing\Actions\SignPrescription;
use App\Domains\Prescribing\Models\Medicine;
use App\Domains\Visits\Enums\PayerType;
use App\Models\User;
use Database\Seeders\ClinicalReferenceSeeder;
use Database\Seeders\PackageSeeder;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

uses(DatabaseMigrations::class);

beforeEach(function (): void {
    if (config('database.default') !== 'mysql') {
        $this->markTestSkipped('Tenancy tests require MySQL.');
    }

    $this->artisan('migrate:fresh', ['--database' => 'hub', '--path' => 'database/migrations/hub'])->assertSuccessful();
    $this->seed([PackageSeeder::class, ClinicalReferenceSeeder::class]);
    $this->app->instance(MessageSender::class, $this->sms = new LogMessageSender);
    $this->a = makeProvider('Sunrise Medical Centre', ProviderType::Clinic, 'sunrise.clinicflow.test');
    $this->b = makeProvider('SkinCare Clinic', ProviderType::Clinic, 'skincare.clinicflow.test');
    $add = app(AddStaffMember::class);
    foreach (['doctorUser' => StaffRole::Doctor, 'colleagueUser' => StaffRole::Doctor, 'ownerUser' => StaffRole::Owner, 'adminUser' => StaffRole::PracticeAdmin] as $k => $role) {
        $this->{$k} = User::factory()->create();
        $add->handle($this->a, $this->{$k}, $role);
    }
    $this->specialistUser = User::factory()->create();
    $add->handle($this->b, $this->specialistUser, StaffRole::Doctor);
    Subscription::create(['tenant_id' => $this->a->id, 'package_id' => Package::query()->where('code', 'clinic-pro')->value('id'), 'status' => SubscriptionStatus::Active, 'current_period_ends_at' => now()->addMonth()]);

    tenancy()->initialize($this->a);
    $this->doctor = Staff::query()->findOrFail($this->doctorUser->id);
    $this->colleague = Staff::query()->findOrFail($this->colleagueUser->id);
    $this->patient = registerTestPatient('Thandi', '880412', 'Discovery Health', '0825550147');
});

afterEach(function (): void {
    tenancy()->end();
    Provider::query()->get()->each->delete();
});

function linkToBoth(object $t): HubIdentity
{
    $identity = app(NetworkIdentity::class)->register($t->patient->fresh(), $t->a);
    $t->b->run(function () use ($identity): void {
        $p = Patient::create(['first_names' => 'Thandi', 'surname' => 'Test', 'id_type' => IdType::None, 'date_of_birth' => '1988-04-12',
            'sex' => 'female', 'cell' => '0825550147', 'no_cell' => false, 'preferred_language' => 'en', 'preferred_channel' => Channel::Sms, 'hub_identity_id' => $identity?->id]);
        HubLink::create(['identity_id' => $identity?->id, 'tenant_id' => test()->b->id, 'patient_id' => $p->id, 'status' => 'active', 'linked_at' => now()]);
    });

    return HubIdentity::query()->findOrFail($identity?->id);
}

it('encrypts messages with the practice key and never lets them be edited or deleted', function (): void {
    $messaging = app(ClinicianMessaging::class);
    $thread = $messaging->start($this->doctor, 'Insulin dose', $this->patient, [$this->colleague->id]);
    $m = $messaging->post($thread, $this->doctor, 'Please review the insulin dose today.');

    expect(DB::table('thread_messages')->where('id', $m->id)->value('body'))->not->toContain('insulin')
        ->and($m->fresh()?->plainBody())->toBe('Please review the insulin dose today.')
        ->and(fn () => $m->fresh()?->forceFill(['body' => 'x'])->save())->toThrow(LogicException::class)
        ->and(fn () => $m->fresh()?->delete())->toThrow(LogicException::class)
        ->and(fn () => PracticeCrypto::decrypt(PracticeCrypto::encrypt('secret', $this->a->id), $this->b->id))->toThrow(DecryptException::class);

    $fix = $messaging->post($thread, $this->colleague, 'Correction: review tomorrow.', null, false, $m->id);
    expect($fix->corrects_id)->toBe($m->id)
        ->and(fn () => $messaging->post($thread, Staff::query()->findOrFail($this->adminUser->id), 'hi'))->toThrow(ValidationException::class)
        ->and(DB::table('patient_access_log')->where('patient_id', $this->patient->id)->where('kind', 'discussed')->exists())->toBeTrue();
});

it('allows cross-practice conversations only with consent or an active referral, keeping a copy in each practice', function (): void {
    $identity = linkToBoth($this);
    $messaging = app(ClinicianMessaging::class);

    expect(fn () => $messaging->start($this->doctor, 'Rash', $this->patient->fresh(), [], $this->b->id))->toThrow(ValidationException::class);

    app(ShareConsent::class)->grant($identity, $this->b->id, ['allergies'], null, 'portal');
    $thread = $messaging->start($this->doctor, 'Rash', $this->patient->fresh(), [], $this->b->id);
    $messaging->post($thread, $this->doctor, 'Would you see her this week?');

    $this->b->run(function (): void {
        $remote = MessageThread::query()->sole();
        expect($remote->messages()->sole()->plainBody())->toBe('Would you see her this week?')
            ->and(DB::table('patient_access_log')->where('kind', 'discussed')->count())->toBe(1);
    });
});

it('escalates an unanswered urgent message to the covering doctor', function (): void {
    $this->colleague->forceFill(['covering_staff_id' => $this->doctor->id])->save();
    $third = User::factory()->create();
    app(AddStaffMember::class)->handle($this->a, $third, StaffRole::Doctor);
    $this->colleague->forceFill(['covering_staff_id' => $third->id])->save();
    $messaging = app(ClinicianMessaging::class);
    $thread = $messaging->start($this->doctor, 'Potassium 6.8', $this->patient, [$this->colleague->id], null, null, null, true);
    $messaging->post($thread, $this->doctor, 'Critical potassium — please act.');

    expect($messaging->escalateUrgent())->toBe(0);

    $answered = $messaging->start($this->doctor, 'Answered quickly', $this->patient, [$this->colleague->id], null, null, null, true);
    $messaging->post($answered, $this->doctor, 'Please call me.');
    $messaging->post($answered, $this->colleague, 'Calling now.');

    $this->travel(5)->hours();
    expect($messaging->escalateUrgent())->toBe(1)
        ->and($thread->fresh()?->local_staff_ids)->toContain($third->id)
        ->and($answered->fresh()?->escalated_at)->toBeNull()
        ->and(collect($this->sms->sent)->where('channel', 'email')->count())->toBeGreaterThan(0);

    // A reply from the recipient means no escalation.
    $answered = $messaging->start($this->doctor, 'BP review', $this->patient, [$this->colleague->id], null, null, null, true);
    $messaging->post($answered, $this->doctor, 'Please review BP.');
    $messaging->post($answered, $this->colleague, 'Done — reviewed.');
    $this->travel(5)->hours();
    expect($messaging->escalateUrgent())->toBe(0)->and($answered->fresh()?->escalated_at)->toBeNull();
});

it('refers with only the history the patient agreed to share and brings status and feedback back', function (): void {
    $identity = linkToBoth($this);
    app(ManageAllergies::class)->add($this->patient, 'Penicillin', 'Rash');
    app(ChronicCare::class)->addProblem($this->patient, 'E11.9', null, $this->doctor);
    $referrals = app(Referrals::class);

    expect(fn () => $referrals->refer(registerTestPatient('Sipho', '850101', null, '0821112222'), $this->doctor, $this->b->id, '', 'Dermatology', 'routine', 'Rash', ['allergies']))->toThrow(ValidationException::class);

    app(ShareConsent::class)->grant($identity, $this->b->id, ['allergies', 'problems'], null, 'otp');
    $out = $referrals->refer($this->patient->fresh(), $this->doctor, $this->b->id, '', 'Dermatology', 'soon', 'Persistent rash on forearms', ['allergies', 'medicines', 'problems']);
    expect(array_keys($out->summary))->toBe(['allergies', 'problems'])->and($out->summary['allergies'])->toBe(['Penicillin']);

    $this->b->run(function () use ($referrals): void {
        $in = Referral::query()->where('direction', 'in')->sole();
        $referrals->update($in, 'accept');
        $referrals->update($in->fresh(), 'feedback', 'Contact dermatitis; started hydrocortisone cream. Review in 6 weeks with GP.');
    });
    expect($out->fresh()?->status)->toBe('feedback')->and($out->fresh()?->feedback)->toContain('hydrocortisone');

    $external = $referrals->refer($this->patient->fresh(), $this->doctor, null, 'Dr Mokoena, Eye Clinic', 'Ophthalmology', 'routine', 'Annual diabetic eye check', ['problems']);
    tenancy()->end();
    $this->actingAs($this->doctorUser)->get("http://sunrise.clinicflow.test/referrals/{$external->id}/letter")->assertOk()->assertHeader('Content-Type', 'application/pdf');
});

it('opens conversations to the Information Officer only with a second approval, for 7 days, and tells the patient', function (): void {
    app(ClinicianMessaging::class)->start($this->doctor, 'Complaint follow-up', $this->patient, []);
    $bg = app(BreakGlass::class);
    $id = $bg->request($this->patient, $this->ownerUser->id, 'complaint', 'Patient complaint about advice given');

    expect($bg->canRead($this->patient->id, $this->ownerUser->id))->toBeFalse()
        ->and(fn () => $bg->approve($id, $this->ownerUser->id))->toThrow(ValidationException::class);
    $bg->approve($id, $this->adminUser->id);
    expect($bg->canRead($this->patient->id, $this->ownerUser->id))->toBeTrue()
        ->and(DB::table('patient_access_log')->where('kind', 'reviewed')->value('summary'))->toContain('Patient complaint');

    $patientId = $this->patient->id;
    tenancy()->end();
    $this->actingAs($this->ownerUser)->get("http://sunrise.clinicflow.test/compliance/break-glass/patients/{$patientId}")->assertOk();
    $this->actingAs($this->adminUser)->get("http://sunrise.clinicflow.test/compliance/break-glass/patients/{$patientId}")->assertForbidden();

    $this->travel(8)->days();
    $this->a->run(fn () => expect($bg->canRead($patientId, $this->ownerUser->id))->toBeFalse());
});

it('records share consent with a code to the patient, honours expiry and revocation', function (): void {
    $identity = app(NetworkIdentity::class)->register($this->patient->fresh(), $this->a);
    $consent = app(ShareConsent::class);
    $consent->requestCode($identity, $this->a);
    preg_match('/Code (\d{6})/', (string) end($this->sms->sent)['body'], $m);

    expect(fn () => $consent->confirmCode($identity, $this->a, '000000', ['allergies']))->toThrow(ValidationException::class);
    $consent->confirmCode($identity, $this->a, $m[1], ['allergies', 'results']);
    expect($consent->categories($identity->id, $this->a->id))->toBe(['allergies', 'results']);

    $consent->revoke($identity, $this->a->id);
    expect($consent->categories($identity->id, $this->a->id))->toBe([]);
    $consent->grant($identity, $this->a->id, ['notes'], now()->subDay()->toDateString(), 'portal');
    expect($consent->categories($identity->id, $this->a->id))->toBe([]);
});

it('keeps a problem list, shows monitoring due and renews chronic scripts for re-signing', function (): void {
    $chronic = app(ChronicCare::class);
    $chronic->addProblem($this->patient, 'E11.9', '2020-01-01', $this->doctor);
    expect(collect($chronic->monitoringDue($this->patient))->firstWhere('check', 'HbA1c')['overdue'])->toBeTrue();

    $consult = seenByDoctor($this, $this->patient, PayerType::Cash);
    $draft = app(SaveDraftPrescription::class)->handle($consult, $this->doctor, [['medicine_id' => (int) Medicine::query()->where('name', 'Metformin')->value('id'), 'dose' => '1 twice daily', 'quantity' => 60, 'repeats' => 5]]);
    $draft->forceFill(['chronic' => true])->save();
    app(RequestSigningPin::class)->handle($draft, $this->doctor);
    $signed = app(SignPrescription::class)->handle($draft, $this->doctor, app(OtpSender::class)->sent[$this->doctorUser->id]);

    $repeat = $chronic->renew($signed, $this->doctor);
    expect($repeat->status)->toBe('draft')->and((bool) $repeat->getAttribute('chronic'))->toBeTrue()
        ->and($repeat->items()->sole()->description)->toContain('Metformin')
        ->and($repeat->consultation->visit->check_in_channel)->toBe('repeat');
});

it('sends due recalls once and skips patients who opted out', function (): void {
    $prevention = app(Prevention::class);
    expect($prevention->sendDueRecalls())->toBe(1)->and($prevention->sendDueRecalls())->toBe(0);

    $other = registerTestPatient('Lerato', '900101', null, '0829990000');
    DB::table('message_opt_outs')->insert(['channel' => 'sms', 'recipient' => '0829990000', 'opted_out_at' => now()]);
    expect($prevention->sendDueRecalls())->toBe(0)->and($other->id)->not->toBeEmpty();
});

it('tracks immunisations due, pregnancy contacts and risk flags, and chronic registrations', function (): void {
    $baby = Patient::create(['first_names' => 'Ama', 'surname' => 'Test', 'id_type' => IdType::None, 'date_of_birth' => now()->subWeeks(15),
        'sex' => 'female', 'cell' => '0827770000', 'no_cell' => false, 'preferred_language' => 'en', 'preferred_channel' => Channel::Sms]);
    $prevention = app(Prevention::class);
    $due = collect($prevention->immunisationsDue($baby));
    expect($due->where('vaccine', 'Hexavalent')->pluck('dose')->all())->toBe(['1st', '2nd', '3rd']);
    $prevention->recordImmunisation($baby, 'Hexavalent', '1st', now()->subWeeks(9)->toDateString(), 'B123', $this->doctor->id);
    expect(collect($prevention->immunisationsDue($baby))->where('vaccine', 'Hexavalent')->count())->toBe(2);

    $pregnancy = app(Pregnancy::class);
    $id = $pregnancy->start($this->patient, now()->subWeeks(20)->toDateString());
    $pregnancy->recordVisit($id, now()->toDateString(), '150/95', 70.5, 20, null, $this->doctor->id);
    $summary = $pregnancy->summary($this->patient);
    expect($summary['edd'])->toBe(now()->subWeeks(20)->addDays(280)->toDateString())
        ->and($summary['risks'][0])->toContain('150/95')
        ->and(collect($summary['contacts'])->firstWhere('week', 20)['done'])->toBeTrue();

    $reg = app(ChronicRegistration::class);
    $rid = $reg->create($this->patient, 'E11.9', ['Metformin 500 mg']);
    expect(fn () => $reg->decide($rid, true, 'X', null))->toThrow(ValidationException::class);
    $reg->submit($rid);
    $reg->decide($rid, true, 'DH-CDL-778', null);
    expect(DB::table('chronic_registrations')->where('id', $rid)->value('status'))->toBe('approved');
});
