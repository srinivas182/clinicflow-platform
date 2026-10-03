<?php

declare(strict_types=1);

namespace App\Domains\Lab\Actions;

use App\Domains\Billing\Actions\AddInvoiceLine;
use App\Domains\Billing\Actions\OpenInvoice;
use App\Domains\Billing\Enums\LineKind;
use App\Domains\Billing\Models\Invoice;
use App\Domains\Hub\Actions\NetworkLabs;
use App\Domains\Identity\Models\Staff;
use App\Domains\Lab\Models\CatalogTest;
use App\Domains\Lab\Models\LabOrder;
use App\Domains\Lab\Models\LabResult;
use App\Domains\Lab\Support\Classifier;
use App\Domains\Messaging\Actions\SendMessage;
use App\Domains\Patients\Models\Patient;
use App\Domains\Visits\Enums\PayerType;
use App\Domains\Visits\Models\Visit;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Lab work from this lab's own templates:
 * order → collect → enter values (system flags by sex and age) → second-person verify → route:
 *  - in-house: to the ordering doctor's results inbox;
 *  - network: delivered to the requesting practice through the Network Hub;
 *  - patient-requested (no doctor): released straight to the patient.
 * The doctor then releases, releases with a note, or asks to discuss in person.
 */
class LabWorkflow
{
    public function __construct(
        private readonly AddInvoiceLine $addLine,
        private readonly LabCatalog $catalog,
        private readonly OpenInvoice $invoices,
    ) {}

    /**
     * @param  list<string>  $testCodes
     */
    public function order(Visit $visit, Staff $doctor, array $testCodes): LabOrder
    {
        $tests = $this->tests($testCodes);

        return DB::transaction(function () use ($visit, $doctor, $tests): LabOrder {
            $order = LabOrder::create(['visit_id' => $visit->id, 'patient_id' => $visit->patient_id, 'ordering_staff_id' => $doctor->id, 'status' => 'ordered', 'source' => 'in_house']);
            $invoice = Invoice::query()->where('visit_id', $visit->id)->firstOrFail();
            foreach ($tests as $test) {
                $this->addRow($order, $test);
                $this->addLine->handle($invoice, LineKind::Lab, "Lab: {$test->name}", $test->price_cents, 1, $test->code)->forceFill(['attributed_staff_id' => $doctor->id])->save();
            }
            activity('lab')->performedOn($order)->withProperties(['tests' => array_map(fn (CatalogTest $t) => $t->code, $tests)])->log('Lab tests ordered');

            return $order;
        });
    }

    /**
     * Patient books tests directly with the lab (no doctor): released straight to the patient after verification.
     *
     * @param  list<string>  $testCodes
     */
    public function orderSelf(Patient $patient, array $testCodes, Staff $by): LabOrder
    {
        $tests = $this->tests($testCodes);

        return DB::transaction(function () use ($patient, $tests, $by): LabOrder {
            $order = LabOrder::create(['patient_id' => $patient->id, 'status' => 'ordered', 'source' => 'self']);
            $invoice = $this->invoices->forPatient($patient->id, PayerType::Cash);
            foreach ($tests as $test) {
                $this->addRow($order, $test);
                $this->addLine->handle($invoice, LineKind::Lab, "Lab: {$test->name}", $test->price_cents, 1, $test->code);
            }
            activity('lab')->performedOn($order)->causedBy(null)->withProperties(['by' => $by->id])->log('Patient-requested lab tests');

            return $order;
        });
    }

    public function collect(LabOrder $order, Staff $by): LabOrder
    {
        $this->expect($order, 'ordered');
        $count = LabOrder::query()->whereDate('collected_at', today())->count() + 1;
        $order->forceFill([
            'status' => 'collected', 'collected_by' => $by->id, 'collected_at' => now(),
            'sample_barcode' => 'LAB-'.now()->format('ymd').'-'.str_pad((string) $count, 4, '0', STR_PAD_LEFT),
        ])->save();
        activity('lab')->performedOn($order)->log('Sample collected');

        if ($order->getAttribute('source') === 'network_in') {
            app(NetworkLabs::class)->syncStatus($order, 'collected');
        }

        return $order;
    }

    /**
     * Enter results from the template. Numeric values are flagged by the system against
     * the range for the patient's sex and age; choice/text results need the lab's
     * classification. $labFlags can only raise a numeric flag, never lower it. With a
     * PDF and no values the order is unclassified (doctor first, never auto-released).
     *
     * @param  array<string, float|int|string>  $values  keyed by test code
     * @param  array<string, string>  $labFlags  code => normal|abnormal|critical
     */
    public function enterResults(LabOrder $order, array $values, Staff $by, array $labFlags = [], ?string $reportPath = null): LabOrder
    {
        $this->expect($order, 'collected');
        $patient = Patient::query()->findOrFail($order->patient_id);

        return DB::transaction(function () use ($order, $values, $by, $labFlags, $reportPath, $patient): LabOrder {
            $flags = [];
            foreach ($order->results()->get() as $result) {
                /** @var LabResult $result */
                $code = $result->test_code;
                $test = $this->catalog->resolve($code);
                $has = array_key_exists($code, $values) && $values[$code] !== '' && $values[$code] !== null;

                if (! $has) {
                    if ($reportPath === null) {
                        throw ValidationException::withMessages(["values.{$code}" => "Enter a result for {$result->name}, or upload the lab report."]);
                    }
                    $flags[] = null;

                    continue;
                }
                if (! $test instanceof CatalogTest) {
                    throw ValidationException::withMessages(["values.{$code}" => "{$result->name} is not in this lab's catalogue."]);
                }

                if ($test->result_type === 'numeric') {
                    $range = Classifier::rangeFor($test, $patient);
                    $checked = Classifier::numeric($test, $range, "values.{$code}", $values[$code]);
                    $flag = $checked['flag'];
                    $raised = false;
                    if (($labFlags[$code] ?? null) === 'critical' && ! str_starts_with($flag, 'critical')) {
                        [$flag, $raised] = ['critical', true];
                    } elseif (($labFlags[$code] ?? null) === 'abnormal' && $flag === 'normal') {
                        [$flag, $raised] = ['abnormal', true];
                    }
                    $result->forceFill([
                        'value' => $checked['value'], 'flag' => $flag, 'raised_by_lab' => $raised, 'unit' => (string) $test->unit,
                        'reference' => Classifier::label($range), 'ref_low' => $range?->ref_low, 'ref_high' => $range?->ref_high,
                        'critical_low' => $range?->critical_low, 'critical_high' => $range?->critical_high,
                    ])->save();
                } else {
                    $text = trim((string) $values[$code]);
                    if ($test->result_type === 'choice' && ! in_array($text, (array) $test->choices, true)) {
                        throw ValidationException::withMessages(["values.{$code}" => 'Choose one of: '.implode(', ', (array) $test->choices).'.']);
                    }
                    $flag = $labFlags[$code] ?? null;
                    if (! in_array($flag, ['normal', 'abnormal', 'critical'], true)) {
                        throw ValidationException::withMessages(["flags.{$code}" => "Classify {$result->name} as normal, abnormal or critical."]);
                    }
                    $result->forceFill(['result_text' => $text, 'flag' => $flag, 'raised_by_lab' => $flag !== 'normal'])->save();
                }
                $flags[] = $flag;
            }

            $classification = Classifier::order($flags);
            $order->forceFill([
                'status' => 'resulted', 'resulted_by' => $by->id, 'classification' => $classification,
                'has_critical' => $classification === 'critical', 'report_path' => $reportPath ?? $order->getAttribute('report_path'),
            ])->save();
            activity('lab')->performedOn($order)->withProperties(['classification' => $classification])->log('Results entered');

            return $order;
        });
    }

    public function verify(LabOrder $order, Staff $by): LabOrder
    {
        $this->expect($order, 'resulted');
        if ($order->resulted_by === $by->id) {
            throw ValidationException::withMessages(['order' => 'Results must be verified by someone other than the person who entered them.']);
        }
        $order->forceFill(['status' => 'verified', 'verified_by' => $by->id, 'verified_at' => now()])->save();
        activity('lab')->performedOn($order)->log('Results verified');

        return match ($order->getAttribute('source')) {
            'network_in' => app(NetworkLabs::class)->deliverResults($order),
            'self' => $this->releaseToPatient($order, null),
            default => $order,
        };
    }

    public function acknowledgeCritical(LabOrder $order, Staff $doctor, string $action): LabOrder
    {
        if (! $order->has_critical || ! in_array($order->status, ['verified', 'discuss'], true)) {
            throw ValidationException::withMessages(['order' => 'There is no verified critical result to acknowledge.']);
        }
        if (trim($action) === '') {
            throw ValidationException::withMessages(['action' => 'Record what was done about the critical result.']);
        }
        $order->forceFill(['critical_acknowledged_at' => now(), 'critical_acknowledged_by' => $doctor->id])->save();
        activity('lab')->performedOn($order)->withProperties(['action' => trim($action)])->log('Critical result acknowledged');

        return $order;
    }

    public function review(LabOrder $order, Staff $doctor, ?string $comment): LabOrder
    {
        if (! in_array($order->status, ['verified', 'discuss'], true)) {
            throw ValidationException::withMessages(['order' => "This order is {$order->status}, not verified."]);
        }
        $order->forceFill(['reviewed_at' => now(), 'doctor_comment' => $comment === null ? null : trim($comment)])->save();
        activity('lab')->performedOn($order)->log('Results reviewed');

        return $order;
    }

    /**
     * Doctor holds the values back and asks the patient to come in; the patient sees the note and a booking link.
     */
    public function discuss(LabOrder $order, Staff $doctor, string $note): LabOrder
    {
        if (! in_array($order->status, ['verified', 'discuss'], true)) {
            throw ValidationException::withMessages(['order' => 'Only verified results can be discussed.']);
        }
        if (trim($note) === '') {
            throw ValidationException::withMessages(['note' => 'Write a short note for the patient.']);
        }
        $order->forceFill(['status' => 'discuss', 'doctor_action' => 'discuss', 'doctor_note' => trim($note), 'reviewed_at' => now()])->save();
        $this->notifyPatient($order, 'lab.results_ready');
        activity('lab')->performedOn($order)->log('Results held: discuss in person');

        return $order;
    }

    public function release(LabOrder $order, Staff $doctor, ?string $note = null): LabOrder
    {
        if (! in_array($order->status, ['verified', 'discuss'], true) || $order->reviewed_at === null) {
            throw ValidationException::withMessages(['order' => 'Review the verified results before releasing them to the patient.']);
        }
        if ($order->has_critical && $order->critical_acknowledged_at === null) {
            throw ValidationException::withMessages(['order' => 'Acknowledge the critical result before releasing.']);
        }
        if ($note !== null && trim($note) !== '') {
            $order->forceFill(['doctor_note' => trim($note)]);
        }

        return $this->releaseToPatient($order, 'released');
    }

    public function releaseToPatient(LabOrder $order, ?string $action, ?string $autoNote = null): LabOrder
    {
        $order->forceFill([
            'status' => 'released', 'released_at' => now(), 'doctor_action' => $action ?? $order->getAttribute('doctor_action'),
            'doctor_note' => $order->getAttribute('doctor_note') ?? $autoNote,
            'auto_released_at' => $autoNote !== null ? now() : null,
        ])->save();
        $this->notifyPatient($order, 'lab.results_ready');
        activity('lab')->performedOn($order)->withProperties(['auto' => $autoNote !== null])->log('Results released to patient');

        return $order;
    }

    /**
     * @param  list<string>  $codes
     * @return list<CatalogTest>
     */
    private function tests(array $codes): array
    {
        $codes = array_values(array_unique(array_map('strtoupper', $codes)));
        $tests = [];
        foreach ($codes as $code) {
            $test = $this->catalog->resolve($code);
            if (! $test instanceof CatalogTest) {
                throw ValidationException::withMessages(['tests' => "{$code} is not in the lab catalogue."]);
            }
            $tests[] = $test;
        }
        if ($tests === []) {
            throw ValidationException::withMessages(['tests' => 'Choose tests from the lab catalogue.']);
        }

        return $tests;
    }

    private function addRow(LabOrder $order, CatalogTest $test): void
    {
        $order->results()->create(['test_code' => $test->code, 'name' => $test->name, 'unit' => (string) $test->unit, 'reference' => '']);
    }

    private function notifyPatient(LabOrder $order, string $template): void
    {
        $patient = Patient::query()->find($order->patient_id);
        if ($patient instanceof Patient && is_string($patient->cell) && $patient->cell !== '') {
            // The message never contains results; the patient signs in to view them.
            app(SendMessage::class)->template($template, 'sms', $patient->cell, ['patient' => $patient->first_names], 'en', 'lab_order', $order->id);
        }
    }

    private function expect(LabOrder $order, string $status): void
    {
        if ($order->status !== $status) {
            throw ValidationException::withMessages(['order' => "This order is {$order->status}, not {$status}."]);
        }
    }
}
