<?php

declare(strict_types=1);

namespace App\Domains\Messaging\Support;

use App\Domains\Identity\Models\Membership;
use App\Domains\Messaging\Contracts\MessageSender;
use App\Domains\Messaging\Models\MessageUsage;
use App\Domains\Platform\Models\Package;
use App\Domains\Platform\Models\Provider;
use App\Domains\Platform\Models\Subscription;
use App\Models\User;

/**
 * Monthly SMS and email allowances per package ("sms" and "email" limits;
 * packages without them fall back to the combined "messages" limit). Each
 * email and each 160-character SMS segment is one message. Usage above the
 * allowance is charged on the next subscription invoice at the package's
 * per-channel overage price. Owners are emailed at 80% and 100%.
 */
final class MessagingUsage
{
    public static function units(string $channel, string $body): int
    {
        return $channel === 'sms' ? max(1, (int) ceil(mb_strlen($body) / 160)) : 1;
    }

    public static function packageFor(string $tenantId): ?Package
    {
        $subscription = Subscription::query()->with('package')->where('tenant_id', $tenantId)->latest('id')->first();

        return $subscription?->package;
    }

    public static function record(string $tenantId, int $units, string $channel = 'sms'): void
    {
        $row = MessageUsage::query()->firstOrCreate(['tenant_id' => $tenantId, 'period' => now()->format('Y-m')], ['units' => 0]);
        $row->increment('units', $units);
        $row->increment($channel === 'email' ? 'email_units' : 'sms_units', $units);

        $package = self::packageFor($tenantId);
        $limit = $package?->limit($channel);
        if ($package === null || $limit === null || $limit <= 0) {
            return;
        }

        $used = (int) $row->fresh()?->getAttribute($channel === 'email' ? 'email_units' : 'sms_units');
        $percent = (int) floor($used * 100 / $limit);
        $level = $percent >= 100 ? 100 : ($percent >= 80 ? 80 : 0);

        if ($level > (int) $row->fresh()?->getAttribute('alert_level')) {
            $row->forceFill(['alert_level' => $level])->save();
            self::alertOwners($tenantId, $level, $channel);
        }
    }

    /**
     * @return array{units: int, overage_units: int, overage_cents: int}
     */
    public static function overage(string $tenantId, string $period, Package $package): array
    {
        $row = MessageUsage::query()->where('tenant_id', $tenantId)->where('period', $period)->first();
        $units = (int) ($row?->units ?? 0);

        if ($package->limit('sms') === null && $package->limit('email') === null) {
            $over = max(0, $units - ($package->limit('messages') ?? 0));

            return ['units' => $units, 'overage_units' => $over, 'overage_cents' => $over * (int) config('clinicflow.messaging.unit_price_cents', 35)];
        }

        $sms = max(0, (int) ($row?->getAttribute('sms_units') ?? 0) - ($package->limit('sms') ?? 0));
        $email = max(0, (int) ($row?->getAttribute('email_units') ?? 0) - ($package->limit('email') ?? 0));

        return [
            'units' => $units,
            'overage_units' => $sms + $email,
            'overage_cents' => $sms * ($package->limit('sms_overage_cents') ?? 35) + $email * ($package->limit('email_overage_cents') ?? 5),
        ];
    }

    private static function alertOwners(string $tenantId, int $level, string $channel): void
    {
        $provider = Provider::query()->find($tenantId);
        $owners = User::query()->whereIn('id', Membership::query()->where('tenant_id', $tenantId)->where('role', 'owner')->pluck('user_id'))->get();
        $template = TemplateResolver::resolve('subscription.usage_alert', 'email', 'en', false);
        $vars = ['practice' => $provider instanceof Provider ? $provider->name : '', 'percent' => (string) $level, 'channel' => strtoupper($channel)];
        $sender = app(MessageSender::class);

        foreach ($owners as $owner) {
            // Platform service email: not counted against the provider's allowance.
            $sender->send('email', $owner->email, MessageCatalogue::render((string) $template['subject'], $vars), MessageCatalogue::render($template['body'], $vars));
        }
    }
}
