<?php

declare(strict_types=1);

namespace App\Domains\Platform\Models;

use App\Domains\Platform\Enums\ProviderType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

/**
 * @property int $id
 * @property string $code
 * @property string $name
 * @property ProviderType $provider_type
 * @property string|null $summary
 * @property int $price_monthly_cents
 * @property int $price_annual_cents
 * @property int $trial_days
 * @property int|null $pending_price_monthly_cents
 * @property Carbon|null $pending_price_from
 * @property array<string, int> $limits
 * @property list<string> $features
 * @property list<string> $addons
 * @property bool $is_active
 * @property int $sort_order
 */
class Package extends Model
{
    use CentralConnection;

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'provider_type' => ProviderType::class,
            'limits' => 'array',
            'features' => 'array',
            'addons' => 'array',
            'is_active' => 'boolean',
            'pending_price_from' => 'date',
        ];
    }

    public function limit(string $key): ?int
    {
        $value = $this->limits[$key] ?? null;

        return is_int($value) ? $value : null;
    }

    public const NOTICE_DAYS = 30;

    /** Features a package can include (codes used across the app), with the label admins see. */
    public const FEATURES = [
        'front_desk' => 'Front desk', 'consults' => 'Consultations', 'billing' => 'Billing', 'claims' => 'Medical aid claims',
        'patient_app' => 'Patient app', 'subdomain' => 'Own web address', 'custom_domain' => 'Custom domain', 'multi_doctor' => 'Several doctors',
        'in_house_pharmacy' => 'In-house pharmacy', 'in_house_lab' => 'In-house lab', 'procedures' => 'Procedures', 'revenue_by_doctor' => 'Revenue by doctor',
        'reports_advanced' => 'Advanced reports', 'group_dashboards' => 'Group dashboards', 'accounting_sync' => 'Accounting sync', 'priority_support' => 'Priority support',
        'api' => 'API access', 'e_scripts' => 'Network e-scripts', 'dispensing' => 'Dispensing', 'stock' => 'Stock', 'delivery' => 'Delivery',
        'lab_orders' => 'Lab orders', 'samples' => 'Samples', 'reports' => 'Lab reports', 'home_collection' => 'Home collection',
        'msg_booking' => 'Booking confirmations', 'msg_billing' => 'Payment links', 'msg_reminders' => 'Booking reminders',
        'msg_queue_alerts' => 'Queue alerts', 'msg_pharmacy' => 'Medicine-ready messages', 'msg_recalls' => 'Recalls',
    ];

    /** Limits set on the package page (0 = unlimited). Messaging allowances are set under Messaging. */
    public const LIMITS = ['doctors' => 'Doctors', 'branches' => 'Branches', 'storage_gb' => 'Storage (GB)'];

    /** Add-ons a practice on this package may buy. */
    public const ADDONS = ['telemedicine' => 'Online consults', 'whatsapp' => 'WhatsApp', 'ai_scribe' => 'AI scribe', 'extra_messaging' => 'Extra messaging', 'custom_domain' => 'Custom domain'];

    /** Monthly price for a period starting on $date: a notified price rise applies from its date. */
    public function monthlyCentsOn(\DateTimeInterface $date): int
    {
        return $this->pending_price_monthly_cents !== null && $this->pending_price_from !== null && $this->pending_price_from->lte($date)
            ? $this->pending_price_monthly_cents : $this->price_monthly_cents;
    }

    /** Annual price (15% off twelve months) for a period starting on $date. */
    public function annualCentsOn(\DateTimeInterface $date): int
    {
        return $this->monthlyCentsOn($date) === $this->price_monthly_cents ? $this->price_annual_cents : (int) round($this->monthlyCentsOn($date) * 12 * 0.85);
    }

    /** A free package has no price: no trial, no invoice, never read-only for non-payment. */
    public function isFree(): bool
    {
        return $this->price_monthly_cents === 0 && $this->price_annual_cents === 0;
    }

    public function hasFeature(string $feature): bool
    {
        return in_array($feature, $this->features, true);
    }
}
