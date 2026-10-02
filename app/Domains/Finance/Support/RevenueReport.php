<?php

declare(strict_types=1);

namespace App\Domains\Finance\Support;

use App\Domains\Identity\Models\Staff;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Revenue by doctor and source. Consultations and procedures belong to the
 * doctor who saw the patient; medicines to the prescriber; lab tests to the
 * ordering doctor (stored on the invoice line).
 */
final class RevenueReport
{
    /**
     * @return list<array{doctor: string, consultation: int, procedure: int, medicine: int, lab: int, other: int, total: int}>
     */
    public static function byDoctor(CarbonInterface $from, CarbonInterface $to): array
    {
        $rows = DB::table('invoice_lines')
            ->join('invoices', 'invoices.id', '=', 'invoice_lines.invoice_id')
            ->leftJoin('visits', 'visits.id', '=', 'invoices.visit_id')
            ->whereBetween('invoice_lines.created_at', [$from, $to])
            ->selectRaw('COALESCE(invoice_lines.attributed_staff_id, visits.doctor_id) as staff_id, invoice_lines.kind as kind, SUM(invoice_lines.total_cents) as cents')
            ->groupBy('staff_id', 'kind')->get();

        $names = Staff::query()->pluck('name', 'id');
        $report = [];
        foreach ($rows as $row) {
            $key = $row->staff_id === null ? 'Unassigned' : (string) ($names[$row->staff_id] ?? 'Former staff');
            $report[$key] ??= ['doctor' => $key, 'consultation' => 0, 'procedure' => 0, 'medicine' => 0, 'lab' => 0, 'other' => 0, 'total' => 0];
            $kind = in_array($row->kind, ['consultation', 'procedure', 'medicine', 'lab'], true) ? $row->kind : 'other';
            $report[$key][$kind] += (int) $row->cents;
            $report[$key]['total'] += (int) $row->cents;
        }

        usort($report, fn (array $a, array $b) => $b['total'] <=> $a['total']);

        return $report;
    }

    /**
     * Money in today by method, net of refunds.
     *
     * @return array<string, int>
     */
    public static function takingsToday(): array
    {
        $in = DB::table('payments')->where('status', 'succeeded')->whereDate('created_at', today())
            ->selectRaw('method, SUM(amount_cents) as cents')->groupBy('method')->pluck('cents', 'method');
        $out = DB::table('refunds')->join('payments', 'payments.id', '=', 'refunds.payment_id')->whereDate('refunds.created_at', today())
            ->selectRaw('payments.method as method, SUM(refunds.amount_cents) as cents')->groupBy('payments.method')->pluck('cents', 'method');

        $takings = [];
        foreach ($in as $method => $cents) {
            $takings[(string) $method] = (int) $cents - (int) ($out[$method] ?? 0);
        }

        return $takings;
    }
}
