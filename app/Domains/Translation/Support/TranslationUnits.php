<?php

declare(strict_types=1);

namespace App\Domains\Translation\Support;

use App\Domains\Messaging\Models\MessageTemplate;
use App\Domains\Messaging\Support\MessageCatalogue;

/**
 * Everything that can be translated, with its current English, placeholders, where it appears,
 * and whether it is medical wording (needing a clinical approval too). Units are named
 * "message:<key>:<part>" where part is sms, subject or email.
 */
final class TranslationUnits
{
    /** Patient messages whose wording is medical: a clinical reviewer must also approve them. */
    public const MEDICAL = ['lab.results_ready', 'pharmacy.ready', 'recall.reminder', 'queue.next'];

    /**
     * @return array<string, array{source: string, placeholders: list<string>, context: string, medical: bool, sms: bool}>
     */
    public static function all(): array
    {
        $units = [];
        // The current English: wording edited by the platform admin, else the built-in default.
        $english = MessageTemplate::query()->where('language', 'en')->get()->keyBy(fn ($t) => $t->key.':'.$t->channel);
        foreach (MessageCatalogue::all() as $key => $entry) {
            $sms = $english->get("{$key}:sms");
            $email = $english->get("{$key}:email");
            $entry['sms'] = $sms !== null ? (string) $sms->body : $entry['sms'];
            $entry['subject'] = $email !== null && $email->subject !== null ? (string) $email->subject : $entry['subject'];
            $entry['email'] = $email !== null ? (string) $email->body : $entry['email'];
            $where = $entry['label'];
            $medical = in_array($key, self::MEDICAL, true);
            foreach (['sms' => 'SMS', 'subject' => 'Email subject', 'email' => 'Email'] as $part => $label) {
                $text = (string) ($entry[$part] ?? '');
                if ($text === '' || ($part !== 'subject' && ! in_array($part === 'sms' ? 'sms' : 'email', $entry['channels'], true))) {
                    continue;
                }
                $units["message:{$key}:{$part}"] = ['source' => $text, 'placeholders' => $entry['placeholders'], 'context' => "{$label} — {$where}",
                    'medical' => $medical, 'sms' => $part === 'sms'];
            }
        }

        foreach (PortalText::STRINGS as $key => $text) {
            $page = ucfirst((string) explode('.', $key)[1]);
            preg_match_all('/\{\{\s*([a-z_]+)\s*\}\}/', $text, $m);
            $units["ui:{$key}"] = ['source' => $text, 'placeholders' => array_values(array_unique($m[1])), 'context' => "Patient portal — {$page} page", 'medical' => PortalText::medical($key), 'sms' => false];
        }

        return $units;
    }

    /**
     * Placeholder problems: ones missing from the translation, and unknown ones added.
     *
     * @return array{missing: list<string>, unknown: list<string>}
     */
    public static function placeholderProblems(string $source, string $translation): array
    {
        $names = fn (string $t): array => preg_match_all('/\{\{\s*([a-z_]+)\s*\}\}/', $t, $m) ? array_values(array_unique($m[1])) : [];
        $need = $names($source);
        $have = $names($translation);

        return ['missing' => array_values(array_diff($need, $have)), 'unknown' => array_values(array_diff($have, $need))];
    }
}
