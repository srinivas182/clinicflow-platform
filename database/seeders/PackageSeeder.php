<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domains\Platform\Enums\ProviderType;
use App\Domains\Platform\Models\Package;
use Illuminate\Database\Seeder;

/**
 * Sample packages from the prototype. Final prices are set by the super admin.
 */
class PackageSeeder extends Seeder
{
    public function run(): void
    {
        $packages = [
            ['clinic-starter', 'Clinic Starter', ProviderType::Clinic, 'Up to 3 doctors, one branch', 149000, ['doctors' => 3, 'branches' => 1, 'storage_gb' => 25, 'messages' => 200], ['front_desk', 'consults', 'billing', 'claims', 'patient_app', 'subdomain']],
            ['clinic-standard', 'Clinic Standard', ProviderType::Clinic, 'Up to 10 doctors, two branches', 299000, ['doctors' => 10, 'branches' => 2, 'storage_gb' => 100, 'messages' => 600], ['front_desk', 'consults', 'billing', 'claims', 'patient_app', 'subdomain', 'multi_doctor', 'in_house_pharmacy', 'in_house_lab', 'procedures', 'revenue_by_doctor']],
            ['clinic-pro', 'Clinic Pro', ProviderType::Clinic, 'Unlimited doctors and branches', 549000, ['doctors' => 0, 'branches' => 0, 'storage_gb' => 500, 'messages' => 1500], ['front_desk', 'consults', 'billing', 'claims', 'patient_app', 'subdomain', 'multi_doctor', 'in_house_pharmacy', 'in_house_lab', 'procedures', 'revenue_by_doctor', 'custom_domain', 'group_dashboards', 'accounting_sync', 'priority_support']],
            ['doctor-solo', 'Doctor Solo', ProviderType::IndependentDoctor, 'Your own practice and bookings', 69000, ['doctors' => 1, 'branches' => 1, 'storage_gb' => 10, 'messages' => 150], ['consults', 'billing', 'patient_app', 'subdomain']],
            ['doctor-plus', 'Doctor Plus', ProviderType::IndependentDoctor, 'Adds claims and a custom domain', 119000, ['doctors' => 1, 'branches' => 1, 'storage_gb' => 25, 'messages' => 300], ['consults', 'billing', 'claims', 'patient_app', 'subdomain', 'custom_domain', 'accounting_sync']],
            ['pharmacy', 'Pharmacy', ProviderType::Pharmacy, 'Network e-scripts, dispensing and delivery', 119000, ['branches' => 1, 'storage_gb' => 25, 'messages' => 300], ['e_scripts', 'dispensing', 'stock', 'delivery', 'subdomain']],
            ['lab', 'Lab', ProviderType::Lab, 'Orders, samples and reports', 149000, ['branches' => 1, 'storage_gb' => 100, 'messages' => 300], ['lab_orders', 'samples', 'reports', 'home_collection', 'subdomain']],
        ];

        foreach ($packages as $i => [$code, $name, $type, $summary, $monthly, $limits, $features]) {
            Package::query()->updateOrCreate(['code' => $code], [
                'name' => $name,
                'provider_type' => $type,
                'summary' => $summary,
                'price_monthly_cents' => $monthly,
                'price_annual_cents' => (int) round($monthly * 12 * 0.85),
                'trial_days' => 30,
                'limits' => $limits,
                'features' => $features,
                'addons' => $type->canOfferTelemedicine() ? ['telemedicine', 'extra_messaging', 'custom_domain'] : ['extra_messaging', 'custom_domain'],
                'is_active' => true,
                'sort_order' => $i,
            ]);
        }
    }
}
