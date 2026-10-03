<?php

use App\Domains\Billing\Actions\Debtors;
use App\Domains\Billing\Actions\IssueCreditNote;
use App\Domains\Billing\Models\Invoice;
use App\Domains\Billing\Models\SubscriptionInvoice;
use App\Domains\Finance\Accounting\AccountingApp;
use App\Domains\Finance\Accounting\AccountingClient;
use App\Domains\Finance\Accounting\AccountingConnection;
use App\Domains\Finance\Accounting\JournalExporter;
use App\Domains\Finance\Accounting\PlatformAccountingConnection;
use App\Domains\Identity\Actions\AddStaffMember;
use App\Domains\Identity\Enums\StaffRole;
use App\Domains\Identity\Models\Staff;
use App\Domains\Messaging\Contracts\MessageSender;
use App\Domains\Messaging\Support\LogMessageSender;
use App\Domains\Pharmacy\Actions\Procurement;
use App\Domains\Pharmacy\Actions\ReceiveStock;
use App\Domains\Pharmacy\Models\RegisterEntry;
use App\Domains\Pharmacy\Models\StockItem;
use App\Domains\Pharmacy\Models\Supplier;
use App\Domains\Platform\Enums\ProviderType;
use App\Domains\Platform\Enums\SubscriptionStatus;
use App\Domains\Platform\Models\Package;
use App\Domains\Platform\Models\Provider;
use App\Domains\Platform\Models\Setting;
use App\Domains\Platform\Models\Subscription;
use App\Domains\Visits\Actions\CheckInPatient;
use App\Domains\Visits\Enums\PayerType;
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

    $this->seed([PackageSeeder::class, ClinicalReferenceSeeder::class]);
    $this->app->instance(MessageSender::class, $this->sms = new LogMessageSender);
    $this->clinic = makeProvider('Sunrise Medical Centre', ProviderType::Clinic, 'sunrise.clinicflow.test');
    $add = app(AddStaffMember::class);
    foreach (['doctorUser' => StaffRole::Doctor, 'pharmacistUser' => StaffRole::Pharmacist, 'ownerUser' => StaffRole::Owner, 'managerUser' => StaffRole::Manager] as $k => $role) {
        $this->{$k} = User::factory()->create();
        $add->handle($this->clinic, $this->{$k}, $role);
    }
    Subscription::create(['tenant_id' => $this->clinic->id, 'package_id' => Package::query()->where('code', 'clinic-pro')->value('id'), 'status' => SubscriptionStatus::Active, 'current_period_ends_at' => now()->addMonth()]);
    tenancy()->initialize($this->clinic);
    $this->doctor = Staff::query()->findOrFail($this->doctorUser->id);
});

afterEach(function (): void {
    tenancy()->end();
    Provider::query()->get()->each->delete();
});

it('charges no VAT when not registered and shows the VAT portion on tax invoices when registered', function (): void {
    $plain = seenByDoctor($this, registerTestPatient('Thandi', '880412'), PayerType::Cash)->visit;
    $first = Invoice::query()->where('visit_id', $plain->id)->sole();
    expect($first->vat_cents)->toBe(0)->and($first->tax_invoice)->toBeFalse();

    Setting::put('vat', 'registered', true);
    Setting::put('vat', 'number', '4123456789');
    Setting::put('vat', 'rate', 15);
    $taxed = seenByDoctor($this, registerTestPatient('Sipho', '850101', null, '0821112222'), PayerType::Cash)->visit;
    $invoice = Invoice::query()->where('visit_id', $taxed->id)->sole();
    $consultCents = (int) $invoice->lines()->sum('total_cents');
    expect($invoice->vat_cents)->toBe((int) round($consultCents * 15 / 115))->and($invoice->tax_invoice)->toBeTrue();

    app(IssueCreditNote::class)->handle($invoice->fresh(), 11500, 'Goodwill');
    expect(DB::table('credit_notes')->value('vat_cents'))->toBe(1500)
        ->and($invoice->fresh()?->vat_cents)->toBe((int) round($consultCents * 15 / 115) - 1500);

    Setting::put('vat', 'zero_rated_kinds', ['consultation']);
    $zero = seenByDoctor($this, registerTestPatient('Lerato', '900101', null, '0829990000'), PayerType::Cash)->visit;
    expect(Invoice::query()->where('visit_id', $zero->id)->sole()->vat_cents)->toBe(0);

    $report = app(Debtors::class)->vatReport(today()->toDateString(), today()->toDateString());
    expect($report['output_vat_cents'])->toBe((int) round($consultCents * 15 / 115) - 1500);
});

it('orders from suppliers, receives against the order and records scheduled stock in the register', function (): void {
    $supplier = Supplier::create(['name' => 'MedSupply', 'email' => 'orders@medsupply.test', 'vat_number' => '4987654321']);
    Setting::put('vat', 'rate', 15);
    $procurement = app(Procurement::class);
    $po = $procurement->order($supplier, [
        ['medicine_id' => medicineId('Metformin'), 'quantity' => 100, 'unit_cost_cents' => 80],
        ['medicine_id' => medicineId('Tramadol'), 'quantity' => 30, 'unit_cost_cents' => 200],
    ], $this->pharmacistUser->id);
    expect($po->total_cents)->toBe(8000 + 6000)->and($po->vat_cents)->toBe(2100);

    $procurement->send($po);
    expect(collect($this->sms->sent)->where('channel', 'email')->pluck('recipient')->all())->toContain('orders@medsupply.test');

    $tramadol = $po->lines()->where('medicine_id', medicineId('Tramadol'))->sole();
    $procurement->receive($po->fresh(), [['line_id' => $tramadol->id, 'quantity' => 20, 'batch' => 'TR1', 'expiry' => now()->addYear()->toDateString(), 'sell_price_cents' => 300]], $this->pharmacistUser->id);
    expect($po->fresh()?->status)->toBe('partial')
        ->and(StockItem::query()->where('medicine_id', medicineId('Tramadol'))->sole()->onHand())->toBe(20)
        ->and(RegisterEntry::query()->where('movement', 'received')->sole()->quantity)->toBe(20)
        ->and(fn () => $procurement->receive($po->fresh(), [['line_id' => $tramadol->id, 'quantity' => 11, 'batch' => 'TR2', 'expiry' => now()->addYear()->toDateString()]], $this->pharmacistUser->id))->toThrow(ValidationException::class);

    $metformin = $po->lines()->where('medicine_id', medicineId('Metformin'))->sole();
    $procurement->receive($po->fresh(), [
        ['line_id' => $tramadol->id, 'quantity' => 10, 'batch' => 'TR2', 'expiry' => now()->addYear()->toDateString()],
        ['line_id' => $metformin->id, 'quantity' => 100, 'batch' => 'MF1', 'expiry' => now()->addYear()->toDateString()],
    ], $this->pharmacistUser->id);
    expect($po->fresh()?->status)->toBe('received');
});

it('adjusts stock after a count with a reason and writes off expired batches', function (): void {
    $receive = app(ReceiveStock::class);
    $receive->handle(medicineId('Tramadol'), 'OLD', now()->addMonth(), 10, 300, $this->pharmacistUser->id);
    $receive->handle(medicineId('Tramadol'), 'NEW', now()->addYear(), 10, 300, $this->pharmacistUser->id);
    $item = StockItem::query()->where('medicine_id', medicineId('Tramadol'))->sole();
    $procurement = app(Procurement::class);

    expect(fn () => $procurement->stockTake($item, 17, '', $this->pharmacistUser->id))->toThrow(ValidationException::class);
    expect($procurement->stockTake($item, 17, 'Broken capsules', $this->pharmacistUser->id))->toBe(-3)
        ->and($item->batches()->where('batch_number', 'OLD')->value('quantity'))->toBe(7)
        ->and(RegisterEntry::query()->where('movement', 'adjusted')->sole()->balance_after)->toBe(17);

    $item->batches()->where('batch_number', 'OLD')->update(['expiry_date' => now()->subDay()]);
    expect($procurement->writeOffExpired($this->pharmacistUser->id))->toBe(1)
        ->and($item->fresh()?->onHand())->toBe(10)
        ->and(RegisterEntry::query()->where('movement', 'written_off')->sole()->quantity)->toBe(-7);
});

it('ages debts, sends one statement a month and writes off bad debt only with a second approval', function (): void {
    $patient = registerTestPatient('Thandi', '880412');
    $visit = app(CheckInPatient::class)->handle($patient, PayerType::Cash);
    $invoice = Invoice::query()->where('visit_id', $visit->id)->sole();
    $this->travel(65)->days();

    $debtors = app(Debtors::class);
    $ageing = $debtors->ageing();
    expect($ageing['buckets']['60'])->toBe($invoice->total_cents)->and($ageing['patients'][0]['oldest_days'])->toBe(65);

    expect($debtors->sendStatements())->toBe(1)->and($debtors->sendStatements())->toBe(0);

    $id = $debtors->requestWriteOff($invoice, 'Patient emigrated; untraceable', $this->managerUser->id);
    expect(fn () => $debtors->approveWriteOff($id, $this->managerUser->id))->toThrow(ValidationException::class);
    $debtors->approveWriteOff($id, $this->ownerUser->id);
    expect($invoice->fresh()?->balanceCents())->toBe(0)
        ->and(DB::table('credit_notes')->value('reason'))->toContain('Bad debt');
});

it('connects a provider to Xero through the super admin\'s app and posts balanced daily journals', function (): void {
    tenancy()->end();
    AccountingApp::create(['driver' => 'xero', 'offered' => true, 'client_id' => 'cf-xero-id', 'client_secret' => 'cf-xero-secret']);
    $redirect = $this->actingAs($this->ownerUser)->get('http://sunrise.clinicflow.test/settings/accounting/xero/connect');
    $location = (string) $redirect->headers->get('Location');
    expect($location)->toStartWith('https://login.xero.com/identity/connect/authorize')->toContain('client_id=cf-xero-id');
    parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

    Http::fake([
        'identity.xero.com/connect/token' => Http::response(['access_token' => 'AT-1', 'refresh_token' => 'RT-1', 'expires_in' => 1800]),
        'api.xero.com/connections' => Http::response([['tenantId' => 'xero-org-9']]),
        'api.xero.com/api.xro/2.0/ManualJournals' => Http::response(['ManualJournals' => [['ManualJournalID' => 'MJ-1']]]),
    ]);
    $this->get('/accounting/callback/xero?code=abc&state='.urlencode((string) $query['state']))->assertRedirect();

    $this->clinic->run(function (): void {
        $c = AccountingConnection::query()->sole();
        expect($c->org_id)->toBe('xero-org-9')->and($c->access_token)->toBe('AT-1')
            ->and(DB::table('accounting_connections')->value('access_token'))->not->toBe('AT-1');

        $this->travel(-1)->days();
        $patient = registerTestPatient('Thandi', '880412');
        seenByDoctor($this, $patient, PayerType::Cash);
        $this->travelBack();

        $exporter = app(JournalExporter::class);
        $app = AccountingApp::query()->where('driver', 'xero')->sole();
        $c->forceFill(['enabled' => true, 'account_map' => []])->save();
        expect($exporter->run($app, $c, fn (string $d) => JournalExporter::providerTotals($d)))->toBe(0)
            ->and($c->fresh()?->last_error)->toContain('Map the account');

        $map = DB::table('ledger_entries')->distinct()->pluck('account')->mapWithKeys(fn ($a) => [$a => strtoupper(substr(md5((string) $a), 0, 4))])->all();
        $c->forceFill(['account_map' => $map, 'exported_until' => null, 'last_error' => null])->save();
        expect($exporter->run($app, $c->fresh(), fn (string $d) => JournalExporter::providerTotals($d)))->toBe(1)
            ->and($c->fresh()?->exported_until?->toDateString())->toBe(now()->subDay()->toDateString());
    });

    Http::assertSent(function ($request): bool {
        if (! str_contains($request->url(), 'ManualJournals')) {
            return false;
        }
        $lines = $request['ManualJournals'][0]['JournalLines'];

        return $request->header('Xero-tenant-id')[0] === 'xero-org-9' && abs(array_sum(array_column($lines, 'LineAmount'))) < 0.001;
    });
});

it('keeps one accounting app active per provider and refreshes expired tokens', function (): void {
    AccountingApp::create(['driver' => 'zoho', 'offered' => true, 'client_id' => 'z', 'client_secret' => 's', 'region' => 'com']);
    $xero = AccountingConnection::create(['driver' => 'xero', 'enabled' => true, 'access_token' => 'a', 'refresh_token' => 'r']);
    $zoho = AccountingConnection::create(['driver' => 'zoho', 'enabled' => false, 'access_token' => 'old', 'refresh_token' => 'rz', 'token_expires_at' => now()->subMinute(), 'org_id' => '777']);
    tenancy()->end();
    $this->actingAs($this->ownerUser)->put('http://sunrise.clinicflow.test/settings/accounting/zoho', ['enabled' => true, 'auto_export' => 'daily', 'account_map' => ['bank:cash' => 'BANK']])->assertSessionHasNoErrors();
    $this->clinic->run(fn () => expect($xero->fresh()?->enabled)->toBeFalse()->and($zoho->fresh()?->enabled)->toBeTrue());

    Http::fake(['accounts.zoho.com/oauth/v2/token' => Http::response(['access_token' => 'new', 'expires_in' => 3600]), 'www.zohoapis.com/*' => Http::response(['journal' => ['journal_id' => 'J1']])]);
    $this->clinic->run(function () use ($zoho): void {
        app(AccountingClient::class)->postJournal(AccountingApp::query()->where('driver', 'zoho')->sole(), $zoho->fresh(), '2026-10-02', 'Test', [
            ['account' => 'BANK', 'debit' => 1000, 'credit' => 0, 'description' => 'x'], ['account' => 'REV', 'debit' => 0, 'credit' => 1000, 'description' => 'y'],
        ]);
        expect($zoho->fresh()?->access_token)->toBe('new');
    });
    Http::assertSent(fn ($r) => str_contains($r->url(), 'organization_id=777') && $r['line_items'][0]['debit_or_credit'] === 'debit');
});

it('posts the platform\'s own books and offers CSV, Excel and PDF exports to providers', function (): void {
    tenancy()->end();
    AccountingApp::create(['driver' => 'sage', 'offered' => true, 'client_id' => 's', 'client_secret' => 's']);
    $conn = PlatformAccountingConnection::create(['driver' => 'sage', 'enabled' => true, 'access_token' => 't', 'token_expires_at' => now()->addHour(),
        'account_map' => ['bank' => 'B', 'revenue:subscriptions' => 'R', 'revenue:wallet' => 'W', 'vat:output' => 'V'], 'exported_until' => now()->subDays(2)->toDateString()]);
    $sub = Subscription::query()->sole();
    SubscriptionInvoice::create(['number' => 'CF-1', 'tenant_id' => $this->clinic->id, 'subscription_id' => $sub->id, 'period_start' => now()->subMonth(), 'period_end' => now(),
        'amount_cents' => 100000, 'messaging_units' => 0, 'messaging_overage_cents' => 0, 'vat_cents' => 15000, 'total_cents' => 115000, 'status' => 'paid',
        'checkout_token' => 'tok', 'due_at' => now()->subDay(), 'paid_at' => now()->subDay()]);
    Http::fake(['api.accounting.sage.com/*' => Http::response(['id' => 'SJ1'])]);

    expect(app(JournalExporter::class)->run(AccountingApp::query()->where('driver', 'sage')->sole(), $conn, fn (string $d) => JournalExporter::platformTotals($d)))->toBe(1);
    Http::assertSent(function ($r): bool {
        $lines = $r['journal']['journal_lines'];

        return abs(array_sum(array_column($lines, 'debit')) - array_sum(array_column($lines, 'credit'))) < 0.001 && count($lines) === 3;
    });

    $base = 'http://sunrise.clinicflow.test/finance/exports/journal?from='.today()->toDateString().'&to='.today()->toDateString();
    $this->actingAs($this->ownerUser)->get($base.'&format=csv')->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
    expect($this->actingAs($this->ownerUser)->get($base.'&format=xlsx')->assertOk()->getContent())->toStartWith('PK')
        ->and($this->actingAs($this->ownerUser)->get($base.'&format=pdf')->assertOk()->getContent())->toStartWith('%PDF');
});
