<?php

declare(strict_types=1);

namespace App\Domains\Telemedicine\Support;

use App\Domains\Billing\Enums\PaymentStatus;
use App\Domains\Billing\Models\Payment;
use App\Domains\Telemedicine\Actions\OnlineBooking;

/**
 * Confirms a paid online consult (or applies a paid extension) as soon as the
 * payment on its invoice succeeds — pay link, card at the desk or cash.
 */
final class OnlineConsultHooks
{
    public static function register(): void
    {
        Payment::saved(function (Payment $payment): void {
            $succeeded = $payment->status === PaymentStatus::Succeeded && ($payment->wasRecentlyCreated || $payment->wasChanged('status'));
            if ($succeeded && tenant() !== null) {
                app(OnlineBooking::class)->paymentSucceeded($payment);
            }
        });
    }
}
