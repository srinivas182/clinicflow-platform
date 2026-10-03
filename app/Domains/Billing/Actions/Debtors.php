<?php

declare(strict_types=1);

namespace App\Domains\Billing\Actions;

use App\Domains\Billing\Enums\InvoiceStatus;
use App\Domains\Billing\Models\Invoice;
use App\Domains\Messaging\Actions\SendMessage;
use App\Domains\Patients\Models\Patient;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * What patients owe: ageing, monthly statements with a pay link (once per
 * month), and bad-debt write-offs that a second person approves.
 */
class Debtors
{
    /**
     * @return array{buckets: array{current: int, '30': int, '60': int, '90+': int}, patients: list<array{patient_id: string, name: string, balance: int, oldest_days: int}>}
     */
    public function ageing(): array
    {
        $buckets = ['current' => 0, '30' => 0, '60' => 0, '90+' => 0];
        $patients = [];
        Invoice::query()->with('patient')->where('status', '!=', InvoiceStatus::Void->value)->whereColumn('paid_cents', '<', 'total_cents')->get()
            ->each(function (Invoice $i) use (&$buckets, &$patients): void {
                $due = $i->balanceCents();
                if ($due <= 0) {
                    return;
                }
                $days = (int) $i->created_at?->diffInDays(now());
                $key = match (true) {
                    $days < 30 => 'current', $days < 60 => '30', $days < 90 => '60', default => '90+'
                };
                $buckets[$key] += $due;
                $p = $patients[$i->patient_id] ?? ['patient_id' => $i->patient_id, 'name' => $i->patient->fullName(), 'balance' => 0, 'oldest_days' => 0];
                $p['balance'] += $due;
                $p['oldest_days'] = max($p['oldest_days'], $days);
                $patients[$i->patient_id] = $p;
            });
        usort($patients, fn ($a, $b) => $b['balance'] <=> $a['balance']);

        return ['buckets' => $buckets, 'patients' => $patients];
    }

    /**
     * Sends each patient with a balance one statement per month, with a link to pay in the portal.
     */
    public function sendStatements(): int
    {
        $period = now()->format('Y-m');
        $sent = 0;
        foreach ($this->ageing()['patients'] as $row) {
            if (DB::table('statements')->where('patient_id', $row['patient_id'])->where('period', $period)->exists()) {
                continue;
            }
            $patient = Patient::query()->find($row['patient_id']);
            if (! $patient instanceof Patient || blank($patient->cell)) {
                continue;
            }
            app(SendMessage::class)->template('billing.pay_link', 'sms', (string) $patient->cell, [
                'patient' => $patient->first_names, 'amount' => 'R'.number_format($row['balance'] / 100, 2, '.', ' '), 'link' => url('/my'),
            ], 'en', 'statement', $patient->id);
            DB::table('statements')->insert(['patient_id' => $patient->id, 'period' => $period, 'balance_cents' => $row['balance'], 'sent_at' => now()]);
            $sent++;
        }

        return $sent;
    }

    public function requestWriteOff(Invoice $invoice, string $reason, int $by): int
    {
        if (trim($reason) === '' || $invoice->balanceCents() <= 0) {
            throw ValidationException::withMessages(['reason' => 'Give a reason; only an unpaid balance can be written off.']);
        }

        return (int) DB::table('debt_write_offs')->insertGetId(['invoice_id' => $invoice->id, 'amount_cents' => $invoice->balanceCents(), 'reason' => trim($reason), 'requested_by' => $by, 'created_at' => now(), 'updated_at' => now()]);
    }

    public function approveWriteOff(int $id, int $by): void
    {
        $w = DB::table('debt_write_offs')->where('id', $id)->first();
        if ($w === null || $w->approved_at !== null) {
            throw ValidationException::withMessages(['write_off' => 'This write-off cannot be approved.']);
        }
        if ((int) $w->requested_by === $by) {
            throw ValidationException::withMessages(['write_off' => 'A different person must approve a write-off.']);
        }
        $invoice = Invoice::query()->findOrFail((string) $w->invoice_id);
        $amount = min((int) $w->amount_cents, $invoice->balanceCents());
        $note = app(IssueCreditNote::class)->handle($invoice, $amount, 'Bad debt written off: '.$w->reason);
        DB::table('debt_write_offs')->where('id', $id)->update(['approved_by' => $by, 'approved_at' => now(), 'credit_note_id' => $note->id, 'updated_at' => now()]);
    }

    /**
     * Output VAT on sales (less credit notes) and input VAT on supplier purchases for a period — the VAT201 figures.
     *
     * @return array{sales_cents: int, output_vat_cents: int, purchases_cents: int, input_vat_cents: int, net_vat_cents: int}
     */
    public function vatReport(string $from, string $to): array
    {
        $sales = (int) DB::table('invoice_lines')->whereBetween('created_at', [$from.' 00:00:00', $to.' 23:59:59'])->sum('total_cents')
            - (int) DB::table('credit_notes')->whereBetween('created_at', [$from.' 00:00:00', $to.' 23:59:59'])->sum('amount_cents');
        $output = (int) DB::table('invoice_lines')->whereBetween('created_at', [$from.' 00:00:00', $to.' 23:59:59'])->sum('vat_cents')
            - (int) DB::table('credit_notes')->whereBetween('created_at', [$from.' 00:00:00', $to.' 23:59:59'])->sum('vat_cents');
        $purchases = DB::table('purchase_orders')->whereIn('status', ['partial', 'received'])->whereBetween('updated_at', [$from.' 00:00:00', $to.' 23:59:59']);
        $input = (int) (clone $purchases)->sum('vat_cents');

        return ['sales_cents' => $sales, 'output_vat_cents' => $output, 'purchases_cents' => (int) (clone $purchases)->sum('total_cents'), 'input_vat_cents' => $input, 'net_vat_cents' => $output - $input];
    }
}
