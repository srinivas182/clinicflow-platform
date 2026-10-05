<?php

declare(strict_types=1);

namespace App\Domains\Platform\Actions;

use App\Domains\Billing\Models\SubscriptionInvoice;
use App\Domains\Platform\Models\Provider;
use App\Domains\Platform\Models\ProviderGroup;
use App\Domains\Reports\Analytics;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Groups of practices: membership, group admins, consolidated totals (never
 * patient records) and optional combined monthly billing.
 */
class ProviderGroups
{
    public function addMember(ProviderGroup $group, Provider $provider): void
    {
        if (DB::connection((string) config('tenancy.database.central_connection'))->table('provider_group_members')->where('tenant_id', $provider->id)->exists()) {
            throw ValidationException::withMessages(['tenant_id' => "{$provider->name} already belongs to a group."]);
        }
        DB::connection((string) config('tenancy.database.central_connection'))->table('provider_group_members')->insert(['provider_group_id' => $group->id, 'tenant_id' => $provider->id]);
    }

    public function addAdmin(ProviderGroup $group, string $email): void
    {
        $user = User::query()->where('email', strtolower(trim($email)))->first();
        if (! $user instanceof User) {
            throw ValidationException::withMessages(['email' => 'No Clinic Flow user has that email address.']);
        }
        DB::connection((string) config('tenancy.database.central_connection'))->table('provider_group_admins')->insertOrIgnore(['provider_group_id' => $group->id, 'user_id' => $user->id]);
    }

    /**
     * Totals per practice for a period: visits, appointments, new patients, takings and amounts owed.
     *
     * @return list<array<string, mixed>>
     */
    public function dashboard(ProviderGroup $group, string $from, string $to): array
    {
        $range = [$from.' 00:00:00', $to.' 23:59:59'];

        return array_values($group->members()->map(fn (Provider $p) => $p->run(fn () => [
            'id' => $p->id, 'name' => $p->name,
            'visits' => DB::table('visits')->whereBetween('visit_date', [$from, $to])->count(),
            'appointments' => DB::table('appointments')->whereBetween('starts_at', $range)->count(),
            'new_patients' => DB::table('patients')->whereBetween('created_at', $range)->count(),
            'takings' => (int) DB::table('payments')->where('status', 'succeeded')->whereBetween('created_at', $range)->sum(DB::raw('amount_cents - refunded_cents')),
            'owed' => (int) DB::table('invoices')->where('status', '!=', 'void')->sum(DB::raw('GREATEST(total_cents - paid_cents - credited_cents, 0)')),
            // Key figures only (never patient records).
            ...array_intersect_key(app(Analytics::class)->summary(CarbonImmutable::parse($from), CarbonImmutable::parse($to)),
                array_flip(['collection_rate', 'no_show_rate', 'avg_wait_minutes', 'utilisation'])),
        ]))->all());
    }

    /**
     * One invoice covering every unpaid member subscription invoice not yet grouped.
     */
    public function issueCombinedInvoice(ProviderGroup $group): ?int
    {
        if ($group->billing !== 'combined') {
            throw ValidationException::withMessages(['billing' => 'This group pays per practice.']);
        }
        $invoices = SubscriptionInvoice::query()->whereIn('tenant_id', $group->members()->pluck('id'))->where('status', '!=', 'paid')->whereNull('group_invoice_id')->get();
        if ($invoices->isEmpty()) {
            return null;
        }

        return DB::transaction(function () use ($group, $invoices): int {
            $next = DB::connection((string) config('tenancy.database.central_connection'))->table('group_invoices')->count() + 1;
            $id = (int) DB::connection((string) config('tenancy.database.central_connection'))->table('group_invoices')->insertGetId(['provider_group_id' => $group->id, 'number' => 'CFG-'.now()->format('Y').'-'.str_pad((string) $next, 5, '0', STR_PAD_LEFT),
                'total_cents' => (int) $invoices->sum('total_cents'), 'status' => 'open', 'created_at' => now(), 'updated_at' => now()]);
            SubscriptionInvoice::query()->whereIn('id', $invoices->pluck('id'))->update(['group_invoice_id' => $id]);

            return $id;
        });
    }

    /**
     * Paying the combined invoice settles every practice invoice in it.
     */
    public function settleCombined(int $groupInvoiceId, string $method, string $reference): void
    {
        $gi = DB::connection((string) config('tenancy.database.central_connection'))->table('group_invoices')->where('id', $groupInvoiceId)->first();
        if ($gi === null || $gi->status === 'paid') {
            throw ValidationException::withMessages(['invoice' => 'This group invoice is already settled.']);
        }
        DB::transaction(function () use ($groupInvoiceId, $method, $reference): void {
            foreach (SubscriptionInvoice::query()->where('group_invoice_id', $groupInvoiceId)->where('status', '!=', 'paid')->get() as $invoice) {
                app(SettleSubscriptionInvoice::class)->settle($invoice, $method, $reference);
            }
            DB::connection((string) config('tenancy.database.central_connection'))->table('group_invoices')->where('id', $groupInvoiceId)->update(['status' => 'paid', 'paid_at' => now(), 'reference' => $reference, 'updated_at' => now()]);
        });
    }
}
