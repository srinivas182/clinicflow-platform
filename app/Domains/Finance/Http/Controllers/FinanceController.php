<?php

declare(strict_types=1);

namespace App\Domains\Finance\Http\Controllers;

use App\Domains\Claims\Support\ClaimAgeing;
use App\Domains\Finance\Actions\CloseCashUp;
use App\Domains\Finance\Models\CashUp;
use App\Domains\Finance\Support\RevenueReport;
use App\Domains\Identity\Enums\Permission;
use App\Domains\Identity\Models\Staff;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Owner dashboard and end-of-day cash-up.
 */
class FinanceController extends Controller
{
    public function dashboard(): Response
    {
        $this->authorize(Permission::FINANCE_VIEW);
        $monthStart = now()->startOfMonth();

        return Inertia::render('Finance/Dashboard', [
            'takings' => array_map(fn (int $c) => $c / 100, RevenueReport::takingsToday()),
            'byDoctor' => array_map(fn (array $r) => array_map(fn ($v) => is_int($v) ? $v / 100 : $v, $r), RevenueReport::byDoctor($monthStart, now())),
            'bySource' => DB::table('invoice_lines')->where('created_at', '>=', $monthStart)->selectRaw('kind, SUM(total_cents) as cents')->groupBy('kind')->pluck('cents', 'kind')
                ->map(fn ($c) => ((int) $c) / 100),
            'claimsAgeing' => array_map(fn (int $c) => $c / 100, ClaimAgeing::buckets()),
            'cashUps' => CashUp::query()->latest('closed_at')->limit(10)->get()->map(fn (CashUp $c) => [
                'staff' => Staff::query()->whereKey($c->staff_id)->value('name'), 'day' => $c->day->toDateString(),
                'difference' => $c->difference_cents / 100, 'reason' => $c->reason,
            ])->values(),
            'messagesThisMonth' => (int) DB::table('message_log')->where('sent_at', '>=', $monthStart)->sum('units'),
        ]);
    }

    public function cashUp(Request $request, CloseCashUp $action): Response
    {
        $this->authorize(Permission::BILLING_COLLECT);
        $staffId = $this->user($request)->id;

        return Inertia::render('Finance/CashUp', [
            'expected' => array_map(fn (int $c) => $c / 100, $action->expected($staffId)),
            'closed' => CashUp::query()->where('staff_id', $staffId)->whereDate('day', today())->exists(),
        ]);
    }

    public function closeCashUp(Request $request, CloseCashUp $action): RedirectResponse
    {
        $this->authorize(Permission::BILLING_COLLECT);
        $data = $request->validate(['counted' => ['required', 'numeric', 'min:0'], 'reason' => ['nullable', 'string', 'max:255']]);
        $cashUp = $action->handle($this->user($request)->id, (int) round(((float) $data['counted']) * 100), $data['reason'] ?? null);

        return back()->with('success', $cashUp->difference_cents === 0 ? 'Drawer balanced and closed.' : 'Drawer closed with a difference of R'.number_format($cashUp->difference_cents / 100, 2, '.', ' ').'.');
    }

    private function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
