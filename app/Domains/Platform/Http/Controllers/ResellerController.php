<?php

declare(strict_types=1);

namespace App\Domains\Platform\Http\Controllers;

use App\Domains\Platform\Models\Provider;
use App\Domains\Platform\Resellers\ResellerProgramme;
use App\Http\Controllers\Controller;
use App\Models\User;
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
            'resellers' => DB::connection((string) config('tenancy.database.central_connection'))->table('resellers')->orderBy('name')->get()->map(fn ($r) => [
                'id' => $r->id, 'name' => $r->name, 'email' => $r->email, 'code' => $r->code, 'percent' => (float) $r->commission_percent, 'months' => (int) $r->commission_months,
                'active' => (bool) $r->active, 'linked' => $r->user_id !== null,
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

        // Nothing pending for that month: say so instead of reporting success (beta #120).
        return $n === 0
            ? back()->with('error', 'Nothing to pay: no unpaid commission for this reseller in that month.')
            : back()->with('success', "{$n} commission line(s) marked paid.");
    }

    public function portal(Request $request, ResellerProgramme $programme): Response
    {
        $user = $request->user();
        $reseller = $user instanceof User ? $programme->linkByEmail($user) : null;
        abort_if($reseller === null, 403, 'You are not a Dr Business Flow reseller.');

        return Inertia::render('Reseller/Portal', [
            'reseller' => ['name' => $reseller->name, 'code' => $reseller->code, 'percent' => (float) $reseller->commission_percent, 'months' => (int) $reseller->commission_months,
                'link' => rtrim((string) config('app.url'), '/').'/?ref='.$reseller->code],
            'referrals' => $programme->statement((int) $reseller->id)['referrals'],
            'periods' => $programme->statement((int) $reseller->id)['months'],
            // White-label partners: their brands, practices, sign-ups this month and AI use.
            'brands' => DB::connection((string) config('tenancy.database.central_connection'))->table('brands')->where('reseller_id', $reseller->id)->orderBy('name')->get()->map(function ($b) {
                $practices = Provider::query()->where('brand_id', $b->id)->orderBy('name')->get();

                return ['name' => $b->name, 'signupLink' => rtrim((string) config('app.url'), '/').'/?brand='.$b->slug,
                    'signupsThisMonth' => $practices->filter(fn ($p) => $p->getAttribute('created_at') !== null && $p->getAttribute('created_at') >= now()->startOfMonth())->count(),
                    'aiMinutesThisMonth' => (int) DB::connection((string) config('tenancy.database.central_connection'))->table('ai_usage')->whereIn('tenant_id', $practices->pluck('id'))->where('period', now()->format('Y-m'))->sum(DB::raw('minutes_included_used + minutes_wallet')),
                    'practices' => $practices->map(fn ($p) => ['name' => (string) $p->getAttribute('name'), 'status' => (string) ($p->getAttribute('status') instanceof \BackedEnum ? $p->getAttribute('status')->value : $p->getAttribute('status')),
                        'since' => substr((string) $p->getAttribute('created_at'), 0, 10)])->values()];
            })->values(),
        ]);
    }

    /** Change commission for new referrals, or switch a reseller on/off (beta #122). */
    public function update(Request $request, int $reseller, ResellerProgramme $programme): RedirectResponse
    {
        $data = $request->validate(['commission_percent' => ['required', 'numeric', 'min:0', 'max:50'], 'commission_months' => ['required', 'integer', 'min:1', 'max:60'], 'active' => ['required', 'boolean']]);
        $programme->update($reseller, (float) $data['commission_percent'], (int) $data['commission_months'], (bool) $data['active']);

        return back()->with('success', 'Reseller saved. New commission terms apply to practices referred from now on.');
    }

    /** Invite the reseller to their portal (beta #124). */
    public function invite(int $reseller, ResellerProgramme $programme): RedirectResponse
    {
        $programme->invite($reseller);

        return back()->with('success', 'Invitation emailed. The reseller is linked to their sign-in.');
    }
}
