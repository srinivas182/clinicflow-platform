<?php

declare(strict_types=1);

namespace App\Domains\Documents\Support;

/**
 * Document types a provider can brand. Each has a locked legal block the
 * provider cannot remove, and sample data for previews.
 */
enum DocumentType: string
{
    case Invoice = 'invoice';
    case Prescription = 'prescription';
    case SickNote = 'sick_note';
    case Referral = 'referral';

    public function label(): string
    {
        return match ($this) {
            self::Invoice => 'Tax invoice',
            self::Prescription => 'Prescription',
            self::SickNote => 'Sick note',
            self::Referral => 'Referral letter',
        };
    }

    /**
     * Required content appended after the provider's body (cannot be edited away).
     */
    public function legalBlock(): string
    {
        return match ($this) {
            self::Invoice => '<div class="legal"><b>Tax invoice</b> {{ invoice.number }} · Date {{ invoice.date }}<br>'
                .'Supplier: {{ practice.name }} · VAT no {{ practice.vat_number }} · BHF {{ practice.bhf }}<br>'
                .'Recipient: {{ patient.name }} · Total {{ invoice.total }} (incl. VAT where applicable)</div>',
            self::Prescription => '<div class="legal">Prescriber: {{ doctor.name }} · HPCSA {{ doctor.hpcsa }} · {{ practice.name }}, {{ practice.address }}<br>'
                .'Patient: {{ patient.name }} · Age {{ patient.age }} · Date {{ document.date }}<br>'
                .'Signed electronically (advanced electronic signature) {{ document.signed_at }}</div>',
            self::SickNote => '<div class="legal">Issued by {{ doctor.name }} · HPCSA {{ doctor.hpcsa }} · {{ practice.name }} · {{ document.date }}</div>',
            self::Referral => '<div class="legal">Referring practitioner: {{ doctor.name }} · HPCSA {{ doctor.hpcsa }} · {{ practice.name }} · {{ document.date }}</div>',
        };
    }

    /**
     * @return array<string, mixed>
     */
    public function sampleData(): array
    {
        return [
            'practice' => ['name' => 'Sunrise Medical Centre', 'address' => '1458 Vilakazi St, Orlando West', 'phone' => '011 555 0190', 'bhf' => '0123456', 'vat_number' => '4520198334', 'colour' => '#0F7C74'],
            'patient' => ['name' => 'Thandi Mokoena', 'age' => '38', 'id_masked' => '••••••••• 0835', 'medical_aid' => 'Discovery Health'],
            'doctor' => ['name' => 'Dr Kagiso Mokoena', 'hpcsa' => 'MP 0654321'],
            'document' => ['date' => now()->format('j F Y'), 'signed_at' => now()->format('j F Y H:i'), 'days' => '3', 'reason' => 'Acute illness'],
            'invoice' => ['number' => 'INV-2026-000123', 'date' => now()->format('j F Y'), 'total' => 'R572.00', 'paid' => 'R572.00', 'balance' => 'R0.00'],
            'lines' => [
                ['code' => '0190', 'description' => 'GP consultation', 'quantity' => '1', 'total' => 'R520.00'],
                ['code' => '—', 'description' => 'Sick note', 'quantity' => '1', 'total' => 'R52.00'],
            ],
        ];
    }
}
