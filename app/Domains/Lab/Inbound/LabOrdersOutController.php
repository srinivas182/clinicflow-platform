<?php

declare(strict_types=1);

namespace App\Domains\Lab\Inbound;

use App\Domains\Identity\Models\Staff;
use App\Domains\Lab\Models\LabOrder;
use App\Domains\Patients\Models\Patient;
use App\Http\Controllers\Controller;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;

/**
 * A connected lab system collects the orders sent to it (FHIR or HL7 ORM^O01) and
 * confirms receipt. It only ever sees its own orders, with the demographics a lab
 * needs (no ID number) and test codes translated to its own codes where mapped.
 */
class LabOrdersOutController extends Controller
{
    public function __construct(private readonly LabConnections $connections) {}

    public function fhir(Request $request): JsonResponse
    {
        $key = $this->keyId($request);
        $entries = [];
        foreach ($this->pending($key) as $order) {
            $patient = Patient::query()->findOrFail($order->patient_id);
            $doctor = Staff::query()->find($order->ordering_staff_id);
            $entries[] = ['resource' => ['resourceType' => 'Patient', 'id' => $patient->id, 'name' => [['family' => $patient->surname, 'given' => array_values(array_filter(explode(' ', $patient->first_names)))]],
                'birthDate' => $patient->date_of_birth->toDateString(), 'gender' => ($patient->sex === null ? 'unknown' : $patient->sex->value)]];
            $entries[] = ['resource' => array_filter(['resourceType' => 'ServiceRequest', 'id' => $order->id, 'status' => 'active', 'intent' => 'order',
                'subject' => ['reference' => 'Patient/'.$patient->id], 'authoredOn' => $order->created_at?->toIso8601String(),
                'requester' => $doctor instanceof Staff ? ['display' => $doctor->name.($doctor->professional_number ? ' ('.$doctor->professional_number.')' : '')] : null,
                'specimen' => $order->sample_barcode ? [['identifier' => ['value' => $order->sample_barcode]]] : null,
                'code' => ['coding' => $order->results()->get()->map(fn ($r) => ['code' => $this->connections->toExternal($key, (string) $r->test_code), 'display' => (string) $r->name])->values()->all()],
            ], fn ($v) => $v !== null)];
        }

        return response()->json(['resourceType' => 'Bundle', 'type' => 'collection', 'total' => intdiv(count($entries), 2), 'entry' => $entries], 200, ['Content-Type' => 'application/fhir+json']);
    }

    public function hl7(Request $request): HttpResponse
    {
        $key = $this->keyId($request);
        $messages = [];
        foreach ($this->pending($key) as $i => $order) {
            $patient = Patient::query()->findOrFail($order->patient_id);
            $doctor = Staff::query()->find($order->ordering_staff_id);
            $seg = ['MSH|^~\&|CLINICFLOW|'.tenant('id').'|LIS||'.now()->format('YmdHis').'||ORM^O01|ORD'.$order->id.'|P|2.5',
                'PID|1||'.$patient->id.'||'.$patient->surname.'^'.$patient->first_names.'||'.$patient->date_of_birth->format('Ymd').'|'.strtoupper(substr(($patient->sex === null ? 'u' : $patient->sex->value), 0, 1)),
                'ORC|NW|'.$order->id.'|||||||'.($order->created_at?->format('YmdHis') ?? '').'|||'.($doctor instanceof Staff ? ($doctor->professional_number ?? '').'^'.$doctor->name : '')];
            foreach ($order->results()->get() as $n => $r) {
                $seg[] = 'OBR|'.($n + 1).'|'.$order->id.'|'.($order->sample_barcode ?? '').'|'.$this->connections->toExternal($key, (string) $r->test_code).'^'.$r->name;
            }
            $messages[] = implode("\r", $seg);
        }

        return response(implode("\r\n", $messages), 200, ['Content-Type' => 'x-application/hl7-v2+er7']);
    }

    public function received(Request $request, string $order): JsonResponse
    {
        $updated = LabOrder::query()->whereKey(strtolower($order))->where('external_key_id', $this->keyId($request))->whereNull('external_received_at')->update(['external_received_at' => now()]);
        abort_if($updated === 0, 404, 'No open order with that id for this lab system.');

        return response()->json(['received' => true]);
    }

    /**
     * @return Collection<int, LabOrder>
     */
    private function pending(int $key): Collection
    {
        return LabOrder::query()->where('external_key_id', $key)->whereNull('external_received_at')->whereIn('status', ['ordered', 'collected'])->orderBy('created_at')->limit(200)->get();
    }

    private function keyId(Request $request): int
    {
        return (int) $request->attributes->get('api_key_id');
    }
}
