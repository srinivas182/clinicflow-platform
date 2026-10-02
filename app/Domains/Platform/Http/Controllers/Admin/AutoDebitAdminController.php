<?php

declare(strict_types=1);

namespace App\Domains\Platform\Http\Controllers\Admin;

use App\Domains\Billing\Models\BillingMandate;
use App\Domains\Billing\Models\DebitAttempt;
use App\Http\Controllers\Controller;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Super admin: which providers pay automatically, and recent debit attempts.
 * Shows card brand and last four digits only.
 */
class AutoDebitAdminController extends Controller
{
    public function index(): Response
    {
        $mandates = [];
        foreach (BillingMandate::query()->with('provider')->latest('id')->limit(200)->get() as $m) {
            if ($m instanceof BillingMandate) {
                $mandates[] = [
                    'provider' => $m->provider->name,
                    'gateway' => $m->gateway->label(),
                    'mode' => $m->mode->value,
                    'card' => $m->label(),
                    'status' => $m->status,
                    'failures' => $m->failure_count,
                    'lastCharged' => $m->last_charged_at?->toDateString(),
                    'since' => $m->consented_at->toDateString(),
                ];
            }
        }

        return Inertia::render('Admin/AutoDebits', [
            'mandates' => $mandates,
            'failedRecently' => DebitAttempt::query()->where('outcome', 'failed')->where('attempted_at', '>', now()->subDays(14))->count(),
        ]);
    }
}
