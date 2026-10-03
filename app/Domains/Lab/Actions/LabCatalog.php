<?php

declare(strict_types=1);

namespace App\Domains\Lab\Actions;

use App\Domains\Lab\Models\CatalogTest;
use App\Domains\Lab\Models\LabTest;
use App\Domains\Lab\Models\RangeChange;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * This lab's catalogue: copy tests from the master list, edit them, and change
 * reference ranges only with a second person's approval. Results keep the
 * range that was used when they were verified.
 */
class LabCatalog
{
    /**
     * @param  list<string>  $codes
     * @return list<CatalogTest>
     */
    public function importFromMaster(array $codes): array
    {
        $imported = [];
        foreach (LabTest::query()->whereIn('code', array_map('strtoupper', $codes))->get() as $master) {
            /** @var LabTest $master */
            $existing = CatalogTest::query()->where('code', $master->code)->first();
            if ($existing instanceof CatalogTest) {
                $imported[] = $existing;

                continue;
            }
            $test = DB::transaction(function () use ($master): CatalogTest {
                $test = CatalogTest::create([
                    'code' => $master->code, 'name' => $master->name, 'loinc' => $master->getAttribute('loinc'),
                    'sample_type' => (string) ($master->getAttribute('sample_type') ?? 'Serum'), 'result_type' => (string) ($master->getAttribute('result_type') ?? 'numeric'),
                    'choices' => is_string($master->getAttribute('choices')) ? json_decode((string) $master->getAttribute('choices'), true) : $master->getAttribute('choices'),
                    'unit' => $master->unit, 'decimals' => (int) ($master->getAttribute('decimals') ?? 1),
                    'plausible_min' => $master->getAttribute('plausible_min'), 'plausible_max' => $master->getAttribute('plausible_max'),
                    'price_cents' => $master->price_cents, 'turnaround_hours' => (int) ($master->getAttribute('turnaround_hours') ?? 24),
                ]);
                $ranges = DB::connection((string) config('tenancy.database.central_connection'))->table('lab_test_ranges')->where('test_code', $master->code)->get();
                foreach ($ranges as $r) {
                    $test->ranges()->create([
                        'sex' => $r->sex, 'age_min_months' => $r->age_min_months, 'age_max_months' => $r->age_max_months,
                        'ref_low' => $r->ref_low, 'ref_high' => $r->ref_high, 'critical_low' => $r->critical_low, 'critical_high' => $r->critical_high,
                    ]);
                }

                return $test;
            });
            $imported[] = $test;
        }

        return $imported;
    }

    /**
     * This lab's test for a code; copied from the master list on first use.
     */
    public function resolve(string $code): ?CatalogTest
    {
        $code = strtoupper($code);

        return CatalogTest::query()->where('code', $code)->where('active', true)->first() ?? ($this->importFromMaster([$code])[0] ?? null);
    }

    /**
     * Ranges change only after a second person approves.
     *
     * @param  list<array{sex: ?string, age_min_months: int, age_max_months: int, ref_low: ?float, ref_high: ?float, critical_low: ?float, critical_high: ?float}>  $ranges
     */
    public function proposeRanges(CatalogTest $test, array $ranges, int $proposedBy): RangeChange
    {
        if ($ranges === []) {
            throw ValidationException::withMessages(['ranges' => 'Add at least one reference range.']);
        }

        return RangeChange::create(['lab_catalog_test_id' => $test->id, 'ranges' => $ranges, 'proposed_by' => $proposedBy]);
    }

    public function approve(RangeChange $change, int $approvedBy): CatalogTest
    {
        if ($change->approved_at !== null) {
            throw ValidationException::withMessages(['change' => 'This change is already approved.']);
        }
        if ($change->proposed_by === $approvedBy) {
            throw ValidationException::withMessages(['change' => 'A different person must approve a range change.']);
        }

        return DB::transaction(function () use ($change, $approvedBy): CatalogTest {
            $test = CatalogTest::query()->findOrFail($change->lab_catalog_test_id);
            $test->ranges()->delete();
            foreach ($change->ranges as $r) {
                $test->ranges()->create($r);
            }
            $test->increment('version');
            $change->forceFill(['approved_by' => $approvedBy, 'approved_at' => now()])->save();
            activity('lab')->performedOn($test)->withProperties(['ranges' => $change->ranges, 'approved_by' => $approvedBy])->log('Reference ranges changed');

            return $test->refresh();
        });
    }
}
