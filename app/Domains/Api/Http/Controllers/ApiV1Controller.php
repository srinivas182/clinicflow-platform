<?php

declare(strict_types=1);

namespace App\Domains\Api\Http\Controllers;

use App\Domains\Billing\Models\Invoice;
use App\Domains\Identity\Models\Staff;
use App\Domains\Patients\Models\Patient;
use App\Domains\Patients\Support\SaIdNumber;
use App\Domains\Scheduling\Actions\AvailableSlots;
use App\Domains\Scheduling\Models\Appointment;
use App\Http\Controllers\Controller;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Public REST API v1 (read). Responses never contain clinical information
 * (no appointment reasons, notes, results or prescriptions).
 */
class ApiV1Controller extends Controller
{
    public function availability(Request $request, AvailableSlots $slots): JsonResponse
    {
        $data = $request->validate(['date' => ['required', 'date_format:Y-m-d'], 'staff_id' => ['nullable', 'integer']]);
        $day = CarbonImmutable::parse($data['date']);
        $doctors = Staff::query()->whereIn('role', ['doctor', 'locum_doctor', 'owner'])
            ->when(isset($data['staff_id']), fn ($q) => $q->whereKey((int) $data['staff_id']))->orderBy('name')->get();

        return response()->json(['data' => $doctors->map(fn (Staff $s) => [
            'staff_id' => $s->id, 'name' => $s->name,
            'slots' => array_map(fn (array $slot) => ['starts_at' => $slot['starts_at']->toIso8601String(), 'ends_at' => $slot['ends_at']->toIso8601String()], $slots->handle($s->id, $day)),
        ])->filter(fn (array $d) => $d['slots'] !== [])->values()]);
    }

    public function appointments(Request $request): JsonResponse
    {
        $data = $request->validate(['from' => ['required', 'date_format:Y-m-d'], 'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from']]);
        $from = CarbonImmutable::parse($data['from'])->startOfDay();
        $to = CarbonImmutable::parse($data['to'])->endOfDay();
        abort_if($from->diffInDays($to) > 31, 422, 'Ask for at most 31 days at a time.');

        return response()->json(['data' => Appointment::query()->with('staff')->whereBetween('starts_at', [$from, $to])->orderBy('starts_at')->limit(2000)->get()
            ->map(fn (Appointment $a) => ['id' => $a->id, 'patient_id' => $a->patient_id, 'staff_id' => $a->staff_id, 'staff_name' => $a->staff->name,
                'starts_at' => $a->starts_at->toIso8601String(), 'ends_at' => $a->ends_at->toIso8601String(), 'type' => $a->consult_type->value, 'status' => $a->status->value])->values()]);
    }

    public function patient(Request $request): JsonResponse
    {
        $data = $request->validate(['cell' => ['required_without:id_number', 'nullable', 'regex:/^0\d{9}$/'], 'id_number' => ['required_without:cell', 'nullable', 'digits:13']]);
        // Exact match only — the API cannot list or search patients broadly. ID numbers are stored
        // encrypted, so they are matched through their lookup hash.
        $sa = isset($data['id_number']) ? SaIdNumber::tryParse((string) $data['id_number']) : null;
        if (isset($data['id_number']) && $sa === null) {
            return response()->json(['data' => []]);
        }
        $patients = Patient::query()->when(isset($data['cell']), fn ($q) => $q->where('cell', $data['cell']))
            ->when($sa !== null, fn ($q) => $q->where('id_number_hash', $sa?->lookupHash()))->limit(10)->get();

        return response()->json(['data' => $patients->map(fn (Patient $p) => [
            'id' => $p->id, 'first_names' => $p->first_names, 'surname' => $p->surname, 'date_of_birth' => $p->date_of_birth->toDateString(),
            'sex' => $p->sex?->value, 'cell' => $p->cell, 'email' => $p->email,
        ])->values()]);
    }

    public function invoices(Request $request): JsonResponse
    {
        $data = $request->validate(['from' => ['required', 'date_format:Y-m-d'], 'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'], 'status' => ['nullable', 'string', 'max:20']]);
        $from = CarbonImmutable::parse($data['from'])->startOfDay();
        $to = CarbonImmutable::parse($data['to'])->endOfDay();
        abort_if($from->diffInDays($to) > 92, 422, 'Ask for at most 92 days at a time.');

        return response()->json(['data' => Invoice::query()->whereBetween('created_at', [$from, $to])->when(isset($data['status']), fn ($q) => $q->where('status', $data['status']))
            ->orderBy('created_at')->limit(5000)->get()->map(fn (Invoice $i) => [
                'id' => $i->id, 'number' => $i->number, 'patient_id' => $i->patient_id, 'status' => $i->status->value, 'payer' => $i->payer_type->value,
                'total' => $i->total_cents / 100, 'vat' => $i->vat_cents / 100, 'paid' => $i->paid_cents / 100, 'balance' => $i->balanceCents() / 100,
                'created_at' => $i->created_at?->toIso8601String(),
            ])->values()]);
    }

    public function prices(): JsonResponse
    {
        return response()->json(['data' => [
            'online_consults' => DB::table('tele_prices')->whereNull('staff_id')->orderBy('mode')->orderBy('duration_minutes')->get()
                ->map(fn ($p) => ['mode' => $p->mode, 'minutes' => (int) $p->duration_minutes, 'price' => $p->price_cents / 100])->values(),
            'prepaid_packages' => DB::table('prepaid_packages')->where('active', true)->orderBy('name')->get()
                ->map(fn ($p) => ['id' => $p->id, 'name' => $p->name, 'price' => $p->price_cents / 100, 'items' => json_decode((string) $p->items, true)])->values(),
        ]]);
    }

    /**
     * OpenAPI 3.1 description of this API (public, no key needed).
     */
    public function openapi(): JsonResponse
    {
        $get = fn (string $summary, string $scope, array $params) => ['get' => ['summary' => $summary, 'security' => [['bearer' => []]], 'x-scope' => $scope,
            'parameters' => array_map(fn ($p) => ['name' => $p[0], 'in' => 'query', 'required' => $p[1], 'schema' => ['type' => $p[2]], 'description' => $p[3]], $params),
            'responses' => ['200' => ['description' => 'OK'], '401' => ['description' => 'Invalid key'], '403' => ['description' => 'Missing permission or API not in package'], '429' => ['description' => 'Rate limited']]]];

        return response()->json([
            'openapi' => '3.1.0',
            'info' => ['title' => 'Clinic Flow practice API', 'version' => '1.0', 'description' => 'Read access to this practice\'s appointments, availability, patients (demographics only), invoices and prices. Send the key as "Authorization: Bearer cf_live_…". No clinical information is available through this API.'],
            'servers' => [['url' => url('/api/v1')]],
            'components' => ['securitySchemes' => ['bearer' => ['type' => 'http', 'scheme' => 'bearer']]],
            'paths' => [
                '/availability' => $get('Free appointment times per doctor', 'availability:read', [['date', true, 'string', 'YYYY-MM-DD'], ['staff_id', false, 'integer', 'One doctor only']]),
                '/appointments' => $get('Appointments in a date range (max 31 days)', 'appointments:read', [['from', true, 'string', 'YYYY-MM-DD'], ['to', true, 'string', 'YYYY-MM-DD']]),
                '/patients' => $get('Find a patient by exact cell or ID number', 'patients:read', [['cell', false, 'string', '0821234567'], ['id_number', false, 'string', '13 digits']]),
                '/invoices' => $get('Invoices in a date range (max 92 days)', 'invoices:read', [['from', true, 'string', 'YYYY-MM-DD'], ['to', true, 'string', 'YYYY-MM-DD'], ['status', false, 'string', 'e.g. open, paid']]),
                '/prices' => $get('Online consult prices and prepaid packages', 'prices:read', []),
            ],
        ]);
    }
}
