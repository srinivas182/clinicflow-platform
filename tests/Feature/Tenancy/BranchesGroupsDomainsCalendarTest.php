<?php

use App\Domains\Billing\Models\SubscriptionInvoice;
use App\Domains\Branches\Actions\ManageBranches;
use App\Domains\Branches\Models\Branch;
use App\Domains\Identity\Actions\AddStaffMember;
use App\Domains\Identity\Enums\StaffRole;
use App\Domains\Pharmacy\Actions\ReceiveStock;
use App\Domains\Pharmacy\Models\RegisterEntry;
use App\Domains\Pharmacy\Models\StockItem;
use App\Domains\Platform\Actions\CustomDomains;
use App\Domains\Platform\Actions\ProviderGroups;
use App\Domains\Platform\Enums\ProviderType;
use App\Domains\Platform\Enums\SubscriptionStatus;
use App\Domains\Platform\Models\Package;
use App\Domains\Platform\Models\Provider;
use App\Domains\Platform\Models\ProviderGroup;
use App\Domains\Platform\Models\Subscription;
use App\Domains\Platform\Support\DnsResolver;
use App\Domains\Prescribing\Models\Medicine;
use App\Domains\Scheduling\Actions\AvailableSlots;
use App\Domains\Scheduling\Calendar\CalendarApp;
use App\Domains\Scheduling\Calendar\CalendarConnection;
use App\Domains\Scheduling\Calendar\CalendarSync;
use App\Domains\Scheduling\Enums\AppointmentStatus;
use App\Domains\Scheduling\Models\Appointment;
use App\Domains\Scheduling\Models\RosterSession;
use App\Domains\Visits\Actions\CheckInPatient;
use App\Domains\Visits\Enums\PayerType;
use App\Models\User;
use Database\Seeders\ClinicalReferenceSeeder;
use Database\Seeders\PackageSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

uses(DatabaseMigrations::class);

beforeEach(function (): void {
    if (config('database.default') !== 'mysql') {
        $this->markTestSkipped('Tenancy tests require MySQL.');
    }

    $this->seed([PackageSeeder::class, ClinicalReferenceSeeder::class]);
    $this->clinic = makeProvider('Sunrise Medical Centre', ProviderType::Clinic, 'sunrise.clinicflow.test');
    $add = app(AddStaffMember::class);
    foreach (['doctorUser' => StaffRole::Doctor, 'pharmacistUser' => StaffRole::Pharmacist, 'ownerUser' => StaffRole::Owner] as $k => $role) {
        $this->{$k} = User::factory()->create();
        $add->handle($this->clinic, $this->{$k}, $role);
    }
    $this->subscription = Subscription::create(['tenant_id' => $this->clinic->id, 'package_id' => Package::query()->where('code', 'clinic-starter')->value('id'), 'status' => SubscriptionStatus::Active, 'current_period_ends_at' => now()->addMonth()]);
    tenancy()->initialize($this->clinic);
});

afterEach(function (): void {
    tenancy()->end();
    Provider::query()->get()->each->delete();
});

it('keeps branches within the package, tags new work with the branch in use and moves stock between branches', function (): void {
    $branches = app(ManageBranches::class);
    expect(Branch::query()->sole()->is_main)->toBeTrue()
        ->and(fn () => $branches->add($this->clinic, 'Soweto', null, null))->toThrow(ValidationException::class);

    $this->subscription->forceFill(['extra_branches' => 1])->save();
    $soweto = $branches->add($this->clinic, 'Soweto', '1 Vilakazi St', null);
    $main = Branch::query()->where('is_main', true)->sole();

    $atMain = app(CheckInPatient::class)->handle(registerTestPatient('Thandi', '880412'), PayerType::Cash);
    $request = Request::create('/');
    $request->setLaravelSession(app('session.store'));
    $request->session()->put('branch_id', $soweto->id);
    $this->app->instance('request', $request);
    $atSoweto = app(CheckInPatient::class)->handle(registerTestPatient('Sipho', '850101', null, '0821112222'), PayerType::Cash);
    expect($atMain->branch_id)->toBe($main->id)->and($atSoweto->branch_id)->toBe($soweto->id)
        ->and(DB::table('invoices')->where('visit_id', $atSoweto->id)->value('branch_id'))->toBe($soweto->id);

    $request->session()->put('branch_id', $main->id);
    app(ReceiveStock::class)->handle((int) Medicine::query()->where('name', 'Tramadol')->value('id'), 'TR1', now()->addYear(), 10, 300, $this->pharmacistUser->id);
    $item = StockItem::query()->sole();
    expect(fn () => $branches->transfer($item, $main->id, $soweto->id, 11, $this->pharmacistUser->id))->toThrow(ValidationException::class);
    $branches->transfer($item, $main->id, $soweto->id, 4, $this->pharmacistUser->id);
    expect($item->onHand($main->id))->toBe(6)->and($item->onHand($soweto->id))->toBe(4)->and($item->onHand())->toBe(10)
        ->and(RegisterEntry::query()->where('movement', 'transferred')->exists())->toBeTrue();
});

it('shows group admins totals per practice without patient records, and settles one combined invoice', function (): void {
    tenancy()->end();
    $other = makeProvider('Northside Clinic', ProviderType::Clinic, 'northside.clinicflow.test');
    $otherSub = Subscription::create(['tenant_id' => $other->id, 'package_id' => Package::query()->where('code', 'clinic-starter')->value('id'), 'status' => SubscriptionStatus::Active, 'current_period_ends_at' => now()->addMonth()]);
    $group = ProviderGroup::create(['name' => 'Sunrise Group', 'billing' => 'combined']);
    $groups = app(ProviderGroups::class);
    $groups->addMember($group, $this->clinic);
    $groups->addMember($group, $other);
    expect(fn () => $groups->addMember($group, $other))->toThrow(ValidationException::class);
    $admin = User::factory()->create(['email' => 'chain@sunrise.test']);
    $groups->addAdmin($group, 'chain@sunrise.test');

    $this->clinic->run(fn () => app(CheckInPatient::class)->handle(registerTestPatient('Thandi', '880412'), PayerType::Cash));
    $rows = collect($groups->dashboard($group, today()->toDateString(), today()->toDateString()))->keyBy('name');
    expect($rows['Sunrise Medical Centre']['visits'])->toBe(1)->and($rows['Northside Clinic']['visits'])->toBe(0)
        ->and(array_keys($rows['Sunrise Medical Centre']))->not->toContain('patients');

    $this->actingAs($admin)->get("http://localhost/groups/{$group->id}")->assertOk();
    $this->actingAs($this->doctorUser)->get("http://localhost/groups/{$group->id}")->assertForbidden();

    foreach ([[$this->subscription, 'CF-1'], [$otherSub, 'CF-2']] as [$sub, $number]) {
        SubscriptionInvoice::create(['number' => $number, 'tenant_id' => $sub->tenant_id, 'subscription_id' => $sub->id, 'period_start' => now(), 'period_end' => now()->addMonth(),
            'amount_cents' => 149000, 'messaging_units' => 0, 'messaging_overage_cents' => 0, 'vat_cents' => 22350, 'total_cents' => 171350, 'status' => 'open', 'checkout_token' => $number, 'due_at' => now()->addWeek()]);
    }
    $gi = $groups->issueCombinedInvoice($group);
    expect(DB::table('group_invoices')->where('id', $gi)->value('total_cents'))->toBe(342700);
    $groups->settleCombined((int) $gi, 'eft', 'EFT-2026-10');
    expect(SubscriptionInvoice::query()->where('status', 'paid')->count())->toBe(2);
});

it('verifies a practice\'s own domain by TXT record and only then allows a certificate', function (): void {
    $domains = app(CustomDomains::class);
    expect(fn () => $domains->add($this->clinic, 'book.sunriseclinic.co.za'))->toThrow(ValidationException::class);

    $this->subscription->forceFill(['package_id' => Package::query()->where('code', 'clinic-pro')->value('id')])->save();
    expect(fn () => $domains->add($this->clinic, 'evil.clinicflow.co.za'))->toThrow(ValidationException::class);
    $d = $domains->add($this->clinic, 'Book.SunriseClinic.co.za');

    $this->app->instance(DnsResolver::class, new class extends DnsResolver
    {
        /** @var list<string> */
        public array $records = [];

        public function txt(string $host): array
        {
            return $this->records;
        }
    });
    $domains = app(CustomDomains::class);
    expect($domains->verify((int) $d->id))->toBeFalse()->and($domains->tlsAllowed('book.sunriseclinic.co.za'))->toBeFalse();

    app(DnsResolver::class)->records = [(string) $d->token];
    expect($domains->verify((int) $d->id))->toBeTrue()
        ->and($this->clinic->domains()->where('domain', 'book.sunriseclinic.co.za')->exists())->toBeTrue();

    tenancy()->end();
    $this->get('http://localhost/internal/tls/allowed?domain=book.sunriseclinic.co.za')->assertOk();
    $this->get('http://localhost/internal/tls/allowed?domain=unknown.co.za')->assertNotFound();
});

it('syncs appointments to Google as "Appointment", removes cancelled ones, blocks busy times and serves an iCal feed', function (): void {
    tenancy()->end();
    CalendarApp::create(['driver' => 'google', 'offered' => true, 'client_id' => 'g-id', 'client_secret' => 'g-secret']);
    $location = (string) $this->actingAs($this->doctorUser)->get('http://sunrise.clinicflow.test/me/calendar/google/connect')->headers->get('Location');
    expect($location)->toStartWith('https://accounts.google.com/o/oauth2/v2/auth')->toContain('client_id=g-id');
    parse_str((string) parse_url($location, PHP_URL_QUERY), $q);

    Http::fake([
        'oauth2.googleapis.com/token' => Http::response(['access_token' => 'GA', 'refresh_token' => 'GR', 'expires_in' => 3600]),
        'www.googleapis.com/calendar/v3/calendars/primary/events' => Http::response(['id' => 'evt-1']),
        'www.googleapis.com/calendar/v3/calendars/primary/events/*' => Http::response([], 204),
        'www.googleapis.com/calendar/v3/freeBusy' => Http::response(['calendars' => ['primary' => ['busy' => [['start' => now()->addDay()->setTime(10, 0)->toIso8601String(), 'end' => now()->addDay()->setTime(11, 0)->toIso8601String()]]]]]),
    ]);
    $this->get('http://localhost/calendar/callback/google?code=c1&state='.urlencode((string) $q['state']))->assertRedirect();

    $this->clinic->run(function (): void {
        $conn = CalendarConnection::query()->sole();
        expect($conn->driver)->toBe('google')->and($conn->access_token)->toBe('GA');

        $patient = registerTestPatient('Thandi', '880412');
        $appt = Appointment::create(['patient_id' => $patient->id, 'staff_id' => $this->doctorUser->id, 'starts_at' => now()->addDay()->setTime(9, 0), 'ends_at' => now()->addDay()->setTime(9, 15), 'status' => AppointmentStatus::Booked]);
        expect(DB::table('calendar_events')->where('appointment_id', $appt->id)->value('external_id'))->toBe('evt-1');

        $appt->forceFill(['status' => AppointmentStatus::Cancelled])->save();
        expect(DB::table('calendar_events')->count())->toBe(0);

        $conn->forceFill(['import_busy' => true])->save();
        expect(app(CalendarSync::class)->importBusy($conn->fresh()))->toBe(1);
        RosterSession::create(['staff_id' => $this->doctorUser->id, 'starts_at' => now()->addDay()->setTime(9, 0), 'ends_at' => now()->addDay()->setTime(12, 0), 'slot_minutes' => 30]);
        $slots = collect(app(AvailableSlots::class)->handle($this->doctorUser->id, now()->addDay()))->map(fn ($s) => $s['starts_at']->format('H:i'))->all();
        expect($slots)->toBe(['09:00', '09:30', '11:00', '11:30']);

        Appointment::create(['patient_id' => $patient->id, 'staff_id' => $this->doctorUser->id, 'starts_at' => now()->addDays(2)->setTime(9, 0), 'ends_at' => now()->addDays(2)->setTime(9, 15), 'status' => AppointmentStatus::Booked]);
        test()->icalToken = $conn->ical_token;
    });

    Http::assertSent(fn ($r) => $r->method() === 'POST' && str_ends_with($r->url(), '/events') && $r['summary'] === 'Appointment');
    Http::assertSent(fn ($r) => $r->method() === 'DELETE' && str_ends_with($r->url(), '/events/evt-1'));

    $ics = $this->get('http://sunrise.clinicflow.test/calendar/'.test()->icalToken.'.ics')->assertOk()->getContent();
    expect($ics)->toContain('BEGIN:VEVENT')->toContain('SUMMARY:Appointment')->not->toContain('Thandi');
});
