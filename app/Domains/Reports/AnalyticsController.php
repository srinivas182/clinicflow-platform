<?php

declare(strict_types=1);

namespace App\Domains\Reports;

use App\Domains\Identity\Enums\Permission;
use App\Domains\Platform\Models\Provider;
use App\Domains\Platform\Models\Subscription;
use App\Http\Controllers\Controller;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Practice analytics dashboard (Standard and Pro): key figures with the previous period
 * for comparison, a six-month money trend, and doctor and branch comparisons.
 */
class AnalyticsController extends Controller
{
    public function index(Request $request, Analytics $analytics): Response
    {
        $this->authorize(Permission::FINANCE_VIEW);
        $provider = tenant();
        $package = $provider instanceof Provider ? Subscription::query()->where('tenant_id', $provider->id)->latest('id')->first()?->package : null;
        if ($package === null || ! $package->hasFeature('reports_advanced')) {
            return Inertia::render('Analytics/Dashboard', ['available' => false]);
        }
        [$from, $to] = $this->period($request);
        $days = (int) $from->diffInDays($to) + 1;
        $branch = $request->filled('branch_id') ? $request->integer('branch_id') : null;

        return Inertia::render('Analytics/Dashboard', [
            'available' => true,
            'period' => ['key' => $request->string('period')->toString() ?: 'this_month', 'from' => $from->toDateString(), 'to' => $to->toDateString(), 'branchId' => $branch],
            'current' => $analytics->summary($from, $to, $branch),
            'previous' => $analytics->summary($from->subDays($days), $from->subDay(), $branch),
            'trend' => $analytics->trend($to),
            'doctors' => $analytics->byDoctor($from, $to),
            'branches' => $analytics->byBranch($from, $to),
            'branchOptions' => DB::table('branches')->where('active', true)->orderBy('name')->get(['id', 'name']),
        ]);
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function period(Request $request): array
    {
        $now = CarbonImmutable::now();

        return match ($request->string('period')->toString()) {
            'last_month' => [$now->subMonthNoOverflow()->startOfMonth(), $now->subMonthNoOverflow()->endOfMonth()],
            'last_90' => [$now->subDays(89)->startOfDay(), $now],
            'custom' => (function () use ($request, $now): array {
                try {
                    $f = CarbonImmutable::parse($request->string('from')->toString());
                    $t = CarbonImmutable::parse($request->string('to')->toString());
                } catch (\Throwable) {
                    return [$now->startOfMonth(), $now];
                }

                return $t->lt($f) || $f->diffInDays($t) > 731 ? [$now->startOfMonth(), $now] : [$f, $t];
            })(),
            default => [$now->startOfMonth(), $now],
        };
    }
}
