<?php

declare(strict_types=1);

namespace App\Domains\Branches\Http\Controllers;

use App\Domains\Branches\Actions\ManageBranches;
use App\Domains\Branches\Models\Branch;
use App\Domains\Identity\Enums\Permission;
use App\Domains\Identity\Models\Staff;
use App\Domains\Pharmacy\Models\StockItem;
use App\Domains\Platform\Models\Provider;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Branches: list and add (within the package), assign staff, switch the branch
 * you are working at, and move stock between branches.
 */
class BranchController extends Controller
{
    public function index(ManageBranches $branches): Response
    {
        $this->authorize(Permission::SETTINGS_MANAGE);
        $all = Branch::query()->orderByDesc('is_main')->orderBy('name')->get();

        return Inertia::render('Settings/Branches', [
            'branches' => $all->map(fn (Branch $b) => [...$b->only(['id', 'name', 'address', 'phone', 'is_main', 'active']), 'staff' => DB::table('branch_staff')->where('branch_id', $b->id)->pluck('staff_id')])->values(),
            'allowed' => $branches->allowed($this->provider()),
            'extraPrice' => (int) config('clinicflow.branches.extra_monthly_cents', 49900) / 100,
            'staff' => Staff::query()->orderBy('name')->get(['id', 'name']),
            'stock' => StockItem::query()->orderBy('description')->get()->map(fn (StockItem $s) => ['id' => $s->id, 'name' => $s->description,
                'perBranch' => $all->mapWithKeys(fn (Branch $b) => [$b->id => $s->onHand($b->id)])])->values(),
        ]);
    }

    public function store(Request $request, ManageBranches $branches): RedirectResponse
    {
        $this->authorize(Permission::SETTINGS_MANAGE);
        $data = $request->validate(['name' => ['required', 'string', 'max:120'], 'address' => ['nullable', 'string', 'max:255'], 'phone' => ['nullable', 'string', 'max:20']]);
        $branches->add($this->provider(), $data['name'], $data['address'] ?? null, $data['phone'] ?? null);

        return back()->with('success', 'Branch added.');
    }

    public function staff(Request $request, Branch $branch, ManageBranches $branches): RedirectResponse
    {
        $this->authorize(Permission::SETTINGS_MANAGE);
        $branches->assignStaff($branch, array_values(array_map('intval', (array) $request->input('staff_ids', []))));

        return back()->with('success', 'Branch staff saved.');
    }

    public function switch(Request $request): RedirectResponse
    {
        $id = $request->integer('branch_id');
        abort_unless(Branch::query()->whereKey($id)->where('active', true)->exists(), 404);
        $request->session()->put('branch_id', $id);

        return back()->with('success', 'Working at '.Branch::query()->whereKey($id)->value('name').'.');
    }

    public function transfer(Request $request, ManageBranches $branches): RedirectResponse
    {
        $this->authorize(Permission::PHARMACY_DISPENSE);
        $data = $request->validate(['stock_item_id' => ['required', 'integer'], 'from' => ['required', 'integer'], 'to' => ['required', 'integer'], 'quantity' => ['required', 'integer', 'min:1']]);
        /** @var User $user */
        $user = $request->user();
        $branches->transfer(StockItem::query()->findOrFail((int) $data['stock_item_id']), (int) $data['from'], (int) $data['to'], (int) $data['quantity'], $user->id);

        return back()->with('success', 'Stock moved.');
    }

    private function provider(): Provider
    {
        $provider = tenant();
        abort_unless($provider instanceof Provider, 404);

        return $provider;
    }
}
