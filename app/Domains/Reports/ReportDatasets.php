<?php

declare(strict_types=1);

namespace App\Domains\Reports;

use App\Domains\Identity\Enums\Permission;

/**
 * Predefined report data sets. Every grouping, filter and total is a fixed,
 * whitelisted expression — nothing a user types is ever placed into SQL. Reports
 * return totals only (no patient-identifying columns).
 */
final class ReportDatasets
{
    /**
     * @return array<string, array{label: string, table: string, date: string, permission: string, branch: bool,
     *   groups: array<string, array{label: string, sql: string}>, measures: array<string, array{label: string, sql: string, money: bool}>,
     *   filters: array<string, array{label: string, column: string, values?: list<string>}>}>
     */
    public static function all(): array
    {
        $period = fn (string $col) => [
            'day' => ['label' => 'Day', 'sql' => "DATE({$col})"],
            'week' => ['label' => 'Week', 'sql' => "DATE_FORMAT({$col}, '%x-W%v')"],
            'month' => ['label' => 'Month', 'sql' => "DATE_FORMAT({$col}, '%Y-%m')"],
        ];

        return [
            'visits' => ['label' => 'Visits', 'table' => 'visits', 'date' => 'visits.visit_date', 'permission' => Permission::APPOINTMENTS_VIEW, 'branch' => true,
                'groups' => $period('visits.visit_date') + [
                    'doctor' => ['label' => 'Doctor', 'sql' => 'visits.doctor_id'], 'payer' => ['label' => 'Payer', 'sql' => 'visits.payer_type'],
                    'stage' => ['label' => 'Stage', 'sql' => 'visits.stage'], 'branch' => ['label' => 'Branch', 'sql' => 'visits.branch_id'],
                ],
                'measures' => ['count' => ['label' => 'Visits', 'sql' => 'COUNT(*)', 'money' => false]],
                'filters' => ['payer' => ['label' => 'Payer', 'column' => 'visits.payer_type', 'values' => ['cash', 'medical_aid']], 'doctor' => ['label' => 'Doctor', 'column' => 'visits.doctor_id']]],
            'appointments' => ['label' => 'Appointments', 'table' => 'appointments', 'date' => 'appointments.starts_at', 'permission' => Permission::APPOINTMENTS_VIEW, 'branch' => true,
                'groups' => $period('appointments.starts_at') + [
                    'doctor' => ['label' => 'Doctor', 'sql' => 'appointments.staff_id'], 'status' => ['label' => 'Status', 'sql' => 'appointments.status'],
                    'type' => ['label' => 'Consult type', 'sql' => 'appointments.consult_type'], 'branch' => ['label' => 'Branch', 'sql' => 'appointments.branch_id'],
                ],
                'measures' => ['count' => ['label' => 'Appointments', 'sql' => 'COUNT(*)', 'money' => false],
                    'no_shows' => ['label' => 'No-shows', 'sql' => "SUM(appointments.status = 'no_show')", 'money' => false]],
                'filters' => ['status' => ['label' => 'Status', 'column' => 'appointments.status'], 'doctor' => ['label' => 'Doctor', 'column' => 'appointments.staff_id']]],
            'invoices' => ['label' => 'Invoices', 'table' => 'invoices', 'date' => 'invoices.created_at', 'permission' => Permission::FINANCE_VIEW, 'branch' => true,
                'groups' => $period('invoices.created_at') + [
                    'payer' => ['label' => 'Payer', 'sql' => 'invoices.payer_type'], 'status' => ['label' => 'Status', 'sql' => 'invoices.status'],
                    'branch' => ['label' => 'Branch', 'sql' => 'invoices.branch_id'],
                ],
                'measures' => ['count' => ['label' => 'Invoices', 'sql' => 'COUNT(*)', 'money' => false], 'billed' => ['label' => 'Billed', 'sql' => 'SUM(invoices.total_cents)', 'money' => true],
                    'collected' => ['label' => 'Paid', 'sql' => 'SUM(invoices.paid_cents)', 'money' => true], 'vat' => ['label' => 'VAT', 'sql' => 'SUM(invoices.vat_cents)', 'money' => true],
                    'outstanding' => ['label' => 'Outstanding', 'sql' => 'SUM(invoices.total_cents - invoices.paid_cents - invoices.credited_cents)', 'money' => true]],
                'filters' => ['payer' => ['label' => 'Payer', 'column' => 'invoices.payer_type', 'values' => ['cash', 'medical_aid']], 'status' => ['label' => 'Status', 'column' => 'invoices.status']]],
            'payments' => ['label' => 'Payments', 'table' => 'payments', 'date' => 'payments.created_at', 'permission' => Permission::FINANCE_VIEW, 'branch' => false,
                'groups' => $period('payments.created_at') + ['method' => ['label' => 'Method', 'sql' => 'payments.method'], 'status' => ['label' => 'Status', 'sql' => 'payments.status']],
                'measures' => ['count' => ['label' => 'Payments', 'sql' => 'COUNT(*)', 'money' => false], 'amount' => ['label' => 'Amount', 'sql' => 'SUM(payments.amount_cents)', 'money' => true],
                    'refunded' => ['label' => 'Refunded', 'sql' => 'SUM(payments.refunded_cents)', 'money' => true]],
                'filters' => ['method' => ['label' => 'Method', 'column' => 'payments.method'], 'status' => ['label' => 'Status', 'column' => 'payments.status']]],
            'claims' => ['label' => 'Medical aid claims', 'table' => 'claims', 'date' => 'claims.created_at', 'permission' => Permission::FINANCE_VIEW, 'branch' => false,
                'groups' => $period('claims.created_at') + ['scheme' => ['label' => 'Scheme', 'sql' => 'claims.scheme'], 'status' => ['label' => 'Status', 'sql' => 'claims.status']],
                'measures' => ['count' => ['label' => 'Claims', 'sql' => 'COUNT(*)', 'money' => false], 'amount' => ['label' => 'Claimed', 'sql' => 'SUM(claims.total_cents)', 'money' => true]],
                'filters' => ['status' => ['label' => 'Status', 'column' => 'claims.status'], 'scheme' => ['label' => 'Scheme', 'column' => 'claims.scheme']]],
            'lab_orders' => ['label' => 'Lab orders', 'table' => 'lab_orders', 'date' => 'lab_orders.created_at', 'permission' => Permission::LAB_MANAGE, 'branch' => false,
                'groups' => $period('lab_orders.created_at') + ['status' => ['label' => 'Status', 'sql' => 'lab_orders.status'], 'classification' => ['label' => 'Result type', 'sql' => 'lab_orders.classification'],
                    'doctor' => ['label' => 'Ordering doctor', 'sql' => 'lab_orders.ordering_staff_id'], 'source' => ['label' => 'Source', 'sql' => 'lab_orders.source']],
                'measures' => ['count' => ['label' => 'Orders', 'sql' => 'COUNT(*)', 'money' => false], 'critical' => ['label' => 'Critical', 'sql' => 'SUM(lab_orders.has_critical)', 'money' => false],
                    'turnaround_hours' => ['label' => 'Avg hours to verify', 'sql' => 'ROUND(AVG(TIMESTAMPDIFF(MINUTE, lab_orders.created_at, lab_orders.verified_at)) / 60, 1)', 'money' => false]],
                'filters' => ['status' => ['label' => 'Status', 'column' => 'lab_orders.status']]],
            'prescriptions' => ['label' => 'Prescriptions', 'table' => 'prescriptions', 'date' => 'prescriptions.signed_at', 'permission' => Permission::AUDIT_VIEW, 'branch' => false,
                'groups' => $period('prescriptions.signed_at') + ['doctor' => ['label' => 'Prescriber', 'sql' => 'prescriptions.prescriber_staff_id'], 'chronic' => ['label' => 'Chronic', 'sql' => 'prescriptions.chronic']],
                'measures' => ['count' => ['label' => 'Scripts', 'sql' => 'COUNT(*)', 'money' => false]],
                'filters' => ['doctor' => ['label' => 'Prescriber', 'column' => 'prescriptions.prescriber_staff_id']]],
        ];
    }
}
