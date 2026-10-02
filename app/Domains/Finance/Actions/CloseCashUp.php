<?php

declare(strict_types=1);

namespace App\Domains\Finance\Actions;

use App\Domains\Billing\Enums\PaymentStatus;
use App\Domains\Billing\Models\Payment;
use App\Domains\Billing\Models\Refund;
use App\Domains\Finance\Models\CashUp;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * End-of-day cash-up per cashier: expected takings by method from that
 * cashier's payments (less cash refunds), the counted cash, and a reason for
 * any difference. Closing locks the day's payments to the cash-up.
 */
class CloseCashUp
{
    /**
     * @return array<string, int>
     */
    public function expected(int $staffId): array
    {
        $payments = Payment::query()->where('received_by', $staffId)->where('status', PaymentStatus::Succeeded->value)
            ->whereNull('cash_up_id')->whereDate('created_at', today())->get();

        $expected = [];
        foreach ($payments as $p) {
            $expected[$p->method->value] = ($expected[$p->method->value] ?? 0) + $p->amount_cents;
        }

        $cashRefunds = (int) Refund::query()->where('issued_by', $staffId)->whereDate('created_at', today())
            ->whereIn('payment_id', Payment::query()->where('method', 'cash')->select('id'))->sum('amount_cents');
        $expected['cash'] = ($expected['cash'] ?? 0) - $cashRefunds;

        return $expected;
    }

    public function handle(int $staffId, int $countedCashCents, ?string $reason = null): CashUp
    {
        if (CashUp::query()->where('staff_id', $staffId)->whereDate('day', today())->exists()) {
            throw ValidationException::withMessages(['cash_up' => 'Your drawer is already closed for today.']);
        }

        $expected = $this->expected($staffId);
        $difference = $countedCashCents - $expected['cash'];
        if ($difference !== 0 && blank($reason)) {
            throw ValidationException::withMessages(['reason' => 'Explain the difference of R'.number_format($difference / 100, 2, '.', ' ').'.']);
        }

        return DB::transaction(function () use ($staffId, $countedCashCents, $reason, $expected, $difference): CashUp {
            $cashUp = CashUp::create([
                'staff_id' => $staffId, 'day' => today(), 'expected' => $expected, 'expected_cash_cents' => $expected['cash'],
                'counted_cash_cents' => $countedCashCents, 'difference_cents' => $difference, 'reason' => $reason, 'closed_at' => now(),
            ]);
            Payment::query()->where('received_by', $staffId)->where('status', PaymentStatus::Succeeded->value)
                ->whereNull('cash_up_id')->whereDate('created_at', today())->update(['cash_up_id' => $cashUp->id]);
            activity('finance')->performedOn($cashUp)->withProperties(['difference_cents' => $difference])->log('Cash-up closed');

            return $cashUp;
        });
    }
}
