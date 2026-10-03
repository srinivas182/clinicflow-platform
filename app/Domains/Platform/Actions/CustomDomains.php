<?php

declare(strict_types=1);

namespace App\Domains\Platform\Actions;

use App\Domains\Platform\Models\Provider;
use App\Domains\Platform\Models\Subscription;
use App\Domains\Platform\Support\DnsResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * A practice's own domain: it points the domain at its Clinic Flow address,
 * proves ownership with a TXT record, and Clinic Flow then serves the
 * practice there. Certificates are issued per domain by the web server
 * (on-demand TLS asks tlsAllowed() first) — finished during deployment.
 */
class CustomDomains
{
    public function __construct(private readonly DnsResolver $dns) {}

    public function add(Provider $provider, string $domain): object
    {
        $domain = strtolower(trim($domain, " ./\t"));
        $package = Subscription::query()->where('tenant_id', $provider->id)->latest('id')->first()?->package;
        if ($package === null || ! $package->hasFeature('custom_domain')) {
            throw ValidationException::withMessages(['domain' => 'Your package does not include a custom domain.']);
        }
        if (preg_match('/^(?=.{4,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,}$/', $domain) !== 1 || str_ends_with($domain, '.'.config('clinicflow.provider_domain', 'clinicflow.co.za'))) {
            throw ValidationException::withMessages(['domain' => 'Enter a domain you own, e.g. book.yourpractice.co.za.']);
        }
        if (DB::table('custom_domains')->where('domain', $domain)->exists() || DB::table('domains')->where('domain', $domain)->exists()) {
            throw ValidationException::withMessages(['domain' => 'That domain is already in use on Clinic Flow.']);
        }
        $id = DB::table('custom_domains')->insertGetId(['tenant_id' => $provider->id, 'domain' => $domain, 'token' => 'cf-verify-'.Str::lower(Str::random(24)), 'created_at' => now(), 'updated_at' => now()]);

        return DB::table('custom_domains')->where('id', $id)->firstOrFail();
    }

    public function verify(int $id): bool
    {
        $d = DB::table('custom_domains')->where('id', $id)->firstOrFail();
        $found = in_array((string) $d->token, $this->dns->txt('_clinicflow.'.$d->domain), true);
        if (! $found) {
            DB::table('custom_domains')->where('id', $id)->update(['last_check' => 'TXT record not found yet (DNS changes can take a few hours).', 'updated_at' => now()]);

            return false;
        }
        DB::transaction(function () use ($d, $id): void {
            DB::table('custom_domains')->where('id', $id)->update(['status' => 'verified', 'verified_at' => now(), 'last_check' => null, 'updated_at' => now()]);
            Provider::query()->findOrFail((string) $d->tenant_id)->domains()->firstOrCreate(['domain' => (string) $d->domain]);
        });

        return true;
    }

    public function remove(int $id, string $tenantId): void
    {
        $d = DB::table('custom_domains')->where('id', $id)->where('tenant_id', $tenantId)->firstOrFail();
        DB::table('domains')->where('domain', $d->domain)->where('tenant_id', $tenantId)->delete();
        DB::table('custom_domains')->where('id', $id)->delete();
    }

    /**
     * Asked by the web server before it requests a certificate for a domain.
     */
    public function tlsAllowed(string $domain): bool
    {
        return DB::table('custom_domains')->where('domain', strtolower($domain))->where('status', 'verified')->exists();
    }
}
