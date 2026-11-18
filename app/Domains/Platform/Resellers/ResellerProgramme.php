<?php

declare(strict_types=1);

namespace App\Domains\Platform\Resellers;

use App\Domains\Billing\Models\SubscriptionInvoice;
use App\Domains\Platform\Models\Provider;
use App\Domains\Platform\Notifications\PracticeNotice;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
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

    /** Change commission (new referrals only) or switch a reseller off (their link stops working; earned commission stays). */
    public function update(int $id, float $percent, int $months, bool $active): void
    {
        if ($percent < 0 || $percent > 50 || $months < 1 || $months > 60) {
            throw ValidationException::withMessages(['commission_percent' => 'Commission must be 0–50% for 1–60 months.']);
        }
        abort_unless($this->db()->table('resellers')->where('id', $id)->exists(), 404);
        $this->db()->table('resellers')->where('id', $id)->update(['commission_percent' => $percent, 'commission_months' => $months, 'active' => $active, 'updated_at' => now()]);
    }

    /**
     * Links the reseller to a sign-in (beta #124): an existing account with the same email, or a new
     * one, with an email to set a password and open the reseller portal.
     */
    public function invite(int $id): void
    {
        $reseller = $this->db()->table('resellers')->where('id', $id)->first();
        abort_if($reseller === null, 404);
        $user = User::query()->where('email', $reseller->email)->first();
        $new = ! $user instanceof User;
        if ($new) {
            $user = new User;
            $user->forceFill(['name' => $reseller->name, 'email' => $reseller->email, 'password' => Hash::make(Str::random(40))])->save();
        }
        $this->db()->table('resellers')->where('id', $id)->update(['user_id' => $user->id, 'updated_at' => now()]);
        $base = rtrim((string) config('app.url'), '/');
        $url = $new ? $base.'/reset-password/'.Password::broker()->createToken($user).'?email='.urlencode($user->email) : $base.'/reseller';
        $user->notify(new PracticeNotice('Your Dr Business Flow reseller portal', [
            "You are set up as a Dr Business Flow reseller ({$reseller->name}).",
            $new ? 'Set a password to open your portal: your referral link, referred practices and commission.' : 'Sign in with this email to open your portal: your referral link, referred practices and commission.',
        ], $new ? 'Set your password' : 'Open the portal', $url, 'reseller_invite'));
    }

    /** A reseller who registers or signs in with the reseller's email is linked automatically. */
    public function linkByEmail(User $user): ?\stdClass
    {
        $this->db()->table('resellers')->whereNull('user_id')->where('email', $user->email)->update(['user_id' => $user->id, 'updated_at' => now()]);
        $row = $this->db()->table('resellers')->where('user_id', $user->id)->first();

        return $row instanceof \stdClass ? $row : null;
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
            // The referral keeps the terms in force today; later changes apply to new referrals only (beta #122).
            $terms = $this->db()->table('resellers')->where('id', $reseller)->first(['commission_percent', 'commission_months']);
            $this->db()->table('reseller_referrals')->insertOrIgnore(['reseller_id' => $reseller, 'tenant_id' => $provider->id, 'referred_at' => now(),
                'commission_percent' => $terms?->commission_percent, 'commission_months' => $terms?->commission_months]);
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
        $months = (int) ($ref->commission_months ?? $reseller?->commission_months);
        $percent = (float) ($ref->commission_percent ?? $reseller?->commission_percent);
        if ($reseller === null || CarbonImmutable::parse((string) $ref->referred_at)->addMonths($months)->isPast()) {
            return;
        }
        $base = max(0, $invoice->total_cents - $invoice->vat_cents);
        $this->db()->table('reseller_commissions')->insertOrIgnore([
            'reseller_id' => $reseller->id, 'tenant_id' => $invoice->tenant_id, 'subscription_invoice_id' => $invoice->id, 'base_cents' => $base,
            'amount_cents' => (int) round($base * $percent / 100), 'period' => now()->format('Y-m'),
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
