<?php

declare(strict_types=1);

namespace App\Domains\Hub\Actions;

use App\Domains\Billing\Actions\AddInvoiceLine;
use App\Domains\Billing\Actions\OpenInvoice;
use App\Domains\Billing\Enums\LineKind;
use App\Domains\Clinical\Models\Consultation;
use App\Domains\Clinical\Models\ConsultationDiagnosis;
use App\Domains\Hub\Models\HubLabOrder;
use App\Domains\Hub\Models\HubLink;
use App\Domains\Identity\Models\Staff;
use App\Domains\Lab\Actions\LabCatalog;
use App\Domains\Lab\Models\CatalogTest;
use App\Domains\Lab\Models\LabOrder;
use App\Domains\Lab\Models\LabResult;
use App\Domains\Patients\Enums\Channel;
use App\Domains\Patients\Enums\IdType;
use App\Domains\Patients\Models\Patient;
use App\Domains\Platform\Enums\ProviderStatus;
use App\Domains\Platform\Enums\ProviderType;
use App\Domains\Platform\Models\Provider;
use App\Domains\Platform\Models\Setting;
use App\Domains\Visits\Enums\PayerType;
use App\Domains\Visits\Models\Visit;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * Lab requests to any lab on the network. The practice orders from the lab's
 * own test menu; the lab accepts (creating its own record and invoice — the
 * lab bills the patient), collects (walk-in or at home), enters and verifies;
 * results are delivered into the requesting practice's record and then erased
 * from the Hub.
 */
class NetworkLabs
{
    /**
     * @return list<array{code: string, name: string, price: float, turnaround_hours: int, home_collection: bool}>
     */
    public function menu(string $labId): array
    {
        $lab = $this->lab($labId);

        return $lab->run(fn () => CatalogTest::query()->where('active', true)->orderBy('name')->get()
            ->map(fn (CatalogTest $t) => ['code' => $t->code, 'name' => $t->name, 'price' => $t->price_cents / 100, 'turnaround_hours' => $t->turnaround_hours, 'home_collection' => $t->home_collection])->values()->all());
    }

    /**
     * @param  list<string>  $codes
     * @param  array{address: string, date: string, window: string}|null  $home
     */
    public function send(Visit $visit, Staff $doctor, Provider $issuer, string $labId, array $codes, ?array $home = null): LabOrder
    {
        $lab = $this->lab($labId);
        $patient = Patient::query()->findOrFail($visit->patient_id);
        $identityId = $patient->getAttribute('hub_identity_id');
        $linked = is_string($identityId) && HubLink::query()->where('identity_id', $identityId)->where('tenant_id', $issuer->id)->where('status', 'active')->exists();
        if (! $linked) {
            throw ValidationException::withMessages(['lab_id' => 'Link this patient to the network (Network search) before sending lab requests.']);
        }

        $menu = collect($this->menu($labId))->keyBy('code');
        $chosen = array_values(array_unique(array_map('strtoupper', $codes)));
        $missing = array_diff($chosen, $menu->keys()->all());
        if ($chosen === [] || $missing !== []) {
            throw ValidationException::withMessages(['tests' => 'Choose tests from '.$lab->name."'s menu.".($missing ? ' Not offered: '.implode(', ', $missing).'.' : '')]);
        }
        if ($home !== null && ! (bool) $lab->run(fn () => Setting::get('lab', 'home_collection', false))) {
            throw ValidationException::withMessages(['home' => $lab->name.' does not offer home collection.']);
        }

        $order = LabOrder::create([
            'visit_id' => $visit->id, 'patient_id' => $patient->id, 'ordering_staff_id' => $doctor->id, 'status' => 'sent',
            'source' => 'network_out', 'lab_tenant_id' => $lab->id, 'home_collection' => $home,
        ]);
        foreach ($chosen as $code) {
            $order->results()->create(['test_code' => $code, 'name' => (string) $menu[$code]['name'], 'unit' => '', 'reference' => '']);
        }

        $codesIcd = ConsultationDiagnosis::query()->whereIn('consultation_id', Consultation::query()->where('visit_id', $visit->id)->select('id'))
            ->orderByDesc('is_primary')->pluck('icd10_code')->all();
        $hub = HubLabOrder::create([
            'identity_id' => $identityId, 'issuer_tenant_id' => $issuer->id, 'issuer_order_id' => $order->id, 'lab_tenant_id' => $lab->id,
            'payload' => [
                'patient' => ['first_names' => $patient->first_names, 'surname' => $patient->surname, 'date_of_birth' => $patient->date_of_birth->toDateString(), 'sex' => $patient->sex?->value, 'cell' => $patient->cell],
                'doctor' => ['name' => $doctor->name, 'hpcsa' => $doctor->professional_number], 'practice' => $issuer->name,
                'tests' => $chosen, 'icd10' => $codesIcd, 'home_collection' => $home,
            ],
            'status' => 'sent',
        ]);
        $order->forceFill(['hub_order_id' => $hub->id])->save();
        activity('hub')->performedOn($order)->withProperties(['lab' => $lab->name, 'tests' => $chosen])->log('Lab request sent to network lab');

        return $order;
    }

    /**
     * Lab accepts: its own patient record, lab order and invoice (tests plus any home-collection fee).
     */
    public function accept(HubLabOrder $hub, Provider $lab): LabOrder
    {
        $this->own($hub, $lab, 'sent');
        $p = $hub->payload['patient'];
        $patient = Patient::query()->where('hub_identity_id', $hub->identity_id)->first() ?? Patient::create([
            'first_names' => $p['first_names'], 'surname' => $p['surname'], 'id_type' => IdType::None, 'date_of_birth' => $p['date_of_birth'],
            'sex' => $p['sex'], 'cell' => $p['cell'], 'no_cell' => $p['cell'] === null, 'preferred_language' => 'en', 'preferred_channel' => Channel::Sms,
            'hub_identity_id' => $hub->identity_id, 'needs_consent' => true,
        ]);

        $catalog = app(LabCatalog::class);
        $order = LabOrder::create([
            'patient_id' => $patient->id, 'status' => 'ordered', 'source' => 'network_in', 'hub_order_id' => $hub->id,
            'home_collection' => $hub->payload['home_collection'] ?? null,
        ]);
        $invoice = app(OpenInvoice::class)->forPatient($patient->id, PayerType::Cash);
        foreach ((array) $hub->payload['tests'] as $code) {
            $test = $catalog->resolve((string) $code);
            if ($test instanceof CatalogTest) {
                $order->results()->create(['test_code' => $test->code, 'name' => $test->name, 'unit' => (string) $test->unit, 'reference' => '']);
                app(AddInvoiceLine::class)->handle($invoice, LineKind::Lab, "Lab: {$test->name}", $test->price_cents, 1, $test->code);
            }
        }
        if (! empty($hub->payload['home_collection'])) {
            app(AddInvoiceLine::class)->handle($invoice, LineKind::Other, 'Home collection', (int) Setting::get('lab', 'home_fee_cents', 15000), 1, 'HOME');
        }

        $hub->forceFill(['status' => 'accepted', 'lab_order_id' => $order->id])->save();
        $this->updateIssuer($hub, ['status' => 'accepted']);

        return $order;
    }

    public function reject(HubLabOrder $hub, Provider $lab, string $reason): void
    {
        $this->own($hub, $lab, 'sent');
        if (trim($reason) === '') {
            throw ValidationException::withMessages(['reason' => 'Give a reason for the doctor.']);
        }
        $hub->forceFill(['status' => 'rejected', 'status_note' => trim($reason)])->save();
        $this->updateIssuer($hub, ['status' => 'rejected', 'doctor_note' => 'Lab: '.trim($reason)]);
    }

    public function syncStatus(LabOrder $labOrder, string $status): void
    {
        $hub = HubLabOrder::query()->find($labOrder->getAttribute('hub_order_id'));
        if ($hub instanceof HubLabOrder) {
            $hub->forceFill(['status' => $status])->save();
            $this->updateIssuer($hub, ['status' => $status, 'collected_at' => $labOrder->collected_at, 'sample_barcode' => $labOrder->sample_barcode]);
        }
    }

    /**
     * Verified results go into the requesting practice's record (values, flags, the range
     * used and the lab's PDF); the Hub copy is erased once delivered.
     */
    public function deliverResults(LabOrder $labOrder): LabOrder
    {
        $hub = HubLabOrder::query()->findOrFail($labOrder->getAttribute('hub_order_id'));
        $pdf = $labOrder->getAttribute('report_path') !== null ? Storage::disk('local')->get((string) $labOrder->getAttribute('report_path')) : null;
        $payload = [
            'classification' => $labOrder->getAttribute('classification'), 'has_critical' => $labOrder->has_critical, 'verified_at' => now()->toIso8601String(),
            'barcode' => $labOrder->sample_barcode,
            'results' => $labOrder->results()->get()->map(fn (LabResult $r) => $r->only(['test_code', 'name', 'unit', 'reference', 'value', 'flag', 'result_text', 'ref_low', 'ref_high', 'critical_low', 'critical_high', 'raised_by_lab']))->all(),
            'pdf' => $pdf === null ? null : base64_encode($pdf),
        ];
        $hub->forceFill(['result_payload' => Crypt::encryptString((string) json_encode($payload))])->save();

        $issuer = Provider::query()->findOrFail($hub->issuer_tenant_id);
        $issuer->run(function () use ($hub): void {
            $data = json_decode(Crypt::decryptString((string) $hub->fresh()?->result_payload), true);
            $order = LabOrder::query()->findOrFail($hub->issuer_order_id);
            foreach ((array) $data['results'] as $r) {
                LabResult::query()->where('lab_order_id', $order->id)->where('test_code', $r['test_code'])->update(collect($r)->except('test_code')->all());
            }
            $path = null;
            if (is_string($data['pdf'] ?? null)) {
                $path = 'lab-reports/'.$order->id.'.pdf';
                Storage::disk('local')->put($path, (string) base64_decode($data['pdf']));
            }
            $order->forceFill([
                'status' => 'verified', 'verified_at' => now(), 'classification' => $data['classification'], 'has_critical' => (bool) $data['has_critical'],
                'report_path' => $path, 'sample_barcode' => $data['barcode'],
            ])->save();
            activity('lab')->performedOn($order)->log('Network lab results received');
        });

        // Delivered: the Hub keeps no results.
        $hub->forceFill(['result_payload' => null, 'status' => 'resulted', 'delivered_at' => now()])->save();

        return $labOrder;
    }

    /**
     * @param  array<string, mixed>  $changes
     */
    private function updateIssuer(HubLabOrder $hub, array $changes): void
    {
        Provider::query()->findOrFail($hub->issuer_tenant_id)->run(fn () => LabOrder::query()->whereKey($hub->issuer_order_id)->update($changes));
    }

    private function own(HubLabOrder $hub, Provider $lab, string $expected): void
    {
        abort_unless($hub->lab_tenant_id === $lab->id, 403);
        if ($hub->status !== $expected) {
            throw ValidationException::withMessages(['order' => "This request is {$hub->status}."]);
        }
    }

    private function lab(string $labId): Provider
    {
        $lab = Provider::query()->find($labId);
        if (! $lab instanceof Provider || $lab->type !== ProviderType::Lab || ! in_array($lab->status, [ProviderStatus::Trial, ProviderStatus::Active], true)) {
            throw ValidationException::withMessages(['lab_id' => 'Choose a lab that is live on the Clinic Flow network.']);
        }

        return $lab;
    }
}
