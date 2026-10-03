<?php

declare(strict_types=1);

namespace App\Domains\Lab\Http\Controllers;

use App\Domains\Hub\Actions\NetworkLabs;
use App\Domains\Hub\Models\HubLabOrder;
use App\Domains\Identity\Enums\Permission;
use App\Domains\Identity\Models\Staff;
use App\Domains\Lab\Actions\LabCatalog;
use App\Domains\Lab\Actions\LabWorkflow;
use App\Domains\Lab\Models\CatalogTest;
use App\Domains\Lab\Models\LabOrder;
use App\Domains\Lab\Models\LabResult;
use App\Domains\Lab\Support\Classifier;
use App\Domains\Patients\Models\Patient;
use App\Domains\Platform\Enums\ProviderStatus;
use App\Domains\Platform\Enums\ProviderType;
use App\Domains\Platform\Models\Provider;
use App\Domains\Platform\Models\Setting;
use App\Domains\Visits\Models\Visit;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Lab worklist (technicians), network requests (labs), ordering (doctors) and
 * the doctor's results inbox (including results of doctors they cover for).
 */
class LabController extends Controller
{
    public function order(Request $request, Visit $visit, LabWorkflow $lab): RedirectResponse
    {
        $this->authorize(Permission::CONSULTS_WRITE);
        $data = $request->validate(['tests' => ['required', 'array', 'min:1'], 'tests.*' => ['string', 'max:16']]);
        /** @var list<string> $tests */
        $tests = array_values($data['tests']);
        $lab->order($visit, $this->staff($request), $tests);

        return back()->with('success', 'Lab tests ordered from your in-house lab.');
    }

    public function networkOrder(Request $request, Visit $visit, NetworkLabs $labs): RedirectResponse
    {
        $this->authorize(Permission::CONSULTS_WRITE);
        $data = $request->validate([
            'lab_id' => ['required', 'string'], 'tests' => ['required', 'array', 'min:1'], 'tests.*' => ['string', 'max:16'],
            'home' => ['nullable', 'array'], 'home.address' => ['required_with:home', 'string', 'max:255'], 'home.date' => ['required_with:home', 'date', 'after_or_equal:today'], 'home.window' => ['required_with:home', 'string', 'max:40'],
        ]);
        /** @var list<string> $tests */
        $tests = array_values($data['tests']);
        /** @var array{address: string, date: string, window: string}|null $home */
        $home = $data['home'] ?? null;
        $provider = tenant();
        abort_unless($provider instanceof Provider, 404);
        $labs->send($visit, $this->staff($request), $provider, $data['lab_id'], $tests, $home);

        return back()->with('success', 'Lab request sent.');
    }

    public function labs(Request $request): JsonResponse
    {
        $this->authorize(Permission::CONSULTS_WRITE);
        $q = trim($request->string('q')->toString());

        return response()->json(Provider::query()->where('type', ProviderType::Lab->value)->whereIn('status', [ProviderStatus::Trial->value, ProviderStatus::Active->value])
            ->when($q !== '', fn ($w) => $w->where('name', 'like', "%{$q}%"))->orderBy('name')->limit(20)->get(['id', 'name']));
    }

    public function menu(string $lab, NetworkLabs $labs): JsonResponse
    {
        $this->authorize(Permission::CONSULTS_WRITE);

        return response()->json($labs->menu($lab));
    }

    public function worklist(): Response
    {
        $this->authorize(Permission::LAB_PROCESS);
        $provider = tenant();

        return Inertia::render('Lab/Worklist', [
            'orders' => LabOrder::query()->with(['patient', 'results'])->whereIn('status', ['ordered', 'collected', 'resulted'])->oldest()->get()
                ->map(fn (LabOrder $o) => $this->row($o, true))->values(),
            'requests' => $provider instanceof Provider ? HubLabOrder::query()->where('lab_tenant_id', $provider->id)->where('status', 'sent')->oldest()->get()
                ->map(fn (HubLabOrder $h) => ['id' => $h->id, 'patient' => $h->payload['patient']['first_names'].' '.$h->payload['patient']['surname'], 'practice' => $h->payload['practice'],
                    'doctor' => $h->payload['doctor']['name'], 'tests' => $h->payload['tests'], 'icd10' => $h->payload['icd10'], 'home' => $h->payload['home_collection']])->values() : [],
            'collectors' => Staff::query()->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function requestAction(Request $request, HubLabOrder $hub, string $action, NetworkLabs $labs): RedirectResponse
    {
        $this->authorize(Permission::LAB_PROCESS);
        $provider = tenant();
        abort_unless($provider instanceof Provider, 404);
        $action === 'accept' ? $labs->accept($hub, $provider) : $labs->reject($hub, $provider, $request->string('reason')->toString());

        return back()->with('success', $action === 'accept' ? 'Request accepted and invoiced.' : 'Request rejected.');
    }

    public function selfOrder(Request $request, LabWorkflow $lab): RedirectResponse
    {
        $this->authorize(Permission::LAB_PROCESS);
        $data = $request->validate(['patient_id' => ['required', 'string'], 'tests' => ['required', 'array', 'min:1'], 'tests.*' => ['string', 'max:16']]);
        /** @var list<string> $tests */
        $tests = array_values($data['tests']);
        $lab->orderSelf(Patient::query()->findOrFail((string) $data['patient_id']), $tests, $this->staff($request));

        return back()->with('success', 'Patient-requested tests added. Results go straight to the patient after verification.');
    }

    public function inbox(Request $request): Response
    {
        $this->authorize(Permission::CONSULTS_WRITE);
        $me = $this->staff($request);
        $covering = Staff::query()->where('covering_staff_id', $me->id)->whereDate('away_until', '>=', today())->pluck('id')->all();

        return Inertia::render('Lab/Inbox', [
            'orders' => LabOrder::query()->with(['patient', 'results'])->whereIn('ordering_staff_id', [$me->id, ...$covering])
                ->whereIn('status', ['verified', 'discuss'])
                ->orderByRaw('has_critical desc, patient_requested_at is null, escalated_at is null, verified_at')->get()
                ->map(fn (LabOrder $o) => $this->row($o, false) + ['covering' => $o->ordering_staff_id !== $me->id])->values(),
            'away' => ['until' => $me->getAttribute('away_until'), 'covering' => $me->getAttribute('covering_staff_id')],
            'doctors' => Staff::query()->whereKeyNot($me->id)->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function setCover(Request $request): RedirectResponse
    {
        $this->authorize(Permission::CONSULTS_WRITE);
        $data = $request->validate(['covering_staff_id' => ['nullable', 'integer'], 'away_until' => ['nullable', 'date', 'after_or_equal:today']]);
        $this->staff($request)->forceFill(['covering_staff_id' => $data['covering_staff_id'] ?? null, 'away_until' => $data['away_until'] ?? null])->save();

        return back()->with('success', 'Your results inbox cover is saved.');
    }

    public function step(Request $request, LabOrder $order, string $step, LabWorkflow $lab): RedirectResponse
    {
        $staff = $this->staff($request);
        in_array($step, ['collect', 'results', 'verify', 'assign'], true) ? $this->authorize(Permission::LAB_PROCESS) : $this->authorize(Permission::CONSULTS_WRITE);
        if (in_array($step, ['acknowledge', 'review', 'release', 'discuss'], true)) {
            $covers = Staff::query()->whereKey($order->ordering_staff_id)->where('covering_staff_id', $staff->id)->whereDate('away_until', '>=', today())->exists();
            abort_unless($order->ordering_staff_id === $staff->id || $covers, 403, 'Only the ordering doctor or their cover can act on these results.');
        }

        match ($step) {
            'collect' => $lab->collect($order, $staff),
            'assign' => $order->forceFill(['collector_staff_id' => $request->integer('collector_staff_id') ?: null])->save(),
            'results' => $lab->enterResults($order, $this->values($request), $staff, $this->flags($request), $this->storeReport($request, $order)),
            'verify' => $lab->verify($order, $staff),
            'acknowledge' => $lab->acknowledgeCritical($order, $staff, $request->string('action')->toString()),
            'review' => $lab->review($order, $staff, $request->string('comment')->toString() ?: null),
            'release' => $lab->release($order->reviewed_at === null ? $lab->review($order, $staff, null) : $order, $staff, $request->string('note')->toString() ?: null),
            'discuss' => $lab->discuss($order, $staff, $request->string('note')->toString()),
            default => abort(404),
        };

        return back()->with('success', 'Saved.');
    }

    public function settings(Request $request): RedirectResponse
    {
        $this->authorize(Permission::LAB_MANAGE);
        $data = $request->validate([
            'home_collection' => ['required', 'boolean'], 'home_fee' => ['required', 'numeric', 'min:0'], 'service_area' => ['nullable', 'string', 'max:500'],
            'request_after_hours' => ['required', 'integer', 'between:1,168'], 'auto_release_hours' => ['required', 'integer', 'between:12,336'],
            'escalate_hours' => ['required', 'integer', 'between:12,336'], 'auto_release_abnormal' => ['required', 'boolean'],
        ]);
        foreach ($data as $key => $value) {
            Setting::put('lab', $key === 'home_fee' ? 'home_fee_cents' : $key, $key === 'home_fee' ? (int) round(((float) $value) * 100) : $value);
        }

        return back()->with('success', 'Lab settings saved.');
    }

    /**
     * @return array<string, mixed>
     */
    private function row(LabOrder $o, bool $forLab): array
    {
        $patient = $o->patient;
        $catalog = app(LabCatalog::class);

        return [
            'id' => $o->id, 'patient' => $patient->fullName(), 'status' => $o->status, 'barcode' => $o->sample_barcode, 'source' => $o->getAttribute('source'),
            'classification' => $o->getAttribute('classification'), 'critical' => $o->has_critical, 'acknowledged' => $o->critical_acknowledged_at !== null,
            'reviewed' => $o->reviewed_at !== null, 'requested' => $o->getAttribute('patient_requested_at') !== null, 'escalated' => $o->getAttribute('escalated_at') !== null,
            'waitingHours' => $o->verified_at === null ? null : (int) $o->verified_at->diffInHours(now()), 'home' => $o->getAttribute('home_collection'),
            'hasReport' => $o->getAttribute('report_path') !== null, 'note' => $o->getAttribute('doctor_note'),
            'results' => $o->results->map(function (LabResult $r) use ($forLab, $catalog, $patient): array {
                $base = $r->only(['test_code', 'name', 'unit', 'reference', 'value', 'flag', 'result_text']);
                if ($forLab) {
                    $test = $catalog->resolve($r->test_code);
                    $range = $test instanceof CatalogTest ? Classifier::rangeFor($test, $patient) : null;
                    $base += [
                        'type' => $test?->result_type ?? 'numeric', 'choices' => $test?->choices ?? [], 'templateUnit' => $test?->unit,
                        'range' => $range?->only(['ref_low', 'ref_high', 'critical_low', 'critical_high']), 'rangeLabel' => Classifier::label($range),
                    ];
                }

                return $base;
            })->values(),
        ];
    }

    /**
     * @return array<string, float|int|string>
     */
    private function values(Request $request): array
    {
        /** @var array<string, float|int|string> $values */
        $values = array_filter((array) $request->input('values', []), fn ($v) => $v !== null && $v !== '');

        return $values;
    }

    /**
     * @return array<string, string>
     */
    private function flags(Request $request): array
    {
        /** @var array<string, string> $flags */
        $flags = array_filter((array) $request->input('flags', []), fn ($v) => is_string($v) && $v !== '');

        return $flags;
    }

    private function storeReport(Request $request, LabOrder $order): ?string
    {
        if (! $request->hasFile('report')) {
            return null;
        }
        $request->validate(['report' => ['file', 'mimetypes:application/pdf', 'max:10240']]);
        $file = $request->file('report');
        abort_unless($file instanceof UploadedFile, 422);

        return $file->storeAs('lab-reports', $order->id.'.pdf', 'local') ?: null;
    }

    private function staff(Request $request): Staff
    {
        /** @var User $user */
        $user = $request->user();

        return Staff::query()->findOrFail($user->id);
    }
}
