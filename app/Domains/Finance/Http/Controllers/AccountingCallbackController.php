<?php

declare(strict_types=1);

namespace App\Domains\Finance\Http\Controllers;

use App\Domains\Finance\Accounting\AccountingApp;
use App\Domains\Finance\Accounting\AccountingClient;
use App\Domains\Finance\Accounting\AccountingConnection;
use App\Domains\Finance\Accounting\AccountingDriver;
use App\Domains\Finance\Accounting\PlatformAccountingConnection;
use App\Domains\Platform\Models\Provider;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Throwable;

/**
 * One fixed OAuth callback (central domain) for every provider and the platform.
 * The signed, time-limited state says whose books are being connected.
 */
class AccountingCallbackController extends Controller
{
    public function __invoke(Request $request, string $driver, AccountingClient $client): RedirectResponse
    {
        $d = AccountingDriver::from($driver);
        try {
            $state = json_decode(Crypt::decryptString($request->string('state')->toString()), true);
        } catch (Throwable) {
            abort(403, 'The connection request is invalid.');
        }
        abort_unless(is_array($state) && ($state['d'] ?? null) === $d->value && (int) ($state['x'] ?? 0) > now()->timestamp, 403, 'The connection request has expired. Try again.');
        $app = AccountingApp::query()->where('driver', $d->value)->firstOrFail();
        $tokens = $client->exchange($app, $request->string('code')->toString(), FinanceOpsController::callbackUrl($d));
        $fields = ['access_token' => $tokens['access_token'], 'refresh_token' => $tokens['refresh_token'], 'token_expires_at' => now()->addSeconds($tokens['expires_in'] - 60), 'org_id' => $tokens['org_id'], 'last_error' => null];

        if ($state['o'] === 'platform') {
            PlatformAccountingConnection::query()->updateOrCreate(['driver' => $d->value], $fields);

            return redirect('/admin/accounting')->with('success', "{$d->label()} connected for the platform's books.");
        }

        $provider = Provider::query()->findOrFail((string) $state['o']);
        $provider->run(fn () => AccountingConnection::query()->updateOrCreate(['driver' => $d->value], $fields));
        $domain = $provider->domains()->value('domain');

        return redirect()->away(($request->isSecure() ? 'https://' : 'http://').$domain.'/settings/accounting')->with('success', "{$d->label()} connected.");
    }
}
