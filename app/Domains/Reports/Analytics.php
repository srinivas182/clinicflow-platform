<?php

declare(strict_types=1);

namespace App\Domains\Reports;

use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Practice analytics (totals only, no patient records). Figures use the same
 * definitions as the group dashboard: takings = successful payments less refunds;
 * owed = non-void invoices' unpaid balance.
 */
final class Analytics
{
    /**
     * @return array<string, int|float|null>
     */
    public function summary(CarbonImmutable $from, CarbonImmutable $to, ?int $branchId = null): array
    {
        $range = [$from->startOfDay(), $to->endOfDay()];
        $branch = fn (Builder $q, string $table) => $branchId === null ? $q : $q->where("{$table}.branch_id", $branchId);

        $billed = (int) $branch(DB::table('invoices'), 'invoices')->where('status', '!=', 'void')->whereBetween('created_at', $range)->sum('total_cents');
        $takings = (int) DB::table('payments')->where('status', 'succeeded')->whereBetween('created_at', $range)->sum(DB::raw('amount_cents - refunded_cents'));
        $owed = (int) $branch(DB::table('invoices'), 'invoices')->where('status', '!=', 'void')->sum(DB::raw('GREATEST(total_cents - paid_cents - credited_cents, 0)'));
        $billed90 = (int) $branch(DB::table('invoices'), 'invoices')->where('status', '!=', 'void')->where('created_at', '>=', $to->subDays(90)->startOfDay())->where('created_at', '<=', $to->endOfDay())->sum('total_cents');

        $appts = $branch(DB::table('appointments'), 'appointments')->whereBetween('starts_at', $range);
        $apptTotal = (clone $appts)->count();
        $noShows = (clone $appts)->where('status', 'no_show')->count();
        $cancelled = (clone $appts)->where('status', 'cancelled')->count();
        $online = (clone $appts)->where('consult_type', '!=', 'in_person')->count();
        $bookedMinutes = (int) (clone $appts)->where('status', '!=', 'cancelled')->sum(DB::raw('TIMESTAMPDIFF(MINUTE, starts_at, ends_at)'));
        $rosterMinutes = (int) $branch(DB::table('roster_sessions'), 'roster_sessions')->whereBetween('starts_at', $range)->sum(DB::raw('TIMESTAMPDIFF(MINUTE, starts_at, ends_at)'));

        $visits = $branch(DB::table('visits'), 'visits')->whereBetween('visit_date', [$from->toDateString(), $to->toDateString()]);
        $wait = (clone $visits)->whereNotNull('called_at')->avg(DB::raw('TIMESTAMPDIFF(MINUTE, created_at, called_at)'));
        $patientIds = (clone $visits)->distinct()->pluck('patient_id');
        $returning = $patientIds->isEmpty() ? 0 : DB::table('visits')->whereIn('patient_id', $patientIds)->where('visit_date', '<', $from->toDateString())->distinct()->count('patient_id');
        $turnaround = DB::table('lab_orders')->whereBetween('created_at', $range)->whereNotNull('verified_at')->avg(DB::raw('TIMESTAMPDIFF(MINUTE, created_at, verified_at)'));

        return [
            'billed' => $billed / 100, 'takings' => $takings / 100, 'owed' => $owed / 100,
            'collection_rate' => $billed > 0 ? round($takings * 100 / $billed, 1) : null,
            'debtor_days' => $billed90 > 0 ? round($owed / ($billed90 / 90), 1) : null,
            'appointments' => $apptTotal, 'no_show_rate' => $apptTotal > 0 ? round($noShows * 100 / $apptTotal, 1) : null,
            'cancel_rate' => $apptTotal > 0 ? round($cancelled * 100 / $apptTotal, 1) : null, 'online_consults' => $online,
            'utilisation' => $rosterMinutes > 0 ? round(min(100, $bookedMinutes * 100 / $rosterMinutes), 1) : null,
            'visits' => (clone $visits)->count(), 'avg_wait_minutes' => $wait === null ? null : round((float) $wait, 1),
            'new_patients' => DB::table('patients')->whereBetween('created_at', $range)->count(), 'returning_patients' => $returning,
            'lab_turnaround_hours' => $turnaround === null ? null : round((float) $turnaround / 60, 1),
        ];
    }

    /**
     * Billed and takings per month for the last $months months.
     *
     * @return list<array{month: string, billed: float, takings: float}>
     */
    public function trend(CarbonImmutable $to, int $months = 6): array
    {
        $out = [];
        for ($i = $months - 1; $i >= 0; $i--) {
            $start = $to->subMonthsNoOverflow($i)->startOfMonth();
            $range = [$start, $start->endOfMonth()];
            $out[] = ['month' => $start->format('Y-m'),
                'billed' => (int) DB::table('invoices')->where('status', '!=', 'void')->whereBetween('created_at', $range)->sum('total_cents') / 100,
                'takings' => (int) DB::table('payments')->where('status', 'succeeded')->whereBetween('created_at', $range)->sum(DB::raw('amount_cents - refunded_cents')) / 100];
        }

        return $out;
    }

    /**
     * Per doctor: visits, appointments, no-show rate, billed and utilisation.
     *
     * @return list<array<string, mixed>>
     */
    public function byDoctor(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $range = [$from->startOfDay(), $to->endOfDay()];
        $doctors = DB::table('staff')->whereIn('role', ['doctor', 'locum_doctor', 'owner'])->orderBy('name')->get(['id', 'name']);

        $rows = $doctors->map(function ($d) use ($range, $from, $to) {
            $appts = DB::table('appointments')->where('staff_id', $d->id)->whereBetween('starts_at', $range);
            $total = (clone $appts)->count();
            $booked = (int) (clone $appts)->where('status', '!=', 'cancelled')->sum(DB::raw('TIMESTAMPDIFF(MINUTE, starts_at, ends_at)'));
            $roster = (int) DB::table('roster_sessions')->where('staff_id', $d->id)->whereBetween('starts_at', $range)->sum(DB::raw('TIMESTAMPDIFF(MINUTE, starts_at, ends_at)'));
            $visitIds = DB::table('visits')->where('doctor_id', $d->id)->whereBetween('visit_date', [$from->toDateString(), $to->toDateString()])->pluck('id');

            return ['doctor' => (string) $d->name, 'visits' => $visitIds->count(), 'appointments' => $total,
                'no_show_rate' => $total > 0 ? round((clone $appts)->where('status', 'no_show')->count() * 100 / $total, 1) : null,
                'billed' => (int) DB::table('invoices')->whereIn('visit_id', $visitIds)->where('status', '!=', 'void')->sum('total_cents') / 100,
                'utilisation' => $roster > 0 ? round(min(100, $booked * 100 / $roster), 1) : null];
        })->filter(fn ($r) => $r['visits'] > 0 || $r['appointments'] > 0)->values()->all();

        return array_values($rows);
    }

    /**
     * Per branch: visits and billed (payments are not branch-tagged).
     *
     * @return list<array<string, mixed>>
     */
    public function byBranch(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $range = [$from->startOfDay(), $to->endOfDay()];

        $rows = DB::table('branches')->where('active', true)->orderBy('name')->get(['id', 'name'])->map(fn ($b) => [
            'branch' => (string) $b->name,
            'visits' => DB::table('visits')->where('branch_id', $b->id)->whereBetween('visit_date', [$from->toDateString(), $to->toDateString()])->count(),
            'billed' => (int) DB::table('invoices')->where('branch_id', $b->id)->where('status', '!=', 'void')->whereBetween('created_at', $range)->sum('total_cents') / 100,
        ])->values()->all();

        return array_values($rows);
    }
}
