<?php

declare(strict_types=1);

namespace App\Domains\Platform\Http\Controllers;

use App\Domains\Platform\Models\Provider;
use App\Domains\Platform\Resellers\ResellerProgramme;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Super admin: resellers, commission and EFT payouts. Resellers: their own statement.
 */
class ResellerController extends Controller
{
    public function admin(ResellerProgramme $programme): Response
    {
        return Inertia::render('Admin/Resellers', [
            'resellers' => DB::table('resellers')->orderBy('name')->get()->map(fn ($r) => [
                'id' => $r->id, 'name' => $r->name, 'email' => $r->email, 'code' => $r->code, 'percent' => (float) $r->commission_percent, 'months' => (int) $r->commission_months,
                'link' => rtrim((string) config('app.url'), '/').'/?ref='.$r->code,
                'referrals' => $programme->statement((int) $r->id)['referrals'], 'periods' => $programme->statement((int) $r->id)['months'],
            ])->values(),
        ]);
    }

    public function store(Request $request, ResellerProgramme $programme): RedirectResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:120'], 'email' => ['required', 'email'], 'phone' => ['nullable', 'string', 'max:20'],
            'commission_percent' => ['required', 'numeric'], 'commission_months' => ['required', 'integer']]);
        $programme->create($data['name'], $data['email'], $data['phone'] ?? null, (float) $data['commission_percent'], (int) $data['commission_months']);

        return back()->with('success', 'Reseller added.');
    }

    public function pay(Request $request, int $reseller, ResellerProgramme $programme): RedirectResponse
    {
        $n = $programme->markPaid($reseller, $request->string('period')->toString(), $request->string('reference')->toString());

        return back()->with('success', "{$n} commission line(s) marked paid.");
    }

    public function portal(Request $request, ResellerProgramme $programme): Response
    {
        $reseller = DB::table('resellers')->where('user_id', $request->user()?->getAuthIdentifier())->first();
        abort_if($reseller === null, 403, 'You are not a Clinic Flow reseller.');

        return Inertia::render('Reseller/Portal', [
            'reseller' => ['name' => $reseller->name, 'code' => $reseller->code, 'percent' => (float) $reseller->commission_percent, 'months' => (int) $reseller->commission_months,
                'link' => rtrim((string) config('app.url'), '/').'/?ref='.$reseller->code],
            'referrals' => $programme->statement((int) $reseller->id)['referrals'],
            'periods' => $programme->statement((int) $reseller->id)['months'],
            // White-label partners: their brands, practices, sign-ups this month and AI use.
            'brands' => DB::table('brands')->where('reseller_id', $reseller->id)->orderBy('name')->get()->map(function ($b) {
                $practices = Provider::query()->where('brand_id', $b->id)->orderBy('name')->get();

                return ['name' => $b->name, 'signupLink' => rtrim((string) config('app.url'), '/').'/?brand='.$b->slug,
                    'signupsThisMonth' => $practices->filter(fn ($p) => $p->getAttribute('created_at') !== null && $p->getAttribute('created_at') >= now()->startOfMonth())->count(),
                    'aiMinutesThisMonth' => (int) DB::table('ai_usage')->whereIn('tenant_id', $practices->pluck('id'))->where('period', now()->format('Y-m'))->sum(DB::raw('minutes_included_used + minutes_wallet')),
                    'practices' => $practices->map(fn ($p) => ['name' => (string) $p->getAttribute('name'), 'status' => (string) ($p->getAttribute('status') instanceof \BackedEnum ? $p->getAttribute('status')->value : $p->getAttribute('status')),
                        'since' => substr((string) $p->getAttribute('created_at'), 0, 10)])->values()];
            })->values(),
        ]);
    }
}
