<?php

namespace App\Providers;

use App\Domains\Api\Webhooks\WebhookEvents;
use App\Domains\Billing\Contracts\PaymentGateway;
use App\Domains\Billing\Enums\PaymentStatus;
use App\Domains\Billing\Gateways\GatewayFactory;
use App\Domains\Billing\Models\Payment;
use App\Domains\Billing\Prepaid\PrepaidPackages;
use App\Domains\Billing\Support\FakePaymentGateway;
use App\Domains\Branches\Models\Branch;
use App\Domains\Branches\Support\BranchContext;
use App\Domains\Claims\Contracts\ClaimsSwitch;
use App\Domains\Claims\Support\DemoClaimsSwitch;
use App\Domains\Finance\Support\LedgerPoster;
use App\Domains\Identity\Contracts\OtpSender;
use App\Domains\Identity\Enums\Permission;
use App\Domains\Identity\Models\Staff;
use App\Domains\Identity\Support\GatewayOtpSender;
use App\Domains\Lab\Inbound\LabConnections;
use App\Domains\Lab\Models\LabOrder;
use App\Domains\Messaging\Contracts\MessageSender;
use App\Domains\Messaging\Support\GatewayMessageSender;
use App\Domains\Platform\Models\Provider;
use App\Domains\Platform\Security\ClamdScanner;
use App\Domains\Platform\Security\VirusScanner;
use App\Domains\Platform\Storage\FileStore;
use App\Domains\Platform\Support\TenantLookupCache;
use App\Domains\Prescribing\Contracts\DrugDatabase;
use App\Domains\Prescribing\Support\DemoDrugDatabase;
use App\Domains\Scheduling\Calendar\CalendarSync;
use App\Domains\Scheduling\Models\Appointment;
use App\Domains\Telemedicine\Support\OnlineConsultHooks;
use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Stancl\Tenancy\Database\Models\Domain;
use Stancl\Tenancy\Resolvers\DomainTenantResolver;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(VirusScanner::class, fn () => new ClamdScanner(
            (string) config('clinicflow.security.virus_scan.host'), (int) config('clinicflow.security.virus_scan.port')));
        // Demo implementations until the client licenses a drug database and chooses a switch.
        $this->app->singleton(DrugDatabase::class, DemoDrugDatabase::class);
        $this->app->singleton(ClaimsSwitch::class, DemoClaimsSwitch::class);
        $this->app->singleton(MessageSender::class, GatewayMessageSender::class);
        $this->app->singleton(OtpSender::class, GatewayOtpSender::class);
        // Provider gateway adapters (Paystack, PayFast, Peach, Yoco) bind per provider in production.
        $this->app->singleton(FakePaymentGateway::class);
        $this->app->bind(PaymentGateway::class, fn () => GatewayFactory::forProvider());
    }

    public function boot(): void
    {
        // Point the "files" disk at the super admin's active storage (S3, S3-compatible or local).
        // Never show debug pages in production: they can reveal code and settings.
        if ($this->app->isProduction() && (bool) config('app.debug')) {
            config(['app.debug' => false]);
            Log::critical('APP_DEBUG was on in production and has been forced off. Fix the environment settings.');
        }
        FileStore::configure();
        require base_path('routes/channels.php');
        // N+1 queries: logged in development and tests (never thrown), so they can be found and fixed.
        if (! $this->app->isProduction()) {
            Model::preventLazyLoading();
            Model::handleLazyLoadingViolationUsing(function ($model, string $relation): void {
                Log::channel('single')->warning('N+1 query: lazy loading '.$relation.' on '.$model::class);
            });
        }
        LedgerPoster::register();
        OnlineConsultHooks::register();
        BranchContext::register();
        WebhookEvents::register();
        // New in-house lab orders go to the practice's connected lab system, if one is chosen.
        LabOrder::creating(function (LabOrder $order): void {
            if (tenant() !== null && in_array($order->getAttribute('source') ?? 'in_house', ['in_house'], true) && $order->getAttribute('external_key_id') === null) {
                $order->setAttribute('external_key_id', app(LabConnections::class)->outgoingKey());
            }
        });
        Payment::saved(function (Payment $p): void {
            if (tenant() !== null && $p->status === PaymentStatus::Succeeded && ($p->wasRecentlyCreated || $p->wasChanged('status'))) {
                app(PrepaidPackages::class)->activatePaid($p->invoice_id);
            }
        });
        Appointment::saved(function (Appointment $a): void {
            if (tenant() !== null) {
                app(CalendarSync::class)->sync($a);
            }
        });

        /*
         * Workspace permissions are answered by the provider-side Staff record
         * (provider database). Outside a workspace they are always denied.
         */
        // Practice lookup by domain: cached for 5 minutes and cleared the moment a practice or its domains change
        // (so suspending a practice takes effect immediately).
        DomainTenantResolver::$shouldCache = (bool) config('clinicflow.performance.cache_tenant_lookup', true);
        DomainTenantResolver::$cacheTTL = 300;
        // Cleared in the platform context (inside a practice, the cache is scoped to that practice).
        $forgetTenant = fn ($tenant) => $tenant instanceof Provider ? TenantLookupCache::forget($tenant) : null;
        Provider::saved($forgetTenant);
        Provider::deleted($forgetTenant);
        Domain::saved(fn ($d) => $d->tenant !== null ? $forgetTenant($d->tenant) : null);
        Domain::deleted(fn ($d) => $d->tenant !== null ? $forgetTenant($d->tenant) : null);
        // Active-branch count is cached per practice; clear it whenever a branch changes.
        Branch::saved(fn () => BranchContext::forgetCount());
        Branch::deleted(fn () => BranchContext::forgetCount());

        // Index-friendly day filters (whereDate wraps the column in DATE(), which stops MySQL using its index).

        // onDate: DATE columns, compared to the value's date. withinDay: DATETIME columns, a start-to-end-of-day range.

        Builder::macro('onDate', function (string $column, mixed $value) {

            /** @var Builder $this */

            return $this->where($column, Carbon::parse($value)->toDateString());

        });

        Builder::macro('withinDay', function (string $column, mixed $value) {

            /** @var Builder $this */
            $day = Carbon::parse($value);

            return $this->whereBetween($column, [$day->copy()->startOfDay(), $day->copy()->endOfDay()]);

        });

        Gate::before(function (User $user, string $ability): ?bool {
            if (! in_array($ability, Permission::all(), true)) {
                return null;
            }

            if (tenant() === null) {
                return false;
            }

            // Support sessions (practice-granted) may look at everything but change nothing (SupportSessionGuard blocks writes).
            if (request()->hasSession() && is_numeric(request()->session()->get('support_grant_id')) && (bool) $user->getAttribute('is_platform_admin')) {
                return in_array(request()->method(), ['GET', 'HEAD'], true);
            }

            // Loaded once per request with roles and permissions (kept on the request, never in a static,
            // so a long-running Octane worker can never carry one person's permissions into another request).
            $key = 'cf.staff.'.tenant('id').'.'.$user->id;
            $attributes = request()->attributes;
            if (! $attributes->has($key)) {
                $attributes->set($key, Staff::query()->with(['roles.permissions', 'permissions'])->find($user->id));
            }
            $staff = $attributes->get($key);

            return $staff instanceof Staff && $staff->checkPermissionTo($ability);
        });

        RateLimiter::for('login', fn (Request $request) => Limit::perMinute(5)->by(strtolower($request->string('login')->toString()).'|'.$request->ip()));
        RateLimiter::for('login-code', fn (Request $request) => Limit::perMinute(10)->by((string) $request->ip()));
        // Patient search: 60 per minute per user (against scripted scraping).
        RateLimiter::for('patient-search', fn (Request $request) => Limit::perMinute(60)->by('u:'.($request->user()?->getAuthIdentifier() ?? $request->ip())));
    }
}
