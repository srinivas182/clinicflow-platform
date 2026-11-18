<?php

declare(strict_types=1);

namespace App\Domains\Platform\Http\Controllers\Admin;

use App\Domains\Platform\Enums\SubscriptionStatus;
use App\Domains\Platform\Models\Package;
use App\Domains\Platform\Models\Provider;
use App\Domains\Platform\Models\Subscription;
use App\Domains\Platform\Notifications\PracticeNotice;
use App\Domains\Platform\Support\PracticeContacts;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Notification;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Super admin: package builder (prices, trial, limits, active).
 */
class PackageAdminController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Admin/Packages/Index', [
            'packages' => Package::query()->orderBy('sort_order')->get()->map(fn (Package $p): array => [
                'id' => $p->id,
                'name' => $p->name,
                'providerType' => $p->provider_type->label(),
                'priceMonthly' => ($p->pending_price_monthly_cents ?? $p->price_monthly_cents) / 100,
                'pending' => $p->pending_price_from === null ? null : ['from' => $p->pending_price_from->toDateString(), 'current' => $p->price_monthly_cents / 100],
                'paying' => $this->paying($p)->count(),
                'trialDays' => $p->trial_days,
                'limits' => $p->limits,
                'features' => $p->features,
                'isActive' => $p->is_active,
            ])->values(),
        ]);
    }

    public function update(Request $request, Package $package): RedirectResponse
    {
        $data = $request->validate([
            'price_monthly' => ['required', 'numeric', 'min:0', 'max:1000000'],
            'trial_days' => ['required', 'integer', 'min:0', 'max:90'],
            'is_active' => ['required', 'boolean'],
        ]);

        $cents = (int) round(((float) $data['price_monthly']) * 100);
        $old = $package->price_monthly_cents;
        $package->forceFill(['trial_days' => (int) $data['trial_days'], 'is_active' => (bool) $data['is_active']]);
        $paying = $this->paying($package);
        $message = "{$package->name} updated.";
        if ($cents > $old && $package->pending_price_monthly_cents === $cents) {
            $message .= ' The price rise already notified still applies from '.$package->pending_price_from?->toDateString().'.';
        } elseif ($cents > $old && $paying->isNotEmpty()) {
            // A price rise for paying practices: 30 days' notice by email; it applies from that date (beta #22).
            $from = now()->addDays(Package::NOTICE_DAYS)->startOfDay();
            $package->forceFill(['pending_price_monthly_cents' => $cents, 'pending_price_from' => $from]);
            $this->notify($package, $paying, $old, $cents, $from->toDateString());
            $message .= " The new price applies from {$from->toDateString()}; {$paying->count()} paying practice(s) were emailed 30 days' notice.";
        } elseif ($cents !== $old || $package->pending_price_from !== null) {
            $package->forceFill(['price_monthly_cents' => $cents, 'price_annual_cents' => (int) round($cents * 12 * 0.85), 'pending_price_monthly_cents' => null, 'pending_price_from' => null]);
            $message .= $paying->isNotEmpty() && $cents < $old ? ' Paying practices move to the lower price at their next invoice.' : '';
        }
        $package->save();
        activity('platform')->causedBy($request->user())->performedOn($package)->withProperties(['old_cents' => $old, 'new_cents' => $cents, 'paying' => $paying->count()])->log('Package updated');

        return back()->with('success', $message);
    }

    /** @return Collection<int, string> tenant ids of practices paying for (or trialling) this package */
    private function paying(Package $package): Collection
    {
        return Subscription::query()->where('package_id', $package->id)->whereIn('status', [SubscriptionStatus::Active->value, SubscriptionStatus::PastDue->value, SubscriptionStatus::Trialing->value])
            ->pluck('tenant_id')->map(fn ($id): string => (string) $id)->values();
    }

    /** @param  Collection<int, string>  $tenants */
    private function notify(Package $package, Collection $tenants, int $old, int $new, string $from): void
    {
        $r = fn (int $c): string => 'R'.number_format($c / 100, 2, '.', ' ');
        foreach (Provider::query()->whereIn('id', $tenants)->get() as $provider) {
            if (! $provider instanceof Provider) {
                continue;
            }
            Notification::send(PracticeContacts::owners($provider->id), new PracticeNotice("Price change for {$package->name} from {$from}",
                ["The {$package->name} plan changes from {$r($old)} to {$r($new)} a month (excl. VAT) from {$from}.", 'Invoices for periods starting before then keep the current price. You can change plan at any time in Settings → Subscription.'],
                'View plans', PracticeContacts::url($provider, '/settings/subscription'), 'price_change'));
        }
    }
}
