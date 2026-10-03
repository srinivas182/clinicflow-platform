<?php

declare(strict_types=1);

namespace App\Domains\Finance\Http\Controllers;

use App\Domains\Billing\Actions\Debtors;
use App\Domains\Billing\Models\Invoice;
use App\Domains\Billing\Support\Vat;
use App\Domains\Finance\Accounting\AccountingApp;
use App\Domains\Finance\Accounting\AccountingConnection;
use App\Domains\Finance\Accounting\AccountingDriver;
use App\Domains\Finance\Accounting\JournalExporter;
use App\Domains\Finance\Accounting\SimpleXlsx;
use App\Domains\Identity\Enums\Permission;
use App\Domains\Platform\Models\Provider;
use App\Domains\Platform\Models\Setting;
use App\Http\Controllers\Controller;
use App\Models\User;
use Dompdf\Dompdf;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * VAT, debtors, accounting connection and exports for a provider.
 */
class FinanceOpsController extends Controller
{
    public function vat(Request $request, Debtors $debtors): Response
    {
        $this->authorize(Permission::FINANCE_VIEW);
        $from = $request->string('from')->toString() ?: now()->startOfMonth()->subMonth()->toDateString();
        $to = $request->string('to')->toString() ?: now()->startOfMonth()->subDay()->toDateString();

        return Inertia::render('Finance/Vat', [
            'settings' => ['registered' => Vat::registered(), 'number' => Vat::number(), 'rate' => Vat::rate(), 'zero_rated_kinds' => (array) Setting::get('vat', 'zero_rated_kinds', [])],
            'report' => array_map(fn (int $c) => $c / 100, $debtors->vatReport($from, $to)),
            'period' => ['from' => $from, 'to' => $to],
        ]);
    }

    public function saveVat(Request $request): RedirectResponse
    {
        $this->authorize(Permission::SETTINGS_MANAGE);
        $data = $request->validate([
            'registered' => ['required', 'boolean'], 'number' => ['nullable', 'required_if:registered,true', 'regex:/^4\d{9}$/'],
            'rate' => ['required', 'numeric', 'between:0,30'], 'zero_rated_kinds' => ['array'], 'zero_rated_kinds.*' => [Rule::in(['consultation', 'procedure', 'medicine', 'lab', 'certificate', 'other'])],
        ], ['number.regex' => 'A South African VAT number has 10 digits and starts with 4.']);
        foreach (['registered', 'number', 'rate', 'zero_rated_kinds'] as $key) {
            Setting::put('vat', $key, $data[$key] ?? ($key === 'zero_rated_kinds' ? [] : null));
        }

        return back()->with('success', 'VAT settings saved. They apply to new invoice lines.');
    }

    public function debtors(Debtors $debtors): Response
    {
        $this->authorize(Permission::BILLING_COLLECT);
        $ageing = $debtors->ageing();

        return Inertia::render('Finance/Debtors', [
            'buckets' => array_map(fn (int $c) => $c / 100, $ageing['buckets']),
            'patients' => array_map(fn (array $p) => ['id' => $p['patient_id'], 'name' => $p['name'], 'balance' => $p['balance'] / 100, 'days' => $p['oldest_days']], $ageing['patients']),
            'writeOffs' => DB::table('debt_write_offs')->whereNull('approved_at')->get(['id', 'invoice_id', 'amount_cents', 'reason', 'requested_by']),
        ]);
    }

    public function debtorAction(Request $request, string $action, Debtors $debtors): RedirectResponse
    {
        $me = $this->user($request)->id;
        if ($action === 'statements') {
            $this->authorize(Permission::BILLING_COLLECT);
            $n = $debtors->sendStatements();

            return back()->with('success', "{$n} statement(s) sent.");
        }
        $this->authorize(Permission::BILLING_REFUND);
        $action === 'write-off'
            ? $debtors->requestWriteOff(Invoice::query()->findOrFail($request->string('invoice_id')->toString()), $request->string('reason')->toString(), $me)
            : $debtors->approveWriteOff($request->integer('write_off_id'), $me);

        return back()->with('success', $action === 'write-off' ? 'Write-off requested. A second person must approve it.' : 'Write-off approved and credited.');
    }

    public function accounting(): Response
    {
        $this->authorize(Permission::SETTINGS_MANAGE);
        $accounts = DB::table('ledger_entries')->distinct()->orderBy('account')->pluck('account');

        return Inertia::render('Finance/Accounting', [
            'apps' => AccountingApp::query()->where('offered', true)->get()->filter(fn (AccountingApp $a) => $a->isConfigured())->map(fn (AccountingApp $a) => ['driver' => $a->driver->value, 'label' => $a->driver->label()])->values(),
            'connections' => AccountingConnection::query()->get()->map(fn (AccountingConnection $c) => [
                'driver' => $c->driver->value, 'label' => $c->driver->label(), 'enabled' => $c->enabled, 'autoExport' => $c->auto_export,
                'exportedUntil' => $c->exported_until?->toDateString(), 'error' => $c->last_error, 'map' => $c->account_map ?? [],
            ])->values(),
            'accounts' => $accounts,
        ]);
    }

    public function connect(string $driver): HttpResponse
    {
        $this->authorize(Permission::SETTINGS_MANAGE);
        $provider = tenant();
        abort_unless($provider instanceof Provider, 404);

        return self::redirectToApp(AccountingDriver::from($driver), (string) $provider->id);
    }

    public function updateConnection(Request $request, string $driver): RedirectResponse
    {
        $this->authorize(Permission::SETTINGS_MANAGE);
        $data = $request->validate(['enabled' => ['required', 'boolean'], 'auto_export' => ['required', Rule::in(['off', 'daily', 'hourly'])], 'account_map' => ['array'], 'account_map.*' => ['nullable', 'string', 'max:64']]);
        $connection = AccountingConnection::query()->where('driver', $driver)->firstOrFail();
        $connection->forceFill(['enabled' => (bool) $data['enabled'], 'auto_export' => $data['auto_export'], 'account_map' => array_filter((array) ($data['account_map'] ?? []), fn ($v) => $v !== null && $v !== '')])->save();
        if ($connection->enabled) {
            AccountingConnection::query()->whereKeyNot($connection->id)->update(['enabled' => false]);
        }

        return back()->with('success', 'Accounting connection saved.');
    }

    public function exportNow(JournalExporter $exporter): RedirectResponse
    {
        $this->authorize(Permission::SETTINGS_MANAGE);
        $connection = AccountingConnection::query()->where('enabled', true)->firstOrFail();
        $app = AccountingApp::query()->where('driver', $connection->driver->value)->firstOrFail();
        $days = $exporter->run($app, $connection, fn (string $d) => JournalExporter::providerTotals($d));

        return back()->with($connection->refresh()->last_error ? 'error' : 'success', $connection->refresh()->last_error ?? "{$days} day(s) exported.");
    }

    /**
     * Built-in exports, always available: journal, invoices, payments, VAT — CSV, Excel or PDF.
     */
    public function export(Request $request, string $type, Debtors $debtors): HttpResponse
    {
        $this->authorize(Permission::FINANCE_VIEW);
        $from = $request->string('from')->toString() ?: now()->startOfMonth()->toDateString();
        $to = $request->string('to')->toString() ?: now()->toDateString();
        $range = [$from.' 00:00:00', $to.' 23:59:59'];
        $rows = match ($type) {
            'journal' => [['Date', 'Account', 'Debit', 'Credit', 'Description'], ...DB::table('ledger_entries')->whereBetween('occurred_at', $range)->orderBy('id')->get()
                ->map(fn ($e) => [substr((string) $e->occurred_at, 0, 10), $e->account, $e->debit_cents / 100, $e->credit_cents / 100, $e->description])->all()],
            'invoices' => [['Number', 'Date', 'Total', 'VAT', 'Paid', 'Status'], ...DB::table('invoices')->whereBetween('created_at', $range)->orderBy('number')->get()
                ->map(fn ($i) => [$i->number, substr((string) $i->created_at, 0, 10), $i->total_cents / 100, $i->vat_cents / 100, $i->paid_cents / 100, $i->status])->all()],
            'payments' => [['Date', 'Method', 'Amount', 'Refunded', 'Reference'], ...DB::table('payments')->where('status', 'succeeded')->whereBetween('created_at', $range)->orderBy('id')->get()
                ->map(fn ($p) => [substr((string) $p->created_at, 0, 10), $p->method, $p->amount_cents / 100, $p->refunded_cents / 100, $p->reference])->all()],
            'vat' => (function () use ($debtors, $from, $to): array {
                $r = $debtors->vatReport($from, $to);

                return [['Item', 'Amount'], ['Sales (incl. VAT)', $r['sales_cents'] / 100], ['Output VAT', $r['output_vat_cents'] / 100], ['Purchases (excl. VAT)', $r['purchases_cents'] / 100], ['Input VAT', $r['input_vat_cents'] / 100], ['VAT payable', $r['net_vat_cents'] / 100]];
            })(),
            default => abort(404),
        };
        $name = "{$type}-{$from}-{$to}";
        activity('finance')->withProperties(['type' => $type, 'format' => $request->string('format')->toString()])->log('Finance export downloaded');

        return match ($request->string('format')->toString()) {
            'xlsx' => response(SimpleXlsx::build($rows), 200, ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'Content-Disposition' => "attachment; filename=\"{$name}.xlsx\""]),
            'pdf' => response($this->pdf($type, $rows), 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => "attachment; filename=\"{$name}.pdf\""]),
            default => response($this->csv($rows), 200, ['Content-Type' => 'text/csv', 'Content-Disposition' => "attachment; filename=\"{$name}.csv\""]),
        };
    }

    public static function redirectToApp(AccountingDriver $driver, string $owner): HttpResponse
    {
        $app = AccountingApp::query()->where('driver', $driver->value)->where('offered', true)->first();
        abort_unless($app instanceof AccountingApp && $app->isConfigured(), 404, 'This accounting app is not available.');
        $state = Crypt::encryptString((string) json_encode(['o' => $owner, 'd' => $driver->value, 'x' => now()->addMinutes(15)->timestamp]));

        return redirect()->away($driver->authorizeUrl((string) $app->client_id, self::callbackUrl($driver), $state, $app->region));
    }

    public static function callbackUrl(AccountingDriver $driver): string
    {
        return rtrim((string) config('app.url'), '/')."/accounting/callback/{$driver->value}";
    }

    /**
     * @param  array<int, array<int, mixed>>  $rows
     */
    private function csv(array $rows): string
    {
        $out = fopen('php://temp', 'r+');
        abort_if($out === false, 500);
        foreach ($rows as $row) {
            fputcsv($out, array_map(fn ($v) => is_scalar($v) || $v === null ? $v : (string) json_encode($v), $row));
        }
        rewind($out);

        return (string) stream_get_contents($out);
    }

    /**
     * @param  array<int, array<int, mixed>>  $rows
     */
    private function pdf(string $type, array $rows): string
    {
        $e = fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES);
        $html = '<html><body style="font-family: DejaVu Sans, sans-serif; font-size: 9px"><h3>'.$e(ucfirst($type)).'</h3><table width="100%" cellpadding="3" style="border-collapse:collapse">';
        foreach ($rows as $i => $row) {
            $html .= '<tr>'.implode('', array_map(fn ($v) => ($i === 0 ? '<th align="left">' : '<td>').$e($v).($i === 0 ? '</th>' : '</td>'), $row)).'</tr>';
        }
        $pdf = new Dompdf;
        $pdf->loadHtml($html.'</table></body></html>');
        $pdf->render();

        return (string) $pdf->output();
    }

    private function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
