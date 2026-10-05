<?php

declare(strict_types=1);

namespace App\Domains\Messaging\Actions;

use App\Domains\Messaging\Contracts\MessageSender;
use App\Domains\Messaging\Support\GatewayMessageSender;
use App\Domains\Messaging\Support\MessageCatalogue;
use App\Domains\Messaging\Support\MessagingUsage;
use App\Domains\Messaging\Support\TemplateResolver;
use App\Domains\Messaging\WhatsApp\WhatsAppRouter;
use App\Domains\Platform\Branding\Brands;
use App\Domains\Platform\Models\Provider;
use App\Domains\Platform\Models\Setting;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Sends a provider's message from the platform's SMS/email accounts: checks
 * the message is included in the provider's package, honours marketing
 * opt-outs, uses the provider's from-name and reply-to, logs it and counts it
 * against the provider's allowance.
 */
class SendMessage
{
    public function __construct(private readonly MessageSender $sender) {}

    /**
     * @param  array<string, string>  $vars
     */
    public function template(string $key, string $channel, string $recipient, array $vars = [], string $language = 'en', ?string $relatedType = null, ?string $relatedId = null): bool
    {
        $entry = MessageCatalogue::get($key);
        if ($entry === null || ! in_array($channel, $entry['channels'], true)) {
            throw new InvalidArgumentException("Unknown message {$key} for {$channel}.");
        }

        $provider = tenant();
        $tenantId = $provider === null ? null : (string) $provider->getTenantKey();

        if ($entry['feature'] !== null && $tenantId !== null) {
            $package = MessagingUsage::packageFor($tenantId);
            if ($package === null || ! $package->hasFeature($entry['feature'])) {
                $this->log($channel, $recipient, null, "[{$key}]", 'not_in_package', 0, $relatedType, $relatedId);

                return false;
            }
        }

        if ($entry['category'] === 'marketing' && DB::table('message_opt_outs')->where(['channel' => $channel, 'recipient' => $recipient])->exists()) {
            $this->log($channel, $recipient, null, "[{$key}]", 'opted_out', 0, $relatedType, $relatedId);

            return false;
        }

        $vars['practice'] ??= $provider instanceof Provider ? (string) Setting::get('messaging', 'from_name', $provider->name) : 'Clinic Flow';

        // Patients who chose WhatsApp (and opted in) get it there when the practice has the add-on; otherwise SMS as before.
        if ($channel === 'sms' && app(WhatsAppRouter::class)->trySend($key, $recipient, $vars, $entry, $relatedType, $relatedId)) {
            return true;
        }

        $template = TemplateResolver::resolve($key, $channel, $language);
        $subject = $template['subject'] === null ? null : MessageCatalogue::render($template['subject'], $vars);

        return $this->handle($channel, $recipient, MessageCatalogue::render($template['body'], $vars), $subject, $relatedType, $relatedId);
    }

    public function handle(string $channel, string $recipient, string $body, ?string $subject = null, ?string $relatedType = null, ?string $relatedId = null): bool
    {
        if (! in_array($channel, ['sms', 'email'], true)) {
            throw new InvalidArgumentException("Unknown channel {$channel}.");
        }

        $provider = tenant();
        if ($this->sender instanceof GatewayMessageSender) {
            $this->sender->fromName = $provider instanceof Provider ? (string) Setting::get('messaging', 'from_name', $provider->name) : 'Clinic Flow';
            $reply = $provider instanceof Provider ? Setting::get('messaging', 'reply_to') : null;
            $this->sender->replyTo = is_string($reply) && $reply !== '' ? $reply : null;
            $brandSender = app(Brands::class)->senderFor($provider instanceof Provider ? $provider : null);
            $this->sender->fromEmail = $brandSender['email'];
            $this->sender->smsSender = $brandSender['sms'];
        }

        $ok = $this->sender->send($channel, $recipient, $subject, $body);
        $units = MessagingUsage::units($channel, $body);
        $this->log($channel, $recipient, $subject, $body, $ok ? 'sent' : 'failed', $units, $relatedType, $relatedId);

        if ($ok && $provider !== null) {
            MessagingUsage::record((string) $provider->getTenantKey(), $units, $channel);
        }

        return $ok;
    }

    private function log(string $channel, string $recipient, ?string $subject, string $body, string $status, int $units, ?string $relatedType, ?string $relatedId): void
    {
        if (tenant() === null) {
            return;
        }

        DB::table('message_log')->insert([
            'channel' => $channel, 'recipient' => $recipient, 'subject' => $subject, 'body' => $body,
            'status' => $status, 'units' => $units, 'related_type' => $relatedType, 'related_id' => $relatedId, 'sent_at' => now(),
        ]);
    }
}
