<?php

declare(strict_types=1);

namespace App\Domains\Messaging\WhatsApp;

use App\Domains\Patients\Enums\Channel;
use App\Domains\Patients\Models\Patient;
use App\Domains\Platform\Models\Subscription;
use App\Domains\Wallet\Actions\WalletLedger;
use App\Domains\Wallet\Models\Wallet;
use App\Domains\Wallet\Support\WalletSettings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Sends a catalogue message by WhatsApp instead of SMS when every condition holds:
 * the practice has the WhatsApp add-on, a WhatsApp supplier is active, the
 * patient prefers WhatsApp and opted in, the template is approved, and the
 * practice wallet covers the message. Otherwise the caller falls back to SMS.
 */
class WhatsAppRouter
{
    public function __construct(private readonly WhatsAppClient $client, private readonly WalletLedger $wallet) {}

    public static function enabledFor(string $tenantId): bool
    {
        $s = Subscription::query()->with('package')->where('tenant_id', $tenantId)->latest('id')->first();

        return $s instanceof Subscription && in_array('whatsapp', (array) ($s->package->addons ?? []), true)
            && in_array('whatsapp', (array) ($s->getAttribute('addons') ?? []), true);
    }

    public static function priceCents(string $category): int
    {
        return (int) (WalletSettings::get("wallet.price_whatsapp_{$category}_cents") ?? match ($category) {
            'marketing' => 90, default => 35,
        });
    }

    /**
     * @param  array<string, string>  $vars
     * @param  array{category: string, placeholders: list<string>}  $entry
     */
    public function trySend(string $key, string $cell, array $vars, array $entry, ?string $relatedType, ?string $relatedId): bool
    {
        $tenant = tenant();
        if ($tenant === null || ! self::enabledFor((string) $tenant->getTenantKey())) {
            return false;
        }
        $provider = WhatsAppProvider::active();
        $template = WhatsAppTemplate::query()->where('message_key', $key)->where('status', 'approved')->first();
        $patient = Patient::query()->where('cell', $cell)->where('preferred_channel', Channel::WhatsApp->value)->whereNotNull('whatsapp_opt_in_at')->first();
        if (! $provider instanceof WhatsAppProvider || ! $template instanceof WhatsAppTemplate || ! $patient instanceof Patient) {
            return false;
        }
        if ($template->category === 'marketing' && DB::table('message_opt_outs')->whereIn('channel', ['whatsapp', 'sms'])->where('recipient', $cell)->exists()) {
            return false;
        }

        $wallet = Wallet::for((string) $tenant->getTenantKey());
        $price = self::priceCents($template->category);
        if ($wallet->availableCents() < $price) {
            return false;
        }

        $params = array_map(fn (string $v) => (string) ($vars[$v] ?? ''), $entry['placeholders']);
        if (! $this->client->sendTemplate($provider, WhatsAppClient::e164($cell), $template, $params)) {
            return false;
        }

        // Unique per message: batches (statements, recalls) send many of the same type in one second.
        $this->wallet->chargeUsage($wallet, $price, 'wa-'.(string) Str::ulid(), 'WhatsApp '.$template->category.' message: '.$key);
        DB::table('message_log')->insert([
            'channel' => 'whatsapp', 'recipient' => $cell, 'subject' => null, 'body' => "[{$key}] ".implode(' | ', $params),
            'status' => 'sent', 'units' => 1, 'related_type' => $relatedType, 'related_id' => $relatedId, 'sent_at' => now(),
        ]);

        return true;
    }
}
