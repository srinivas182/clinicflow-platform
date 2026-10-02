<?php

declare(strict_types=1);

namespace App\Domains\Platform\Http\Controllers;

use App\Domains\Platform\Models\Package;
use App\Http\Controllers\Controller;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Public pricing page, read live from the package builder.
 */
class PricingController extends Controller
{
    public function __invoke(): Response
    {
        return Inertia::render('Public/Pricing', ['packages' => self::packages()]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function packages(): array
    {
        return Package::query()->where('is_active', true)->orderBy('sort_order')->get()
            ->map(fn (Package $p): array => [
                'id' => $p->id,
                'code' => $p->code,
                'name' => $p->name,
                'providerType' => $p->provider_type->value,
                'summary' => $p->summary,
                'priceMonthly' => $p->price_monthly_cents / 100,
                'priceAnnual' => $p->price_annual_cents / 100,
                'trialDays' => $p->trial_days,
                'features' => $p->features,
            ])->values()->all();
    }
}
