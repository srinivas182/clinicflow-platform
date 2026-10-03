<?php

namespace App\Providers;

use App\Domains\Billing\Contracts\PaymentGateway;
use App\Domains\Billing\Gateways\GatewayFactory;
use App\Domains\Billing\Support\FakePaymentGateway;
use App\Domains\Branches\Support\BranchContext;
use App\Domains\Claims\Contracts\ClaimsSwitch;
use App\Domains\Claims\Support\DemoClaimsSwitch;
use App\Domains\Finance\Support\LedgerPoster;
use App\Domains\Identity\Contracts\OtpSender;
use App\Domains\Identity\Enums\Permission;
use App\Domains\Identity\Models\Staff;
use App\Domains\Identity\Support\GatewayOtpSender;
use App\Domains\Messaging\Contracts\MessageSender;
use App\Domains\Messaging\Support\GatewayMessageSender;
use App\Domains\Prescribing\Contracts\DrugDatabase;
use App\Domains\Prescribing\Support\DemoDrugDatabase;
use App\Domains\Scheduling\Calendar\CalendarSync;
use App\Domains\Scheduling\Models\Appointment;
use App\Domains\Telemedicine\Support\OnlineConsultHooks;
use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
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
        LedgerPoster::register();
        OnlineConsultHooks::register();
        BranchContext::register();
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

            $staff = Staff::query()->find($user->id);

            return $staff instanceof Staff && $staff->checkPermissionTo($ability);
        });

        RateLimiter::for('login', fn (Request $request) => Limit::perMinute(5)->by(strtolower($request->string('login')->toString()).'|'.$request->ip()));
        RateLimiter::for('login-code', fn (Request $request) => Limit::perMinute(10)->by((string) $request->ip()));
    }
}
