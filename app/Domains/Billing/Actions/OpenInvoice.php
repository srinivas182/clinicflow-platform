<?php

declare(strict_types=1);

namespace App\Domains\Billing\Actions;

use App\Domains\Billing\Enums\InvoiceStatus;
use App\Domains\Billing\Models\Invoice;
use App\Domains\Visits\Enums\PayerType;
use App\Domains\Visits\Models\Visit;
use Illuminate\Support\Facades\DB;

/**
 * Opens a visit's invoice. Numbers are sequential per provider and year.
 */
class OpenInvoice
{
    public function handle(Visit $visit, PayerType $payer): Invoice
    {
        $year = now()->format('Y');
        $prefix = "INV-{$year}-";

        $last = DB::table('invoices')->where('number', 'like', $prefix.'%')->lockForUpdate()->max('number');
        $next = is_string($last) ? ((int) substr($last, strlen($prefix))) + 1 : 1;

        return Invoice::create([
            'number' => $prefix.str_pad((string) $next, 6, '0', STR_PAD_LEFT),
            'patient_id' => $visit->patient_id,
            'visit_id' => $visit->id,
            'payer_type' => $payer,
            'status' => InvoiceStatus::Open,
        ]);
    }
}
