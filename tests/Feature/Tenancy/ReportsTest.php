<?php

use App\Domains\Identity\Actions\AddStaffMember;
use App\Domains\Identity\Enums\StaffRole;
use App\Domains\Identity\Models\Staff;
use App\Domains\Messaging\Contracts\MessageSender;
use App\Domains\Messaging\Support\LogMessageSender;
use App\Domains\Platform\Enums\ProviderType;
use App\Domains\Platform\Enums\SubscriptionStatus;
use App\Domains\Platform\Models\Package;
use App\Domains\Platform\Models\Provider;
use App\Domains\Platform\Models\Subscription;
use App\Domains\Visits\Enums\PayerType;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\ClinicalReferenceSeeder;
use Database\Seeders\PackageSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;

uses(DatabaseMigrations::class);

beforeEach(function (): void {
    if (config('database.default') !== 'mysql') {
        $this->markTestSkipped('Tenancy tests require MySQL.');
    }
    $this->artisan('migrate:fresh', ['--database' => 'hub', '--path' => 'database/migrations/hub'])->assertSuccessful();
    $this->seed(ClinicalReferenceSeeder::class);
    $this->seed(PackageSeeder::class);
    $this->app->instance(MessageSender::class, new LogMessageSender);
    $this->clinic = makeProvider('Sunrise Medical Centre', ProviderType::Clinic, 'sunrise.clinicflow.test');
    $this->sub = Subscription::create(['tenant_id' => $this->clinic->id, 'package_id' => Package::query()->where('code', 'clinic-standard')->value('id'),
        'status' => SubscriptionStatus::Active, 'current_period_ends_at' => now()->addMonth()]);
    $this->ownerUser = User::factory()->create(['email' => 'owner@sunrise.test']);
    $this->doctorUser = User::factory()->create(['name' => 'Dr Mokoena']);
    $this->nurseUser = User::factory()->create();
    app(AddStaffMember::class)->handle($this->clinic, $this->ownerUser, StaffRole::Owner);
    app(AddStaffMember::class)->handle($this->clinic, $this->doctorUser, StaffRole::Doctor);
    app(AddStaffMember::class)->handle($this->clinic, $this->nurseUser, StaffRole::Nurse);
    tenancy()->initialize($this->clinic);
    $this->doctor = Staff::query()->findOrFail($this->doctorUser->id);
    seenByDoctor($this, registerTestPatient('Thandi', '880412'), PayerType::Cash);
    tenancy()->end();
    $this->base = 'http://sunrise.clinicflow.test';
    $this->def = fn (array $o = []) => array_merge(['dataset' => 'invoices', 'from' => now()->subDay()->toDateString(), 'to' => now()->toDateString(), 'groups' => ['payer'], 'measures' => ['count', 'billed', 'collected'], 'filters' => []], $o);
});

afterEach(function (): void {
    tenancy()->end();
    Provider::query()->get()->each->delete();
});

it('runs reports from predefined data sets with totals in rands and names instead of ids', function (): void {
    $r = $this->actingAs($this->ownerUser)->postJson("{$this->base}/reports/run", ['definition' => ($this->def)()])->assertOk()->json();
    expect($r['rows'][0]['payer'])->toBe('cash')->and($r['rows'][0]['count'])->toEqual(1)->and($r['rows'][0]['billed'])->toEqual(520.0)
        ->and(collect($r['columns'])->firstWhere('key', 'billed')['money'])->toBeTrue();

    $v = $this->actingAs($this->ownerUser)->postJson("{$this->base}/reports/run", ['definition' => ['dataset' => 'visits', 'from' => now()->subDay()->toDateString(), 'to' => now()->toDateString(), 'groups' => ['doctor'], 'measures' => ['count']]])->assertOk()->json();
    expect($v['rows'][0]['doctor'])->toBe('Dr Mokoena');
});

it('refuses anything outside the whitelist and binds filter values safely', function (): void {
    foreach ([['dataset' => 'users'], ['groups' => ['day; DROP TABLE invoices']], ['measures' => ['SUM(1)']], ['groups' => ['day', 'payer', 'status']], ['filters' => ['nope' => 'x']], ['filters' => ['payer' => 'bitcoin']], ['from' => '2020-01-01']] as $bad) {
        $this->actingAs($this->ownerUser)->postJson("{$this->base}/reports/run", ['definition' => ($this->def)($bad)])->assertStatus(422);
    }
    $this->actingAs($this->ownerUser)->postJson("{$this->base}/reports/run", ['definition' => ($this->def)(['dataset' => 'claims', 'groups' => ['status'], 'measures' => ['count'], 'filters' => ['scheme' => "x' OR '1'='1"]])])
        ->assertOk()->assertJsonCount(0, 'rows');
    $this->clinic->run(fn () => expect(DB::table('invoices')->count())->toBe(1));
});

it('keeps money reports from roles without finance access', function (): void {
    $this->actingAs($this->nurseUser)->postJson("{$this->base}/reports/run", ['definition' => ($this->def)()])->assertForbidden();
    $this->actingAs($this->nurseUser)->postJson("{$this->base}/reports/run", ['definition' => ['dataset' => 'appointments', 'from' => now()->subDay()->toDateString(), 'to' => now()->toDateString(), 'groups' => ['status'], 'measures' => ['count']]])->assertOk();
    $this->actingAs($this->nurseUser)->get("{$this->base}/reports")->assertOk()->assertInertia(fn ($p) => $p->where('datasets', fn ($d) => collect($d)->pluck('key')->doesntContain('invoices')));
});

it('exports CSV and PDF and logs every export', function (): void {
    $csv = $this->actingAs($this->ownerUser)->post("{$this->base}/reports/export/csv", ['definition' => json_encode(($this->def)())])->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
    expect($csv->getContent())->toContain('Payer,Invoices,Billed,Paid')->toContain('cash,1,520,');
    $this->actingAs($this->ownerUser)->post("{$this->base}/reports/export/pdf", ['definition' => json_encode(($this->def)())])->assertOk()->assertHeader('Content-Type', 'application/pdf');
    $this->clinic->run(fn () => expect(DB::table('activity_log')->where('description', 'Report exported')->count())->toBe(2));
});

it('saves and schedules reports on higher packages, emailing only staff who may see the data', function (): void {
    $this->sub->update(['package_id' => Package::query()->where('code', 'clinic-starter')->value('id')]);
    $this->actingAs($this->ownerUser)->post("{$this->base}/reports", ['name' => 'Billing', 'definition' => ($this->def)(), 'schedule' => 'weekly', 'recipients' => []])->assertForbidden();
    $this->sub->update(['package_id' => Package::query()->where('code', 'clinic-standard')->value('id')]);
    $this->actingAs($this->ownerUser)->post("{$this->base}/reports", ['name' => 'Billing', 'definition' => ($this->def)(), 'schedule' => 'weekly',
        'recipients' => [$this->ownerUser->id, $this->nurseUser->id]])->assertSessionHasNoErrors();
    $this->clinic->run(fn () => expect(json_decode((string) DB::table('report_definitions')->value('recipients'), true))->toBe([$this->ownerUser->id]));

    $this->travelTo(CarbonImmutable::parse('next monday 07:00'));
    $this->artisan('reports:send')->assertSuccessful();
    $this->clinic->run(fn () => expect(DB::table('message_log')->where('recipient', 'owner@sunrise.test')->where('subject', 'like', 'Billing%')->exists())->toBeTrue()
        ->and(DB::table('report_definitions')->value('last_sent_at'))->not->toBeNull());
});
