<?php

declare(strict_types=1);

namespace App\Domains\Platform\Http\Controllers;

use App\Domains\Identity\Enums\Permission;
use App\Domains\Platform\Actions\CustomDomains;
use App\Domains\Platform\Models\Provider;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The practice's own domain: add, see the DNS records to create, verify, remove.
 * Also the web server's "may I issue a certificate for this domain?" check.
 */
class CustomDomainController extends Controller
{
    public function index(): Response
    {
        $this->authorize(Permission::SETTINGS_MANAGE);
        $provider = $this->provider();

        return Inertia::render('Settings/Domains', [
            'domains' => DB::table('custom_domains')->where('tenant_id', $provider->id)->get(['id', 'domain', 'token', 'status', 'ssl_status', 'last_check']),
            'target' => (string) DB::table('domains')->where('tenant_id', $provider->id)->where('domain', 'like', '%.'.config('clinicflow.provider_domain'))->value('domain'),
            'spf' => 'v=spf1 include:'.config('clinicflow.provider_domain').' ~all',
        ]);
    }

    public function store(Request $request, CustomDomains $domains): RedirectResponse
    {
        $this->authorize(Permission::SETTINGS_MANAGE);
        $domains->add($this->provider(), $request->string('domain')->toString());

        return back()->with('success', 'Domain added. Create the DNS records shown, then press Verify.');
    }

    public function verify(int $domain, CustomDomains $domains): RedirectResponse
    {
        $this->authorize(Permission::SETTINGS_MANAGE);
        abort_unless(DB::table('custom_domains')->where('id', $domain)->where('tenant_id', $this->provider()->id)->exists(), 404);

        return $domains->verify($domain) ? back()->with('success', 'Domain verified. The secure certificate is issued automatically on the first visit.') : back()->with('error', 'The TXT record was not found yet. DNS changes can take a few hours.');
    }

    public function destroy(int $domain, CustomDomains $domains): RedirectResponse
    {
        $this->authorize(Permission::SETTINGS_MANAGE);
        $domains->remove($domain, $this->provider()->id);

        return back()->with('success', 'Domain removed.');
    }

    public function tlsAllowed(Request $request, CustomDomains $domains): HttpResponse
    {
        return response('', $domains->tlsAllowed($request->string('domain')->toString()) ? 200 : 404);
    }

    private function provider(): Provider
    {
        $provider = tenant();
        abort_unless($provider instanceof Provider, 404);

        return $provider;
    }
}
