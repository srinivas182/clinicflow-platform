<?php

declare(strict_types=1);

namespace App\Domains\Platform\Resellers;

use App\Domains\Billing\Models\SubscriptionInvoice;
use App\Domains\Platform\Models\Provider;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Resellers sign up providers with their link (?ref=CODE). For each referred
 * provider they earn a percentage of the subscription fee (excluding VAT) for a
 * number of months from the referral, paid by EFT monthly from a statement.
 */
class ResellerProgramme
{
    public const COOKIE = 'cf_ref';

    private function db(): ConnectionInterface
    {
        return DB::connection((string) config('tenancy.database.central_connection'));
    }

    public function create(string $name, string $email, ?string $phone, float $percent = 20, int $months = 12): int
    {
        if ($percent < 0 || $percent > 50 || $months < 1 || $months > 60) {
            throw ValidationException::withMessages(['commission_percent' => 'Commission must be 0–50% for 1–60 months.']);
        }
        $email = strtolower(trim($email));
        if ($this->db()->table('resellers')->where('email', $email)->exists()) {
            throw ValidationException::withMessages(['email' => 'A reseller with that email exists.']);
        }
        do {
            $code = strtoupper(Str::substr(preg_replace('/[^A-Za-z]/', '', $name) ?: 'CF', 0, 4)).random_int(100, 999);
        } while ($this->db()->table('resellers')->where('code', $code)->exists());

        return (int) $this->db()->table('resellers')->insertGetId([
            'name' => trim($name), 'email' => $email, 'phone' => $phone, 'code' => $code, 'commission_percent' => $percent, 'commission_months' => $months,
            'user_id' => User::query()->where('email', $email)->value('id'), 'active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function validCode(?string $code): ?int
    {
        if ($code === null || preg_match('/^[A-Z0-9]{3,20}$/', strtoupper($code)) !== 1) {
            return null;
        }
        $id = $this->db()->table('resellers')->where('code', strtoupper($code))->where('active', true)->value('id');

        return $id === null ? null : (int) $id;
    }

    /**
     * Records the referral when a referred provider signs up (first referral wins).
     */
    public function attach(Provider $provider, ?string $code): void
    {
        $reseller = $this->validCode($code);
        if ($reseller !== null) {
            $this->db()->table('reseller_referrals')->insertOrIgnore(['reseller_id' => $reseller, 'tenant_id' => $provider->id, 'referred_at' => now()]);
        }
    }

    /**
     * Commission for a paid subscription invoice, within the reseller's commission window.
     */
    public function recordCommission(SubscriptionInvoice $invoice): void
    {
        $ref = $this->db()->table('reseller_referrals')->where('tenant_id', $invoice->tenant_id)->first();
        if ($ref === null) {
            return;
        }
        $reseller = $this->db()->table('resellers')->where('id', $ref->reseller_id)->first();
        if ($reseller === null || CarbonImmutable::parse((string) $ref->referred_at)->addMonths((int) $reseller->commission_months)->isPast()) {
            return;
        }
        $base = max(0, $invoice->total_cents - $invoice->vat_cents);
        $this->db()->table('reseller_commissions')->insertOrIgnore([
            'reseller_id' => $reseller->id, 'tenant_id' => $invoice->tenant_id, 'subscription_invoice_id' => $invoice->id, 'base_cents' => $base,
            'amount_cents' => (int) round($base * (float) $reseller->commission_percent / 100), 'period' => now()->format('Y-m'),
            'status' => 'pending', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /**
     * Marks a reseller's pending commission for a month as paid by EFT.
     */
    public function markPaid(int $resellerId, string $period, string $reference): int
    {
        if (trim($reference) === '') {
            throw ValidationException::withMessages(['reference' => 'Enter the EFT reference.']);
        }

        return $this->db()->table('reseller_commissions')->where('reseller_id', $resellerId)->where('period', $period)->where('status', 'pending')
            ->update(['status' => 'paid', 'paid_at' => now(), 'payout_reference' => trim($reference), 'updated_at' => now()]);
    }

    /**
     * @return array{referrals: list<array{practice: string, referred: string}>, months: list<array{period: string, amount: int, paid: bool, reference: ?string}>}
     */
    public function statement(int $resellerId): array
    {
        $referrals = $this->db()->table('reseller_referrals')->where('reseller_id', $resellerId)->orderByDesc('referred_at')->get()
            ->map(fn ($r) => ['practice' => (string) Provider::query()->whereKey($r->tenant_id)->value('name'), 'referred' => substr((string) $r->referred_at, 0, 10)])->values()->all();
        $months = $this->db()->table('reseller_commissions')->where('reseller_id', $resellerId)->selectRaw("period, SUM(amount_cents) as amount, MIN(status = 'paid') as paid, MAX(payout_reference) as reference")
            ->groupBy('period')->orderByDesc('period')->get()
            ->map(fn ($m) => ['period' => (string) $m->period, 'amount' => (int) $m->amount, 'paid' => (bool) $m->paid, 'reference' => $m->reference === null ? null : (string) $m->reference])->values()->all();

        return ['referrals' => array_values($referrals), 'months' => array_values($months)];
    }
}
