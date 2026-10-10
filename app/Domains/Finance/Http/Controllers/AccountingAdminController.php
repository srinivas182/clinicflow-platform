<?php

declare(strict_types=1);

namespace App\Domains\Finance\Http\Controllers;

use App\Domains\Finance\Accounting\AccountingApp;
use App\Domains\Finance\Accounting\AccountingDriver;
use App\Domains\Finance\Accounting\JournalExporter;
use App\Domains\Finance\Accounting\PlatformAccountingConnection;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Super admin: which accounting apps providers may connect (with Dr Business Flow's
 * registered app credentials) and the platform's own books.
 */
class AccountingAdminController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Admin/Accounting', [
            'apps' => collect(AccountingDriver::cases())->map(function (AccountingDriver $d): array {
                $app = AccountingApp::query()->where('driver', $d->value)->first();
                $conn = PlatformAccountingConnection::query()->where('driver', $d->value)->first();

                return ['driver' => $d->value, 'label' => $d->label(), 'offered' => (bool) $app?->offered, 'clientId' => $app?->client_id, 'hasSecret' => filled($app?->client_secret), 'region' => $app?->region,
                    'platform' => $conn === null ? null : ['enabled' => $conn->enabled, 'autoExport' => $conn->auto_export, 'map' => $conn->account_map ?? [], 'exportedUntil' => $conn->exported_until?->toDateString(), 'error' => $conn->last_error]];
            })->values(),
            'callbackUrls' => collect(AccountingDriver::cases())->mapWithKeys(fn (AccountingDriver $d) => [$d->value => FinanceOpsController::callbackUrl($d)]),
            'platformAccounts' => ['bank', 'revenue:subscriptions', 'revenue:wallet', 'vat:output'],
        ]);
    }

    public function save(Request $request, string $driver): RedirectResponse
    {
        $data = $request->validate(['offered' => ['required', 'boolean'], 'client_id' => ['nullable', 'string', 'max:255'], 'client_secret' => ['nullable', 'string', 'max:255'], 'region' => ['nullable', Rule::in(['com', 'eu', 'in', 'com.au'])]]);
        $app = AccountingApp::query()->firstOrNew(['driver' => AccountingDriver::from($driver)->value]);
        $app->fill(['offered' => (bool) $data['offered'], 'client_id' => $data['client_id'] ?? $app->client_id, 'region' => $data['region'] ?? $app->region]);
        if (filled($data['client_secret'] ?? null)) {
            $app->client_secret = (string) $data['client_secret'];
        }
        $app->save();

        return back()->with('success', AccountingDriver::from($driver)->label().' saved.');
    }

    public function connect(string $driver): HttpResponse
    {
        return FinanceOpsController::redirectToApp(AccountingDriver::from($driver), 'platform');
    }

    public function updatePlatform(Request $request, string $driver): RedirectResponse
    {
        $data = $request->validate(['enabled' => ['required', 'boolean'], 'auto_export' => ['required', Rule::in(['off', 'daily', 'hourly'])], 'account_map' => ['array'], 'account_map.*' => ['nullable', 'string', 'max:64']]);
        $conn = PlatformAccountingConnection::query()->where('driver', $driver)->firstOrFail();
        $conn->forceFill(['enabled' => (bool) $data['enabled'], 'auto_export' => $data['auto_export'], 'account_map' => array_filter((array) ($data['account_map'] ?? []))])->save();
        if ($conn->enabled) {
            PlatformAccountingConnection::query()->whereKeyNot($conn->id)->update(['enabled' => false]);
        }

        return back()->with('success', 'Platform accounting saved.');
    }

    public function exportPlatform(JournalExporter $exporter): RedirectResponse
    {
        $conn = PlatformAccountingConnection::query()->where('enabled', true)->firstOrFail();
        $days = $exporter->run(AccountingApp::query()->where('driver', $conn->driver->value)->firstOrFail(), $conn, fn (string $d) => JournalExporter::platformTotals($d));

        return back()->with($conn->refresh()->last_error ? 'error' : 'success', $conn->refresh()->last_error ?? "{$days} day(s) exported.");
    }
}
