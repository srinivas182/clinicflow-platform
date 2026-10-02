<?php

declare(strict_types=1);

namespace App\Domains\Pharmacy\Actions;

use App\Domains\Billing\Models\Invoice;
use App\Domains\Pharmacy\Models\RegisterEntry;
use App\Domains\Pharmacy\Models\StockBatch;
use App\Domains\Pharmacy\Models\StockItem;
use App\Domains\Platform\Models\Setting;
use App\Domains\Visits\Actions\TransitionVisit;
use App\Domains\Visits\Enums\VisitStage;
use App\Domains\Visits\Models\Visit;
use Illuminate\Support\Facades\DB;

/**
 * Medicine not collected after N days (clinic setting, default 7) goes back
 * to stock, the invoice is flagged for a credit, and the visit is closed.
 */
class ReturnUncollected
{
    public function __construct(private readonly TransitionVisit $transition) {}

    public function handle(): int
    {
        $days = (int) (Setting::get('pharmacy', 'uncollected_days', 7) ?? 7);
        $count = 0;

        Visit::query()->where('stage', VisitStage::Dispatch->value)->whereNull('collected_at')
            ->where('ready_for_collection_at', '<', now()->subDays($days))->get()
            ->each(function (Visit $visit) use (&$count): void {
                DB::transaction(function () use ($visit): void {
                    $rows = DB::table('dispensings')->where('visit_id', $visit->id)->whereNull('returned_at')->get();
                    foreach ($rows as $row) {
                        $batch = StockBatch::query()->find($row->stock_batch_id);
                        if ($batch instanceof StockBatch) {
                            $batch->increment('quantity', (int) $row->quantity);
                            $item = StockItem::query()->find($batch->stock_item_id);
                            if ($item instanceof StockItem) {
                                RegisterEntry::record($item, 'returned', (int) $row->quantity, ['prescription_id' => $row->prescription_id, 'reference' => 'Not collected']);
                            }
                        }
                    }
                    DB::table('dispensings')->where('visit_id', $visit->id)->whereNull('returned_at')->update(['returned_at' => now()]);

                    Invoice::query()->where('visit_id', $visit->id)->update(['needs_review' => true, 'review_note' => 'Medicine not collected — returned to stock; credit the medicine lines.']);
                    $this->transition->handle($visit, VisitStage::Done);
                    activity('pharmacy')->performedOn($visit)->log('Uncollected medicine returned to stock');
                });
                $count++;
            });

        return $count;
    }
}
