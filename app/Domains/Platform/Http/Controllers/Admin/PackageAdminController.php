<?php

declare(strict_types=1);

namespace App\Domains\Platform\Http\Controllers\Admin;

use App\Domains\Platform\Enums\ProviderType;
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
use Illuminate\Validation\Rule;
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
                'code' => $p->code,
                'summary' => $p->summary,
                'type' => $p->provider_type->value,
                'addons' => $p->addons,
            ])->values(),
            'catalogue' => ['features' => Package::FEATURES, 'limits' => Package::LIMITS, 'addons' => Package::ADDONS,
                'types' => collect(ProviderType::cases())->mapWithKeys(fn (ProviderType $t) => [$t->value => $t->label()])],
        ]);
    }

    /** Add a package (beta #19). New packages start switched off so they can be checked first. */
    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:40', 'regex:/^[a-z0-9-]+$/', Rule::unique(Package::class, 'code')],
            'name' => ['required', 'string', 'max:80', Rule::unique(Package::class, 'name')],
            'provider_type' => ['required', Rule::enum(ProviderType::class)],
            'summary' => ['nullable', 'string', 'max:160'],
            'price_monthly' => ['required', 'numeric', 'min:0', 'max:1000000'],
            'trial_days' => ['required', 'integer', 'min:0', 'max:90'],
            ...$this->detailRules(),
        ], ['code.regex' => 'Use lower-case letters, digits and dashes, e.g. clinic-plus.']);
        $cents = (int) round(((float) $data['price_monthly']) * 100);
        $package = new Package;
        $package->forceFill([
            'code' => $data['code'], 'name' => trim((string) $data['name']), 'provider_type' => $data['provider_type'], 'summary' => $data['summary'] ?? null,
            'price_monthly_cents' => $cents, 'price_annual_cents' => (int) round($cents * 12 * 0.85), 'trial_days' => $cents === 0 ? 0 : (int) $data['trial_days'],
            // Messaging allowances start at zero and are set under Messaging.
            'limits' => $this->limitsFrom($data) + ['sms' => 0, 'email' => 0, 'sms_overage_cents' => 35, 'email_overage_cents' => 5],
            'features' => array_values($data['features'] ?? []), 'addons' => array_values($data['addons'] ?? []),
            'is_active' => false, 'sort_order' => (int) Package::query()->max('sort_order') + 1,
        ])->save();
        activity('platform')->causedBy($request->user())->performedOn($package)->log('Package created');

        return back()->with('success', "{$package->name} added. It is switched off until you switch it on.");
    }

    /**
     * Edit what a package includes (beta #20). Practices already on it are emailed what they lose.
     */
    public function details(Request $request, Package $package): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80', Rule::unique(Package::class, 'name')->ignore($package->id)],
            'summary' => ['nullable', 'string', 'max:160'],
            ...$this->detailRules(),
        ]);
        $before = ['features' => $package->features, 'addons' => $package->addons, 'limits' => $package->limits];
        $limits = $this->limitsFrom($data) + array_diff_key($package->limits, Package::LIMITS);
        $package->forceFill(['name' => trim((string) $data['name']), 'summary' => $data['summary'] ?? null, 'limits' => $limits,
            'features' => array_values($data['features'] ?? []), 'addons' => array_values($data['addons'] ?? [])])->save();

        $lost = array_merge(
            array_map(fn (string $f) => 'No longer included: '.(Package::FEATURES[$f] ?? $f).'.', array_values(array_diff($before['features'], $package->features))),
            array_map(fn (string $a) => 'Add-on no longer offered: '.(Package::ADDONS[$a] ?? $a).'.', array_values(array_diff($before['addons'], $package->addons))),
        );
        foreach (Package::LIMITS as $key => $label) {
            $old = (int) ($before['limits'][$key] ?? 0);
            $new = (int) ($limits[$key] ?? 0);
            if ($old !== $new && ($new !== 0 && ($old === 0 || $new < $old))) {
                $lost[] = "{$label}: ".($old === 0 ? 'unlimited' : $old)." → {$new}.";
            }
        }
        $paying = $this->paying($package);
        if ($lost !== [] && $paying->isNotEmpty()) {
            foreach (Provider::query()->whereIn('id', $paying)->get() as $provider) {
                if ($provider instanceof Provider) {
                    Notification::send(PracticeContacts::owners($provider->id), new PracticeNotice("Changes to your {$package->name} plan", ["Dr Business Flow changed what {$package->name} includes:", ...$lost, 'You can change plan at any time in Settings → Subscription.'],
                        'View plans', PracticeContacts::url($provider, '/settings/subscription'), 'package_changed'));
                }
            }
        }
        activity('platform')->causedBy($request->user())->performedOn($package)->withProperties(['before' => $before, 'lost' => $lost])->log('Package details updated');

        return back()->with('success', "{$package->name} saved.".($lost !== [] && $paying->isNotEmpty() ? " {$paying->count()} practice(s) on it were emailed what changed." : ''));
    }

    /** @return array<string, list<mixed>> */
    private function detailRules(): array
    {
        $rules = ['features' => ['array'], 'features.*' => [Rule::in(array_keys(Package::FEATURES))], 'addons' => ['array'], 'addons.*' => [Rule::in(array_keys(Package::ADDONS))], 'limits' => ['array']];
        foreach (array_keys(Package::LIMITS) as $key) {
            $rules["limits.{$key}"] = ['required', 'integer', 'min:0', 'max:100000'];
        }

        return $rules;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, int>
     */
    private function limitsFrom(array $data): array
    {
        $limits = [];
        foreach (array_keys(Package::LIMITS) as $key) {
            $limits[$key] = (int) ($data['limits'][$key] ?? 0);
        }

        return $limits;
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
