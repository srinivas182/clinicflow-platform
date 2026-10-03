<?php

declare(strict_types=1);

namespace App\Domains\Branches\Support;

use App\Domains\Billing\Models\Invoice;
use App\Domains\Branches\Models\Branch;
use App\Domains\Finance\Models\CashUp;
use App\Domains\Pharmacy\Models\StockBatch;
use App\Domains\Scheduling\Models\Appointment;
use App\Domains\Scheduling\Models\RosterSession;
use App\Domains\Visits\Models\Visit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Which branch the signed-in user is working at (chosen with the branch
 * switcher, default the main branch). New visits, appointments, invoices,
 * cash-ups, roster sessions and received stock are tagged with it. Branch
 * filtering only applies once a practice has more than one active branch.
 */
final class BranchContext
{
    public const TAGGED = [Visit::class, Appointment::class, Invoice::class, CashUp::class, StockBatch::class, RosterSession::class];

    public static function current(): ?int
    {
        if (tenant() === null) {
            return null;
        }
        $chosen = request()->hasSession() ? request()->session()->get('branch_id') : null;
        if (is_numeric($chosen) && DB::table('branches')->where('id', (int) $chosen)->where('active', true)->exists()) {
            return (int) $chosen;
        }

        return self::main();
    }

    public static function main(): ?int
    {
        $id = DB::table('branches')->where('is_main', true)->value('id');

        return $id === null ? null : (int) $id;
    }

    /**
     * The branch to filter lists and stock by, or null when the practice has one branch.
     */
    public static function filterId(): ?int
    {
        return tenant() !== null && DB::table('branches')->where('active', true)->count() > 1 ? self::current() : null;
    }

    public static function register(): void
    {
        foreach (self::TAGGED as $model) {
            $model::creating(function (Model $m): void {
                if ($m->getAttribute('branch_id') === null && tenant() !== null) {
                    $m->setAttribute('branch_id', self::current());
                }
            });
        }
    }

    /**
     * @return list<array{id: int, name: string}>
     */
    public static function options(): array
    {
        return array_values(Branch::query()->where('active', true)->orderByDesc('is_main')->orderBy('name')->get(['id', 'name'])
            ->map(fn (Branch $b) => ['id' => $b->id, 'name' => $b->name])->all());
    }
}
