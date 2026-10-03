<?php

declare(strict_types=1);

namespace App\Domains\Hub\Actions;

use App\Domains\Pharmacy\Models\StockItem;
use App\Domains\Platform\Enums\ProviderStatus;
use App\Domains\Platform\Enums\ProviderType;
use App\Domains\Platform\Models\Provider;
use App\Domains\Platform\Models\Setting;
use Illuminate\Support\Facades\DB;

/**
 * Network pharmacies that opt in publish what they have in stock and their
 * price (no patient data). Patients and doctors compare pharmacies for a
 * script: which have every item and an estimated total. Medicine prices are
 * regulated in South Africa, so differences are mostly dispensing fees —
 * totals are estimates to confirm at the pharmacy.
 */
class PharmacyComparison
{
    public function publish(Provider $pharmacy): int
    {
        return $pharmacy->run(function () use ($pharmacy): int {
            DB::connection('hub')->table('hub_pharmacy_stock')->where('tenant_id', $pharmacy->id)->delete();
            if (! (bool) Setting::get('pharmacy', 'publish_stock', false)) {
                return 0;
            }
            $rows = StockItem::query()->get()->map(fn (StockItem $s) => ['tenant_id' => $pharmacy->id, 'nappi_code' => $s->nappi_code, 'quantity' => $s->onHand(), 'price_cents' => $s->unit_price_cents, 'published_at' => now()])
                ->filter(fn (array $r) => $r['quantity'] > 0)->values()->all();
            DB::connection('hub')->table('hub_pharmacy_stock')->insert($rows);

            return count($rows);
        });
    }

    /**
     * @param  list<array{nappi_code: string, quantity: int, description: string}>  $items
     * @return list<array{id: string, name: string, publishes: bool, has_all: bool, available: int, of: int, estimate_cents: int|null}>
     */
    public function compare(array $items): array
    {
        $codes = array_column($items, 'nappi_code');
        $stock = DB::connection('hub')->table('hub_pharmacy_stock')->whereIn('nappi_code', $codes)->get()->groupBy('tenant_id');
        $publishers = DB::connection('hub')->table('hub_pharmacy_stock')->distinct()->pluck('tenant_id')->all();

        $rows = Provider::query()->where('type', ProviderType::Pharmacy->value)->whereIn('status', [ProviderStatus::Trial->value, ProviderStatus::Active->value])->orderBy('name')->get()
            ->map(function (Provider $p) use ($items, $stock, $publishers): array {
                $mine = collect($stock->get($p->id, []))->keyBy('nappi_code');
                $available = 0;
                $total = 0;
                foreach ($items as $i) {
                    $row = $mine->get($i['nappi_code']);
                    if ($row !== null && (int) $row->quantity >= $i['quantity']) {
                        $available++;
                        $total += (int) $row->price_cents * $i['quantity'];
                    }
                }
                $publishes = in_array($p->id, $publishers, true);

                return ['id' => $p->id, 'name' => $p->name, 'publishes' => $publishes, 'has_all' => $publishes && $available === count($items),
                    'available' => $available, 'of' => count($items), 'estimate_cents' => $publishes && $available === count($items) ? $total : null];
            })->all();

        // Pharmacies with every item first, cheapest estimate next, then by name.
        usort($rows, fn (array $a, array $b): int => [! $a['has_all'], $a['estimate_cents'] ?? PHP_INT_MAX, $a['name']] <=> [! $b['has_all'], $b['estimate_cents'] ?? PHP_INT_MAX, $b['name']]);

        return array_values($rows);
    }
}
