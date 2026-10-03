<?php

declare(strict_types=1);

namespace App\Domains\Lab\Http\Controllers;

use App\Domains\Identity\Enums\Permission;
use App\Domains\Lab\Actions\LabCatalog;
use App\Domains\Lab\Models\CatalogRange;
use App\Domains\Lab\Models\CatalogTest;
use App\Domains\Lab\Models\LabTest;
use App\Domains\Lab\Models\Panel;
use App\Domains\Lab\Models\RangeChange;
use App\Domains\Platform\Models\Setting;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The lab's own test templates: copy from the master list, edit, panels, and
 * reference-range changes that a second person approves.
 */
class LabCatalogController extends Controller
{
    public function index(): Response
    {
        $this->authorize(Permission::LAB_MANAGE);

        return Inertia::render('Lab/Catalogue', [
            'tests' => CatalogTest::query()->with('ranges')->orderBy('name')->get()->map(fn (CatalogTest $t) => [
                ...$t->only(['id', 'code', 'name', 'loinc', 'sample_type', 'result_type', 'choices', 'unit', 'decimals', 'plausible_min', 'plausible_max', 'turnaround_hours', 'home_collection', 'active', 'version']),
                'price' => $t->price_cents / 100,
                'ranges' => $t->ranges->map(fn (CatalogRange $r) => $r->only(['sex', 'age_min_months', 'age_max_months', 'ref_low', 'ref_high', 'critical_low', 'critical_high']))->values(),
            ])->values(),
            'pending' => RangeChange::query()->whereNull('approved_at')->get()->map(fn (RangeChange $c) => [
                'id' => $c->id, 'test' => CatalogTest::query()->whereKey($c->lab_catalog_test_id)->value('name'), 'ranges' => $c->ranges, 'proposedBy' => $c->proposed_by,
            ])->values(),
            'master' => LabTest::query()->whereNotIn('code', CatalogTest::query()->pluck('code'))->orderBy('name')->get(['code', 'name']),
            'panels' => Panel::query()->orderBy('name')->get(['id', 'code', 'name', 'price_cents', 'test_codes', 'active']),
            'settings' => [
                'home_collection' => (bool) Setting::get('lab', 'home_collection', false), 'home_fee' => ((int) Setting::get('lab', 'home_fee_cents', 15000)) / 100,
                'service_area' => (string) Setting::get('lab', 'service_area', ''), 'request_after_hours' => (int) Setting::get('lab', 'request_after_hours', 24),
                'auto_release_hours' => (int) Setting::get('lab', 'auto_release_hours', 48), 'escalate_hours' => (int) Setting::get('lab', 'escalate_hours', 48),
                'auto_release_abnormal' => (bool) Setting::get('lab', 'auto_release_abnormal', false),
            ],
        ]);
    }

    public function import(Request $request, LabCatalog $catalog): RedirectResponse
    {
        $this->authorize(Permission::LAB_MANAGE);
        $data = $request->validate(['codes' => ['required', 'array', 'min:1'], 'codes.*' => ['string', 'max:16']]);
        /** @var list<string> $codes */
        $codes = array_values($data['codes']);
        $count = count($catalog->importFromMaster($codes));

        return back()->with('success', "{$count} test(s) copied from the master list. Confirm their reference ranges before go-live.");
    }

    public function update(Request $request, CatalogTest $test): RedirectResponse
    {
        $this->authorize(Permission::LAB_MANAGE);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'], 'sample_type' => ['required', 'string', 'max:40'], 'unit' => ['nullable', 'string', 'max:24'],
            'result_type' => ['required', Rule::in(['numeric', 'choice', 'text'])], 'choices' => ['nullable', 'array'], 'choices.*' => ['string', 'max:60'],
            'decimals' => ['required', 'integer', 'between:0,3'], 'plausible_min' => ['nullable', 'numeric'], 'plausible_max' => ['nullable', 'numeric'],
            'price' => ['required', 'numeric', 'min:0'], 'turnaround_hours' => ['required', 'integer', 'between:1,720'], 'home_collection' => ['required', 'boolean'], 'active' => ['required', 'boolean'],
        ]);
        $test->fill(collect($data)->except('price')->all() + ['price_cents' => (int) round(((float) $data['price']) * 100)])->save();

        return back()->with('success', "{$test->name} saved.");
    }

    public function proposeRanges(Request $request, CatalogTest $test, LabCatalog $catalog): RedirectResponse
    {
        $this->authorize(Permission::LAB_MANAGE);
        $data = $request->validate([
            'ranges' => ['required', 'array', 'min:1', 'max:12'], 'ranges.*.sex' => ['nullable', Rule::in(['female', 'male'])],
            'ranges.*.age_min_months' => ['required', 'integer', 'min:0'], 'ranges.*.age_max_months' => ['required', 'integer', 'gte:ranges.*.age_min_months'],
            'ranges.*.ref_low' => ['nullable', 'numeric'], 'ranges.*.ref_high' => ['nullable', 'numeric'], 'ranges.*.critical_low' => ['nullable', 'numeric'], 'ranges.*.critical_high' => ['nullable', 'numeric'],
        ]);
        /** @var list<array{sex: ?string, age_min_months: int, age_max_months: int, ref_low: ?float, ref_high: ?float, critical_low: ?float, critical_high: ?float}> $ranges */
        $ranges = array_values($data['ranges']);
        $catalog->proposeRanges($test, $ranges, $this->user($request)->id);

        return back()->with('success', 'Range change saved. A second person must approve it before it is used.');
    }

    public function approve(Request $request, RangeChange $change, LabCatalog $catalog): RedirectResponse
    {
        $this->authorize(Permission::LAB_MANAGE);
        $catalog->approve($change, $this->user($request)->id);

        return back()->with('success', 'Range change approved. It applies to new results only.');
    }

    public function savePanel(Request $request): RedirectResponse
    {
        $this->authorize(Permission::LAB_MANAGE);
        $data = $request->validate([
            'code' => ['required', 'string', 'max:16'], 'name' => ['required', 'string', 'max:120'], 'price' => ['required', 'numeric', 'min:0'],
            'test_codes' => ['required', 'array', 'min:2'], 'test_codes.*' => [Rule::in(CatalogTest::query()->pluck('code')->all())],
        ]);
        Panel::query()->updateOrCreate(['code' => strtoupper($data['code'])], ['name' => $data['name'], 'price_cents' => (int) round(((float) $data['price']) * 100), 'test_codes' => array_values($data['test_codes']), 'active' => true]);

        return back()->with('success', 'Panel saved.');
    }

    private function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
