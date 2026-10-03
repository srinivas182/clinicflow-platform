<?php

declare(strict_types=1);

namespace App\Domains\Messaging\WhatsApp;

use Illuminate\Support\Facades\Http;

/**
 * Sends approved WhatsApp templates through the active supplier and reads
 * template approval status back from Meta. Payloads follow each supplier's
 * published API; confirm in sandbox before go-live.
 */
class WhatsAppClient
{
    /**
     * @param  list<string>  $params  body variables in template order
     */
    public function sendTemplate(WhatsAppProvider $p, string $to, WhatsAppTemplate $t, array $params): bool
    {
        $response = match ($p->driver) {
            'meta' => Http::withToken($p->credential('access_token'))->acceptJson()->post("https://graph.facebook.com/v20.0/{$p->sender}/messages", [
                'messaging_product' => 'whatsapp', 'to' => ltrim($to, '+'), 'type' => 'template',
                'template' => ['name' => $t->template_name, 'language' => ['code' => $t->language],
                    'components' => [['type' => 'body', 'parameters' => array_map(fn (string $v) => ['type' => 'text', 'text' => $v], $params)]]],
            ]),
            'twilio' => Http::withBasicAuth($p->credential('account_sid'), $p->credential('auth_token'))->asForm()->acceptJson()
                ->post('https://api.twilio.com/2010-04-01/Accounts/'.rawurlencode($p->credential('account_sid')).'/Messages.json', [
                    'From' => 'whatsapp:'.$p->sender, 'To' => 'whatsapp:'.$to, 'ContentSid' => $t->template_name,
                    'ContentVariables' => (string) json_encode(array_combine(array_map('strval', range(1, max(1, count($params)))), $params === [] ? [''] : $params)),
                ]),
            'clickatell' => Http::withHeaders(['Authorization' => $p->credential('api_key')])->acceptJson()->post('https://platform.clickatell.com/v1/message', [
                'messages' => [['channel' => 'whatsapp', 'to' => ltrim($to, '+'), 'template' => ['name' => $t->template_name, 'language' => $t->language, 'parameters' => $params]]],
            ]),
            default => null,
        };

        return $response !== null && $response->successful();
    }

    /**
     * Refreshes approval status for every template from Meta (Twilio and Clickatell show it in their consoles).
     */
    public function syncMeta(WhatsAppProvider $p): int
    {
        $response = Http::withToken($p->credential('access_token'))->acceptJson()
            ->get('https://graph.facebook.com/v20.0/'.rawurlencode($p->credential('waba_id')).'/message_templates', ['limit' => 200]);
        $updated = 0;
        foreach ((array) $response->json('data', []) as $row) {
            if (! is_array($row) || ! isset($row['name'], $row['status'])) {
                continue;
            }
            $status = match (strtoupper((string) $row['status'])) {
                'APPROVED' => 'approved', 'REJECTED' => 'rejected', default => 'pending',
            };
            $updated += WhatsAppTemplate::query()->where('template_name', $row['name'])
                ->update(['status' => $status, 'rejected_reason' => $row['rejected_reason'] ?? null, 'synced_at' => now()]);
        }

        return $updated;
    }

    public static function e164(string $cell): string
    {
        $digits = preg_replace('/\D/', '', $cell) ?? '';

        return str_starts_with($digits, '0') ? '+27'.substr($digits, 1) : '+'.$digits;
    }
}
