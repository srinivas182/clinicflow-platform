<?php

declare(strict_types=1);

namespace App\Domains\Lab\Inbound;

use App\Domains\Identity\Enums\Permission;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Connected lab systems post results here (HL7 v2 or FHIR); staff handle the unmatched queue.
 */
class LabInboundController extends Controller
{
    public function hl7(Request $request, LabInbound $inbound): HttpResponse
    {
        $raw = (string) $request->getContent();
        $controlId = (string) (explode('|', strtok($raw, "\r\n") ?: '')[9] ?? '');
        try {
            $result = $inbound->receive('hl7', $raw, (int) $request->attributes->get('api_key_id'));
            [$code, $text] = ['AA', $result['status'] === 'applied' ? 'Results applied' : ($result['status'] === 'duplicate' ? 'Already received' : 'Received; held for review: '.$result['reason'])];
        } catch (ValidationException $e) {
            [$code, $text] = ['AE', (string) collect($e->errors())->flatten()->first()];
        }
        $ack = 'MSH|^~\&|CLINICFLOW|'.tenant('id').'|||'.now()->format('YmdHis').'||ACK^R01|ACK'.now()->format('YmdHisv').'|P|2.5'."\r"
            .'MSA|'.$code.'|'.$controlId.'|'.str_replace(['|', "\r", "\n"], ' ', mb_substr($text, 0, 200));

        return response($ack, $code === 'AA' ? 200 : 400, ['Content-Type' => 'x-application/hl7-v2+er7']);
    }

    public function fhir(Request $request, LabInbound $inbound): JsonResponse
    {
        try {
            $result = $inbound->receive('fhir', (string) $request->getContent(), (int) $request->attributes->get('api_key_id'));
        } catch (ValidationException $e) {
            return response()->json(['resourceType' => 'OperationOutcome', 'issue' => [['severity' => 'error', 'code' => 'invalid', 'diagnostics' => (string) collect($e->errors())->flatten()->first()]]], 400, ['Content-Type' => 'application/fhir+json']);
        }

        return response()->json(['resourceType' => 'OperationOutcome', 'issue' => [['severity' => $result['status'] === 'unmatched' ? 'warning' : 'information', 'code' => 'informational',
            'diagnostics' => $result['status'] === 'applied' ? 'Results applied' : ($result['status'] === 'duplicate' ? 'Already received' : 'Received; held for review: '.$result['reason'])]]],
            $result['status'] === 'duplicate' ? 200 : 201, ['Content-Type' => 'application/fhir+json']);
    }

    // ---------------- staff: unmatched results ----------------

    public function queue(LabInbound $inbound): Response
    {
        $this->authorize(Permission::LAB_MANAGE);

        return Inertia::render('Lab/Unmatched', [
            'messages' => DB::table('lab_inbound_messages')->leftJoin('api_keys', 'api_keys.id', '=', 'lab_inbound_messages.api_key_id')->where('lab_inbound_messages.status', 'unmatched')
                ->orderBy('lab_inbound_messages.id')->limit(100)->get(['lab_inbound_messages.id', 'lab_inbound_messages.format', 'lab_inbound_messages.reason', 'lab_inbound_messages.lab_order_id', 'lab_inbound_messages.created_at', 'api_keys.name as lab'])
                ->map(fn ($m) => ['id' => $m->id, 'lab' => $m->lab, 'format' => $m->format, 'reason' => $m->reason, 'orderId' => $m->lab_order_id, 'received' => $m->created_at] + $inbound->summary((int) $m->id))->values(),
            'openOrders' => DB::table('lab_orders')->join('patients', 'patients.id', '=', 'lab_orders.patient_id')->whereIn('lab_orders.status', ['ordered', 'collected'])
                ->orderByDesc('lab_orders.created_at')->limit(200)->get(['lab_orders.id', 'lab_orders.sample_barcode', 'lab_orders.created_at', 'patients.first_names', 'patients.surname', 'patients.date_of_birth'])
                ->map(fn ($o) => ['id' => $o->id, 'label' => "{$o->first_names} {$o->surname} · born ".substr((string) $o->date_of_birth, 0, 10).' · ordered '.substr((string) $o->created_at, 0, 10).($o->sample_barcode ? " · {$o->sample_barcode}" : '')])->values(),
        ]);
    }

    public function act(Request $request, int $message, string $action, LabInbound $inbound): RedirectResponse
    {
        $this->authorize(Permission::LAB_MANAGE);
        $by = (int) $request->user()?->getAuthIdentifier();
        if ($action === 'reject') {
            $inbound->reject($message, $request->string('reason')->toString(), $by);

            return back()->with('success', 'Result rejected.');
        }
        $result = $inbound->resolve($message, $request->string('order_id')->toString(), $by);

        return back()->with($result['status'] === 'applied' ? 'success' : 'error', $result['status'] === 'applied' ? 'Results applied to the order.' : 'Still held: '.$result['reason']);
    }
}
