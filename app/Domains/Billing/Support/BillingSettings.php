<?php

declare(strict_types=1);

namespace App\Domains\Billing\Support;

use App\Domains\Billing\Enums\PaymentTiming;
use App\Domains\Billing\Enums\RefundRule;
use App\Domains\Platform\Models\Setting;

/**
 * The provider's billing rules (read inside the provider's tenancy context).
 */
final class BillingSettings
{
    public const DEFAULT_CONSULT_FEE_CENTS = 52000;

    public static function paymentTiming(): PaymentTiming
    {
        return PaymentTiming::tryFrom((string) Setting::get('billing', 'payment_timing', 'check_in')) ?? PaymentTiming::AtCheckIn;
    }

    public static function refundRule(): RefundRule
    {
        return RefundRule::tryFrom((string) Setting::get('billing', 'refund_rule', 'refund')) ?? RefundRule::Refund;
    }

    public static function consultFeeCents(): int
    {
        $value = Setting::get('billing', 'consult_fee_cents', self::DEFAULT_CONSULT_FEE_CENTS);

        return is_numeric($value) ? (int) $value : self::DEFAULT_CONSULT_FEE_CENTS;
    }

    public static function consultCode(): string
    {
        return (string) Setting::get('billing', 'consult_code', '0190');
    }
}
