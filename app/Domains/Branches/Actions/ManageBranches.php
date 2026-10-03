<?php

declare(strict_types=1);

namespace App\Domains\Branches\Actions;

use App\Domains\Branches\Models\Branch;
use App\Domains\Pharmacy\Models\RegisterEntry;
use App\Domains\Pharmacy\Models\StockBatch;
use App\Domains\Pharmacy\Models\StockItem;
use App\Domains\Platform\Models\Provider;
use App\Domains\Platform\Models\Subscription;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Adds branches within the package limit (plus extra-branch add-ons), assigns
 * staff and moves stock between branches.
 */
class ManageBranches
{
    /**
     * Branches allowed, or null for unlimited.
     */
    public function allowed(Provider $provider): ?int
    {
        $subscription = Subscription::query()->where('tenant_id', $provider->id)->latest('id')->first();
        $limit = $subscription?->package->limit('branches');
        if ($limit === null || $limit === 0) {
            return null;
        }

        return $limit + (int) ($subscription->getAttribute('extra_branches') ?? 0);
    }

    public function add(Provider $provider, string $name, ?string $address, ?string $phone): Branch
    {
        $allowed = $this->allowed($provider);
        if ($allowed !== null && Branch::query()->where('active', true)->count() >= $allowed) {
            throw ValidationException::withMessages(['name' => "Your package allows {$allowed} branch(es). Add an extra branch to your subscription first."]);
        }

        return Branch::create(['name' => trim($name), 'address' => $address, 'phone' => $phone, 'active' => true]);
    }

    /**
     * @param  list<int>  $staffIds
     */
    public function assignStaff(Branch $branch, array $staffIds): void
    {
        DB::table('branch_staff')->where('branch_id', $branch->id)->delete();
        foreach (array_unique($staffIds) as $id) {
            DB::table('branch_staff')->insert(['branch_id' => $branch->id, 'staff_id' => $id]);
        }
    }

    /**
     * Moves stock (earliest expiry first) keeping batch numbers and expiry dates; S5/S6 moves are recorded.
     */
    public function transfer(StockItem $item, int $fromBranch, int $toBranch, int $quantity, int $by): void
    {
        if ($fromBranch === $toBranch || $quantity < 1 || $item->onHand($fromBranch) < $quantity) {
            throw ValidationException::withMessages(['quantity' => 'Choose two different branches and no more than the stock available.']);
        }

        DB::transaction(function () use ($item, $fromBranch, $toBranch, $quantity, $by): void {
            $remaining = $quantity;
            foreach ($item->batches()->where('branch_id', $fromBranch)->whereDate('expiry_date', '>=', today())->where('quantity', '>', 0)->orderBy('expiry_date')->lockForUpdate()->get() as $batch) {
                /** @var StockBatch $batch */
                $take = min($remaining, $batch->quantity);
                $batch->decrement('quantity', $take);
                $target = $item->batches()->where('branch_id', $toBranch)->where('batch_number', $batch->batch_number)->first()
                    ?? $item->batches()->create(['batch_number' => $batch->batch_number, 'expiry_date' => $batch->expiry_date, 'quantity' => 0, 'branch_id' => $toBranch]);
                $target->increment('quantity', $take);
                $remaining -= $take;
                if ($remaining === 0) {
                    break;
                }
            }
            DB::table('stock_transfers')->insert(['stock_item_id' => $item->id, 'from_branch_id' => $fromBranch, 'to_branch_id' => $toBranch, 'quantity' => $quantity, 'by' => $by, 'created_at' => now()]);
            RegisterEntry::record($item, 'transferred', 0, ['pharmacist_staff_id' => $by, 'reference' => "{$quantity} moved from branch {$fromBranch} to branch {$toBranch}"]);
        });
    }
}
