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
            ['clinic-starter', 'Clinic Starter', ProviderType::Clinic, 'Up to 3 doctors, one branch', 149000, ['doctors' => 3, 'branches' => 1, 'storage_gb' => 25, 'sms' => 200, 'email' => 500, 'sms_overage_cents' => 35, 'email_overage_cents' => 5], ['front_desk', 'consults', 'billing', 'claims', 'patient_app', 'subdomain', 'msg_booking', 'msg_billing']],
            ['clinic-standard', 'Clinic Standard', ProviderType::Clinic, 'Up to 10 doctors, two branches', 299000, ['doctors' => 10, 'branches' => 2, 'storage_gb' => 100, 'sms' => 600, 'email' => 1500, 'sms_overage_cents' => 35, 'email_overage_cents' => 5], ['front_desk', 'consults', 'billing', 'claims', 'patient_app', 'subdomain', 'multi_doctor', 'in_house_pharmacy', 'in_house_lab', 'procedures', 'revenue_by_doctor', 'msg_booking', 'msg_billing', 'msg_reminders', 'msg_queue_alerts', 'msg_pharmacy']],
            ['clinic-pro', 'Clinic Pro', ProviderType::Clinic, 'Unlimited doctors and branches', 549000, ['doctors' => 0, 'branches' => 0, 'storage_gb' => 500, 'sms' => 1500, 'email' => 5000, 'sms_overage_cents' => 35, 'email_overage_cents' => 5], ['front_desk', 'consults', 'billing', 'claims', 'patient_app', 'subdomain', 'multi_doctor', 'in_house_pharmacy', 'in_house_lab', 'procedures', 'revenue_by_doctor', 'custom_domain', 'group_dashboards', 'accounting_sync', 'priority_support', 'msg_booking', 'msg_billing', 'msg_reminders', 'msg_queue_alerts', 'msg_pharmacy', 'msg_recalls']],
            ['doctor-solo', 'Doctor Solo', ProviderType::IndependentDoctor, 'Your own practice and bookings', 69000, ['doctors' => 1, 'branches' => 1, 'storage_gb' => 10, 'sms' => 150, 'email' => 300, 'sms_overage_cents' => 35, 'email_overage_cents' => 5], ['consults', 'billing', 'patient_app', 'subdomain', 'msg_booking', 'msg_billing']],
            ['doctor-plus', 'Doctor Plus', ProviderType::IndependentDoctor, 'Adds claims and a custom domain', 119000, ['doctors' => 1, 'branches' => 1, 'storage_gb' => 25, 'sms' => 300, 'email' => 600, 'sms_overage_cents' => 35, 'email_overage_cents' => 5], ['consults', 'billing', 'claims', 'patient_app', 'subdomain', 'custom_domain', 'accounting_sync', 'msg_booking', 'msg_billing', 'msg_reminders', 'msg_recalls']],
            ['pharmacy', 'Pharmacy', ProviderType::Pharmacy, 'Network e-scripts, dispensing and delivery', 119000, ['branches' => 1, 'storage_gb' => 25, 'sms' => 300, 'email' => 600, 'sms_overage_cents' => 35, 'email_overage_cents' => 5], ['e_scripts', 'dispensing', 'stock', 'delivery', 'subdomain', 'msg_pharmacy', 'msg_billing']],
            ['lab', 'Lab', ProviderType::Lab, 'Orders, samples and reports', 149000, ['branches' => 1, 'storage_gb' => 100, 'sms' => 300, 'email' => 600, 'sms_overage_cents' => 35, 'email_overage_cents' => 5], ['lab_orders', 'samples', 'reports', 'home_collection', 'subdomain', 'msg_billing']],
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
