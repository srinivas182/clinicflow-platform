<?php

declare(strict_types=1);

namespace App\Domains\Lab\Http\Controllers;

use App\Domains\Identity\Enums\Permission;
use App\Domains\Identity\Models\Staff;
use App\Domains\Lab\Actions\LabWorkflow;
use App\Domains\Lab\Models\LabOrder;
use App\Domains\Lab\Models\LabResult;
use App\Domains\Visits\Models\Visit;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Lab worklist (lab technicians) and the doctor's results inbox.
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

        return back()->with('success', 'Lab tests ordered.');
    }

    public function worklist(): Response
    {
        $this->authorize(Permission::LAB_PROCESS);

        return Inertia::render('Lab/Worklist', [
            'orders' => LabOrder::query()->with(['patient', 'results'])->whereIn('status', ['ordered', 'collected', 'resulted'])->oldest()->get()
                ->map(fn (LabOrder $o) => $this->row($o))->values(),
        ]);
    }

    public function inbox(Request $request): Response
    {
        $this->authorize(Permission::CONSULTS_WRITE);

        return Inertia::render('Lab/Inbox', [
            'orders' => LabOrder::query()->with(['patient', 'results'])->where('ordering_staff_id', $this->staff($request)->id)
                ->where('status', 'verified')->orderByDesc('has_critical')->oldest('verified_at')->get()
                ->map(fn (LabOrder $o) => $this->row($o))->values(),
        ]);
    }

    public function step(Request $request, LabOrder $order, string $step, LabWorkflow $lab): RedirectResponse
    {
        $staff = $this->staff($request);
        match ($step) {
            'collect' => $this->authorize(Permission::LAB_PROCESS),
            'results', 'verify' => $this->authorize(Permission::LAB_PROCESS),
            default => $this->authorize(Permission::CONSULTS_WRITE),
        };
        if (in_array($step, ['acknowledge', 'review', 'release'], true)) {
            abort_unless($order->ordering_staff_id === $staff->id, 403, 'Only the ordering doctor can act on these results.');
        }

        /** @var array<string, float|int|string> $values */
        $values = (array) $request->input('values', []);

        match ($step) {
            'collect' => $lab->collect($order, $staff),
            'results' => $lab->enterResults($order, $values, $staff),
            'verify' => $lab->verify($order, $staff),
            'acknowledge' => $lab->acknowledgeCritical($order, $staff, $request->string('action')->toString()),
            'review' => $lab->review($order, $staff, $request->string('comment')->toString() ?: null),
            'release' => $lab->release($order, $staff),
            default => abort(404),
        };

        return back()->with('success', 'Saved.');
    }

    /**
     * @return array<string, mixed>
     */
    private function row(LabOrder $o): array
    {
        return [
            'id' => $o->id, 'patient' => $o->patient->fullName(), 'status' => $o->status, 'barcode' => $o->sample_barcode,
            'critical' => $o->has_critical, 'acknowledged' => $o->critical_acknowledged_at !== null, 'reviewed' => $o->reviewed_at !== null,
            'results' => $o->results->map(fn (LabResult $r) => $r->only(['test_code', 'name', 'unit', 'reference', 'value', 'flag']))->values(),
        ];
    }

    private function staff(Request $request): Staff
    {
        /** @var User $user */
        $user = $request->user();

        return Staff::query()->findOrFail($user->id);
    }
}
