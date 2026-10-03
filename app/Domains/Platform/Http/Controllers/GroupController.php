<?php

declare(strict_types=1);

namespace App\Domains\Platform\Http\Controllers;

use App\Domains\Platform\Actions\ProviderGroups;
use App\Domains\Platform\Models\Provider;
use App\Domains\Platform\Models\ProviderGroup;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Group dashboards for group admins, and group set-up and combined billing for the super admin.
 */
class GroupController extends Controller
{
    public function show(Request $request, ProviderGroup $group, ProviderGroups $groups): Response
    {
        abort_unless($group->isAdmin((int) $request->user()?->getAuthIdentifier()) || (bool) $request->user()?->getAttribute('is_platform_admin'), 403);
        $from = $request->string('from')->toString() ?: now()->startOfMonth()->toDateString();
        $to = $request->string('to')->toString() ?: now()->toDateString();

        return Inertia::render('Groups/Show', [
            'group' => ['id' => $group->id, 'name' => $group->name, 'billing' => $group->billing],
            'period' => ['from' => $from, 'to' => $to],
            'practices' => array_map(fn (array $r) => [...$r, 'takings' => $r['takings'] / 100, 'owed' => $r['owed'] / 100], $groups->dashboard($group, $from, $to)),
        ]);
    }

    public function mine(Request $request): Response
    {
        $ids = DB::table('provider_group_admins')->where('user_id', $request->user()?->getAuthIdentifier())->pluck('provider_group_id');

        return Inertia::render('Groups/Index', ['groups' => ProviderGroup::query()->whereIn('id', $ids)->orderBy('name')->get(['id', 'name'])]);
    }

    public function admin(): Response
    {
        return Inertia::render('Admin/Groups', [
            'groups' => ProviderGroup::query()->orderBy('name')->get()->map(fn (ProviderGroup $g) => [
                'id' => $g->id, 'name' => $g->name, 'billing' => $g->billing, 'members' => $g->members()->map(fn (Provider $p) => ['id' => $p->id, 'name' => $p->name])->values(),
                'invoices' => DB::table('group_invoices')->where('provider_group_id', $g->id)->latest('id')->limit(6)->get(['id', 'number', 'total_cents', 'status']),
            ])->values(),
            'providers' => Provider::query()->whereNotIn('id', DB::table('provider_group_members')->pluck('tenant_id'))->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function adminAction(Request $request, string $action, ProviderGroups $groups): RedirectResponse
    {
        $group = $action === 'create' ? null : ProviderGroup::query()->findOrFail($request->integer('group_id'));
        match ($action) {
            'create' => ProviderGroup::create($request->validate(['name' => ['required', 'string', 'max:120'], 'billing' => ['required', Rule::in(['separate', 'combined'])]])),
            'member' => $groups->addMember($group ?? abort(404), Provider::query()->findOrFail($request->string('tenant_id')->toString())),
            'admin' => $groups->addAdmin($group ?? abort(404), $request->string('email')->toString()),
            'billing' => $group?->forceFill(['billing' => $request->string('billing')->toString() === 'combined' ? 'combined' : 'separate'])->save(),
            'invoice' => $groups->issueCombinedInvoice($group ?? abort(404)),
            'settle' => $groups->settleCombined($request->integer('group_invoice_id'), 'eft', $request->string('reference')->toString() ?: 'EFT'),
            default => abort(404),
        };

        return back()->with('success', 'Saved.');
    }
}
