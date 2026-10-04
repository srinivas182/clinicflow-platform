<?php

declare(strict_types=1);

namespace App\Domains\Billing\Prepaid;

use App\Domains\Billing\Actions\AddInvoiceLine;
use App\Domains\Billing\Actions\IssueCreditNote;
use App\Domains\Billing\Actions\OpenInvoice;
use App\Domains\Billing\Enums\LineKind;
use App\Domains\Billing\Models\Invoice;
use App\Domains\Billing\Models\InvoiceLine;
use App\Domains\Patients\Models\Patient;
use App\Domains\Visits\Enums\PayerType;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Prepaid packages: defined services bought in advance at a fixed price — never
 * open-ended cover (Medical Schemes Act). Each purchase is valid for three years
 * (Consumer Protection Act s63). Using a service issues a credit note against the
 * matching invoice line, so revenue and VAT stay correct.
 */
class PrepaidPackages
{
    public const VALID_YEARS = 3;

    /** Services a package can contain: consultations, or a specific procedure or lab test code. */
    public const SERVICE_PATTERN = '/^(consultation|procedure:[A-Z0-9-]{1,16}|lab:[A-Z0-9-]{1,16})$/';

    /**
     * @param  list<array{service: string, label: string, quantity: int}>  $items
     */
    public function savePackage(?int $id, string $name, ?string $description, int $priceCents, array $items, bool $active = true): int
    {
        if ($items === [] || count($items) > 20) {
            throw ValidationException::withMessages(['items' => 'A package needs between 1 and 20 services.']);
        }
        foreach ($items as $i => $item) {
            if (preg_match(self::SERVICE_PATTERN, $item['service']) !== 1 || $item['quantity'] < 1 || $item['quantity'] > 50) {
                throw ValidationException::withMessages(["items.{$i}" => 'Each service must be a consultation, procedure or lab test, with a quantity from 1 to 50.']);
            }
        }
        $row = ['name' => trim($name), 'description' => $description, 'price_cents' => $priceCents, 'items' => json_encode($items), 'active' => $active, 'updated_at' => now()];
        if ($id === null) {
            return (int) DB::table('prepaid_packages')->insertGetId($row + ['created_at' => now()]);
        }
        DB::table('prepaid_packages')->where('id', $id)->update($row);

        return $id;
    }

    /**
     * Creates the invoice for the package; the package becomes active once the invoice is paid in full.
     */
    public function sell(Patient $patient, int $packageId): string
    {
        $package = DB::table('prepaid_packages')->where('id', $packageId)->where('active', true)->first();
        if ($package === null) {
            throw ValidationException::withMessages(['package' => 'Choose an available package.']);
        }

        return DB::transaction(function () use ($patient, $package): string {
            $invoice = app(OpenInvoice::class)->forPatient($patient->id, PayerType::Cash);
            app(AddInvoiceLine::class)->handle($invoice, LineKind::Other, 'Prepaid package: '.$package->name, (int) $package->price_cents, 1, 'PKG-'.$package->id);
            $remaining = [];
            foreach ((array) json_decode((string) $package->items, true) as $item) {
                $remaining[$item['service']] = ($remaining[$item['service']] ?? 0) + (int) $item['quantity'];
            }
            $id = strtolower((string) Str::ulid());
            DB::table('patient_packages')->insert(['id' => $id, 'patient_id' => $patient->id, 'prepaid_package_id' => $package->id, 'invoice_id' => $invoice->id,
                'price_cents' => $package->price_cents, 'remaining' => json_encode($remaining), 'status' => 'pending', 'created_at' => now(), 'updated_at' => now()]);

            return $invoice->id;
        });
    }

    /**
     * Called when a payment succeeds: activates packages whose invoice is now paid in full.
     */
    public function activatePaid(string $invoiceId): void
    {
        $invoice = Invoice::query()->find($invoiceId);
        if (! $invoice instanceof Invoice || ! DB::table('patient_packages')->where('invoice_id', $invoiceId)->where('status', 'pending')->exists()) {
            return;
        }
        // The payment event fires before the invoice totals are updated: recalculate from the payments first.
        $invoice->recalculate();
        if ($invoice->balanceCents() > 0) {
            return;
        }
        DB::table('patient_packages')->where('invoice_id', $invoiceId)->where('status', 'pending')
            ->update(['status' => 'active', 'activated_at' => now(), 'expires_at' => now()->addYears(self::VALID_YEARS), 'updated_at' => now()]);
    }

    /**
     * @return array<string, int> service => quantity left, for an active package
     */
    public static function remaining(object $patientPackage): array
    {
        $raw = property_exists($patientPackage, 'remaining') ? $patientPackage->remaining : '[]';

        return array_map('intval', (array) json_decode(is_string($raw) ? $raw : '[]', true));
    }

    public static function serviceFor(InvoiceLine $line): ?string
    {
        return match ($line->kind) {
            LineKind::Consultation => 'consultation',
            LineKind::Procedure => $line->code === null ? null : 'procedure:'.strtoupper($line->code),
            LineKind::Lab => $line->code === null ? null : 'lab:'.strtoupper($line->code),
            default => null,
        };
    }

    /**
     * Uses one service from the package against an unpaid invoice line of the same patient.
     */
    public function redeem(string $patientPackageId, InvoiceLine $line, ?int $by): void
    {
        DB::transaction(function () use ($patientPackageId, $line, $by): void {
            $pp = DB::table('patient_packages')->where('id', $patientPackageId)->lockForUpdate()->first();
            $invoice = Invoice::query()->findOrFail($line->invoice_id);
            $service = self::serviceFor($line);
            if ($pp === null || $pp->status !== 'active' || now()->gt($pp->expires_at)) {
                throw ValidationException::withMessages(['package' => 'This package is not active.']);
            }
            if ($pp->patient_id !== $invoice->patient_id) {
                throw ValidationException::withMessages(['package' => 'The package belongs to another patient.']);
            }
            $left = self::remaining($pp);
            if ($service === null || ($left[$service] ?? 0) < 1) {
                throw ValidationException::withMessages(['package' => 'The package has no '.($service ?? 'matching service').' left for this line.']);
            }
            if (DB::table('package_redemptions')->where('invoice_line_id', $line->id)->exists()) {
                throw ValidationException::withMessages(['package' => 'A package was already used for this line.']);
            }
            $amount = min($line->total_cents, $invoice->balanceCents());
            if ($amount <= 0) {
                throw ValidationException::withMessages(['package' => 'Nothing is owed on this invoice.']);
            }
            app(IssueCreditNote::class)->handle($invoice, $amount, 'Prepaid package used: '.$line->description);
            $left[$service]--;
            DB::table('package_redemptions')->insert(['patient_package_id' => $pp->id, 'invoice_line_id' => $line->id, 'service' => $service, 'amount_cents' => $amount, 'by' => $by, 'created_at' => now()]);
            DB::table('patient_packages')->where('id', $pp->id)->update([
                'remaining' => json_encode($left), 'status' => array_sum($left) === 0 ? 'used' : 'active', 'updated_at' => now(),
            ]);
        });
    }

    public function expire(): int
    {
        return DB::table('patient_packages')->where('status', 'active')->where('expires_at', '<', now())->update(['status' => 'expired', 'updated_at' => now()]);
    }
}
