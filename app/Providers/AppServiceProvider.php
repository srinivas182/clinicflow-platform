<?php

namespace App\Providers;

use App\Domains\Api\Webhooks\WebhookEvents;
use App\Domains\Billing\Contracts\PaymentGateway;
use App\Domains\Billing\Enums\PaymentStatus;
use App\Domains\Billing\Gateways\GatewayFactory;
use App\Domains\Billing\Models\Payment;
use App\Domains\Billing\Prepaid\PrepaidPackages;
use App\Domains\Billing\Support\FakePaymentGateway;
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
use App\Domains\Platform\Storage\FileStore;
use App\Domains\Prescribing\Contracts\DrugDatabase;
use App\Domains\Prescribing\Support\DemoDrugDatabase;
use App\Domains\Scheduling\Calendar\CalendarSync;
use App\Domains\Scheduling\Models\Appointment;
use App\Domains\Telemedicine\Support\OnlineConsultHooks;
use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
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
        FileStore::configure();
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

            $staff = Staff::query()->find($user->id);

            return $staff instanceof Staff && $staff->checkPermissionTo($ability);
        });

        RateLimiter::for('login', fn (Request $request) => Limit::perMinute(5)->by(strtolower($request->string('login')->toString()).'|'.$request->ip()));
        RateLimiter::for('login-code', fn (Request $request) => Limit::perMinute(10)->by((string) $request->ip()));
        // Patient search: 60 per minute per user (against scripted scraping).
        RateLimiter::for('patient-search', fn (Request $request) => Limit::perMinute(60)->by('u:'.($request->user()?->getAuthIdentifier() ?? $request->ip())));
    }
}
