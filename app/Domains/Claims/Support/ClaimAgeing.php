<?php

declare(strict_types=1);

namespace App\Domains\Claims\Support;

use App\Domains\Claims\Models\Claim;

/**
 * Unpaid claim value by age since submission.
 */
final class ClaimAgeing
{
    /**
     * @return array{'0-30': int, '31-60': int, '61-90': int, '90+': int}
     */
    public static function buckets(): array
    {
        $buckets = ['0-30' => 0, '31-60' => 0, '61-90' => 0, '90+' => 0];

        Claim::query()->whereIn('status', ['submitted', 'accepted', 'rejected'])->whereNotNull('submitted_at')->get(['total_cents', 'submitted_at'])
            ->each(function (Claim $c) use (&$buckets): void {
                $days = (int) $c->submitted_at?->diffInDays(now());
                $key = match (true) {
                    $days <= 30 => '0-30',
                    $days <= 60 => '31-60',
                    $days <= 90 => '61-90',
                    default => '90+',
                };
                $buckets[$key] += $c->total_cents;
            });

        return $buckets;
    }
}
