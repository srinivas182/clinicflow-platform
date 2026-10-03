<?php

declare(strict_types=1);

namespace App\Domains\Portal\Http\Controllers;

use App\Domains\Lab\Actions\LabReleaseRules;
use App\Domains\Lab\Models\LabOrder;
use App\Domains\Lab\Models\LabResult;
use App\Domains\Lab\Support\LabReportPdf;
use App\Domains\Platform\Models\Provider;
use App\Domains\Portal\Actions\PortalSignIn;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Patient results: status only until the doctor releases (or the normal-result
 * auto-release); "discuss in person" shows the note without values; released
 * results show values and downloads. Every view and download is audited.
 */
class PortalResultsController extends Controller
{
    public function index(Request $request, PortalSignIn $signIn): Response
    {
        $ids = $signIn->profiles($this->cell($request))->pluck('id');
        $after = LabReleaseRules::hours('request_after_hours');

        return Inertia::render('Portal/Results', [
            'providerName' => $this->providerName(),
            'orders' => LabOrder::query()->with(['results', 'patient'])->whereIn('patient_id', $ids)->latest()->limit(50)->get()->map(function (LabOrder $o) use ($after): array {
                $released = $o->status === 'released';
                activity('portal')->performedOn($o)->withProperties(['released' => $released])->log('Patient viewed lab results status');

                return [
                    'id' => $o->id, 'patient' => $o->patient->fullName(), 'date' => $o->created_at?->format('j M Y'),
                    'status' => match ($o->status) {
                        'sent', 'accepted', 'ordered' => 'Requested', 'collected', 'resulted' => 'Sample collected',
                        'verified' => 'Results received — waiting for your doctor to review', 'discuss' => 'Your doctor would like to discuss these results',
                        'released' => 'Results ready', 'rejected' => 'The lab could not do this test', default => $o->status,
                    },
                    'note' => in_array($o->status, ['discuss', 'released', 'rejected'], true) ? $o->getAttribute('doctor_note') : null,
                    'comment' => $released ? $o->doctor_comment : null,
                    'results' => $released ? $o->results->map(fn (LabResult $r) => ['name' => $r->name, 'value' => $r->getAttribute('result_text') ?? $r->value, 'unit' => $r->unit, 'reference' => $r->reference, 'flag' => $r->flag])->values() : [],
                    'canRequest' => $o->status === 'verified' && $o->getAttribute('patient_requested_at') === null && $o->verified_at !== null && $o->verified_at->lte(now()->subHours($after)),
                    'requested' => $o->getAttribute('patient_requested_at') !== null,
                    'downloads' => $released ? ['report' => true, 'labReport' => $o->getAttribute('report_path') !== null] : null,
                    'bookUrl' => $o->status === 'discuss' ? '/my' : null,
                ];
            })->values(),
        ]);
    }

    public function request(Request $request, LabOrder $order, PortalSignIn $signIn, LabReleaseRules $rules): RedirectResponse
    {
        $this->own($request, $order, $signIn);
        $rules->patientRequest($order);

        return back()->with('success', 'Your doctor has been asked to look at these results.');
    }

    public function download(Request $request, LabOrder $order, string $kind, PortalSignIn $signIn): HttpResponse
    {
        $this->own($request, $order, $signIn);
        abort_unless($order->status === 'released', 403, 'These results have not been released yet.');
        activity('portal')->performedOn($order)->withProperties(['kind' => $kind])->log('Patient downloaded lab results');

        if ($kind === 'lab') {
            $path = (string) $order->getAttribute('report_path');
            abort_if($path === '' || ! Storage::disk('local')->exists($path), 404);

            return response((string) Storage::disk('local')->get($path), 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'attachment; filename="lab-report.pdf"']);
        }

        return response(LabReportPdf::render($order, $this->providerName()), 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'attachment; filename="results.pdf"']);
    }

    private function own(Request $request, LabOrder $order, PortalSignIn $signIn): void
    {
        abort_unless($signIn->profiles($this->cell($request))->contains('id', $order->patient_id), 403);
    }

    private function cell(Request $request): string
    {
        return (string) $request->session()->get('portal_cell');
    }

    private function providerName(): string
    {
        $provider = tenant();

        return $provider instanceof Provider ? $provider->name : 'Clinic Flow';
    }
}
