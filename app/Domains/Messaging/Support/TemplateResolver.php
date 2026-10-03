<?php

declare(strict_types=1);

namespace App\Domains\Messaging\Support;

use App\Domains\Messaging\Models\MessageTemplate;
use App\Domains\Messaging\Models\MessageTemplateOverride;

/**
 * Wording for a message: the provider's override (if the message is
 * editable), else the platform default in the language, else English,
 * else the built-in text.
 */
final class TemplateResolver
{
    /**
     * @return array{subject: ?string, body: string}
     */
    public static function resolve(string $key, string $channel, string $language = 'en', bool $useOverrides = true): array
    {
        $entry = MessageCatalogue::get($key);
        $languages = array_values(array_unique([$language, 'en']));

        if ($useOverrides && ($entry['editable'] ?? false) && tenant() !== null) {
            foreach ($languages as $lang) {
                $o = MessageTemplateOverride::query()->where(['key' => $key, 'channel' => $channel, 'language' => $lang])->first();
                if ($o instanceof MessageTemplateOverride) {
                    return ['subject' => $o->subject, 'body' => $o->body];
                }
            }
        }

        foreach ($languages as $lang) {
            $t = MessageTemplate::query()->where(['key' => $key, 'channel' => $channel, 'language' => $lang])->first();
            if ($t instanceof MessageTemplate) {
                return ['subject' => $t->subject, 'body' => $t->body];
            }
        }

        return $channel === 'email'
            ? ['subject' => $entry['subject'] ?? null, 'body' => (string) ($entry['email'] ?? '')]
            : ['subject' => null, 'body' => (string) ($entry['sms'] ?? '')];
    }
}
