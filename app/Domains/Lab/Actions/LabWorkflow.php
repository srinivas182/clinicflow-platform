<?php

declare(strict_types=1);

namespace App\Domains\Lab\Actions;

use App\Domains\Billing\Actions\AddInvoiceLine;
use App\Domains\Billing\Enums\LineKind;
use App\Domains\Billing\Models\Invoice;
use App\Domains\Identity\Models\Staff;
use App\Domains\Lab\Models\LabOrder;
use App\Domains\Lab\Models\LabResult;
use App\Domains\Lab\Models\LabTest;
use App\Domains\Messaging\Actions\SendMessage;
use App\Domains\Visits\Models\Visit;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * In-house lab: order (doctor) → collect sample (barcode) → enter results
 * (auto-flagged) → verify (a second person) → doctor reviews, acknowledges
 * critical values and releases to the patient. Lab tests are billed to the
 * visit and attributed to the ordering doctor.
 */
class LabWorkflow
{
    public function __construct(private readonly AddInvoiceLine $addLine) {}

    /**
     * @param  list<string>  $testCodes
     */
    public function order(Visit $visit, Staff $doctor, array $testCodes): LabOrder
    {
        $codes = array_values(array_unique(array_map('strtoupper', $testCodes)));
        $tests = LabTest::query()->whereIn('code', $codes)->get()->keyBy('code');
        if ($codes === [] || $tests->count() !== count($codes)) {
            throw ValidationException::withMessages(['tests' => 'Choose tests from the lab catalogue.']);
        }

        return DB::transaction(function () use ($visit, $doctor, $codes, $tests): LabOrder {
            $order = LabOrder::create(['visit_id' => $visit->id, 'patient_id' => $visit->patient_id, 'ordering_staff_id' => $doctor->id, 'status' => 'ordered']);
            $invoice = Invoice::query()->where('visit_id', $visit->id)->firstOrFail();

            foreach ($codes as $code) {
                /** @var LabTest $test */
                $test = $tests[$code];
                $order->results()->create(['test_code' => $code, 'name' => $test->name, 'unit' => $test->unit, 'reference' => $test->referenceLabel()]);
                $line = $this->addLine->handle($invoice, LineKind::Lab, "Lab: {$test->name}", $test->price_cents, 1, $code);
                $line->forceFill(['attributed_staff_id' => $doctor->id])->save();
            }

            activity('lab')->performedOn($order)->withProperties(['tests' => $codes])->log('Lab tests ordered');

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

        return $order;
    }

    /**
     * @param  array<string, float|int|string>  $values  keyed by test code
     */
    public function enterResults(LabOrder $order, array $values, Staff $by): LabOrder
    {
        $this->expect($order, 'collected');
        $tests = LabTest::query()->whereIn('code', $order->results()->pluck('test_code'))->get()->keyBy('code');

        return DB::transaction(function () use ($order, $values, $by, $tests): LabOrder {
            $critical = false;
            foreach ($order->results()->get() as $result) {
                /** @var LabResult $result */
                if (! isset($values[$result->test_code]) || ! is_numeric($values[$result->test_code])) {
                    throw ValidationException::withMessages(["values.{$result->test_code}" => "Enter a value for {$result->name}."]);
                }
                $value = (float) $values[$result->test_code];
                $test = $tests->get($result->test_code);
                $flag = $test instanceof LabTest ? $test->flag($value) : 'normal';
                $critical = $critical || str_starts_with($flag, 'critical');
                $result->forceFill(['value' => $value, 'flag' => $flag])->save();
            }

            $order->forceFill(['status' => 'resulted', 'resulted_by' => $by->id, 'has_critical' => $critical])->save();
            activity('lab')->performedOn($order)->withProperties(['critical' => $critical])->log('Results entered');

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

        return $order;
    }

    public function acknowledgeCritical(LabOrder $order, Staff $doctor, string $action): LabOrder
    {
        if (! $order->has_critical || $order->status !== 'verified') {
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
        $this->expect($order, 'verified');
        $order->forceFill(['reviewed_at' => now(), 'doctor_comment' => $comment === null ? null : trim($comment)])->save();
        activity('lab')->performedOn($order)->log('Results reviewed');

        return $order;
    }

    public function release(LabOrder $order, Staff $doctor): LabOrder
    {
        if ($order->status !== 'verified' || $order->reviewed_at === null) {
            throw ValidationException::withMessages(['order' => 'Review the verified results before releasing them to the patient.']);
        }
        if ($order->has_critical && $order->critical_acknowledged_at === null) {
            throw ValidationException::withMessages(['order' => 'Acknowledge the critical result before releasing.']);
        }
        $order->forceFill(['status' => 'released', 'released_at' => now()])->save();
        $cell = $order->patient->cell;
        if (is_string($cell) && $cell !== '') {
            // The SMS never contains results; the patient views them in the app.
            app(SendMessage::class)->template('lab.results_ready', 'sms', $cell, ['patient' => $order->patient->fullName()], (string) ($order->patient->preferred_language ?? 'en'), 'lab_order', $order->id);
        }
        activity('lab')->performedOn($order)->causedBy(null)->withProperties(['doctor' => $doctor->id])->log('Results released to patient');

        return $order;
    }

    private function expect(LabOrder $order, string $status): void
    {
        if ($order->status !== $status) {
            throw ValidationException::withMessages(['order' => "This order is {$order->status}, not {$status}."]);
        }
    }
}
