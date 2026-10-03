<?php

declare(strict_types=1);

namespace App\Domains\Platform\Support\Website;

use App\Domains\Documents\Support\TemplateRenderer;
use Illuminate\Validation\ValidationException;

/**
 * Editable page sections for the platform site and provider sites.
 * Only known section types and fields are kept; text is plain text (escaped
 * when shown); links must be site-relative, https, tel: or mailto:; images
 * must be site-relative or https; rich text is sanitised.
 */
final class SiteSections
{
    public const TYPES = ['hero', 'cards', 'features', 'steps', 'split', 'faq', 'cta', 'contact', 'richtext'];

    private const TEXT_FIELDS = ['eyebrow', 'heading', 'text', 'note'];

    /**
     * @param  array<int, mixed>  $sections
     * @return list<array<string, mixed>>
     */
    public static function clean(array $sections): array
    {
        if (count($sections) > 20) {
            throw ValidationException::withMessages(['sections' => 'A page can have at most 20 sections.']);
        }

        $clean = [];
        foreach (array_values($sections) as $i => $section) {
            if (! is_array($section) || ! in_array($section['type'] ?? null, self::TYPES, true)) {
                throw ValidationException::withMessages(["sections.{$i}" => 'Unknown section type.']);
            }

            $out = ['type' => $section['type']];
            foreach (self::TEXT_FIELDS as $field) {
                if (isset($section[$field])) {
                    $out[$field] = self::text($section[$field], $field === 'text' ? 1200 : 200);
                }
            }
            if (isset($section['image'])) {
                $out['image'] = self::image($section['image'], "sections.{$i}.image");
            }
            foreach (['primary', 'secondary'] as $button) {
                if (isset($section[$button]) && is_array($section[$button]) && ($section[$button]['label'] ?? '') !== '') {
                    $out[$button] = ['label' => self::text($section[$button]['label'], 40), 'href' => self::href($section[$button]['href'] ?? '', "sections.{$i}.{$button}")];
                }
            }
            if (isset($section['bullets']) && is_array($section['bullets'])) {
                $out['bullets'] = array_values(array_map(fn ($b) => self::text($b, 200), array_slice($section['bullets'], 0, 12)));
            }
            if (isset($section['items']) && is_array($section['items'])) {
                $out['items'] = [];
                foreach (array_slice(array_values($section['items']), 0, 12) as $j => $item) {
                    if (! is_array($item)) {
                        continue;
                    }
                    $row = [];
                    foreach (['title', 'text', 'icon', 'question', 'answer'] as $f) {
                        if (isset($item[$f])) {
                            $row[$f] = self::text($item[$f], in_array($f, ['text', 'answer'], true) ? 600 : 120);
                        }
                    }
                    if (isset($item['href']) && $item['href'] !== '') {
                        $row['href'] = self::href($item['href'], "sections.{$i}.items.{$j}.href");
                    }
                    if (isset($item['image']) && $item['image'] !== '') {
                        $row['image'] = self::image($item['image'], "sections.{$i}.items.{$j}.image");
                    }
                    $out['items'][] = $row;
                }
            }
            if ($section['type'] === 'richtext') {
                $out['html'] = TemplateRenderer::sanitise(mb_substr((string) ($section['html'] ?? ''), 0, 50000));
            }

            $clean[] = $out;
        }

        return $clean;
    }

    /**
     * Replace {name}, {phone}, ... tokens so defaults stay correct when the
     * provider changes its details.
     *
     * @param  list<array<string, mixed>>  $sections
     * @param  array<string, string>  $tokens
     * @return list<array<string, mixed>>
     */
    public static function resolve(array $sections, array $tokens): array
    {
        $search = array_map(fn ($k) => '{'.$k.'}', array_keys($tokens));
        $walk = function (mixed $value) use (&$walk, $search, $tokens): mixed {
            if (is_string($value)) {
                return str_replace($search, array_values($tokens), $value);
            }
            if (is_array($value)) {
                return array_map($walk, $value);
            }

            return $value;
        };

        /** @var list<array<string, mixed>> $resolved */
        $resolved = $walk($sections);

        return $resolved;
    }

    private static function text(mixed $value, int $max): string
    {
        return mb_substr(trim(strip_tags(is_scalar($value) ? (string) $value : '')), 0, $max);
    }

    private static function href(mixed $value, string $key): string
    {
        $href = trim(is_scalar($value) ? (string) $value : '');
        if (preg_match('#^(/[^/]|/$|https://|tel:\+?[0-9 ]+$|mailto:[^\s]+$)#', $href) !== 1 && ! str_starts_with($href, 'tel:{') && ! str_starts_with($href, 'mailto:{')) {
            throw ValidationException::withMessages([$key => 'Links must start with /, https://, tel: or mailto:.']);
        }

        return $href;
    }

    private static function image(mixed $value, string $key): string
    {
        $src = trim(is_scalar($value) ? (string) $value : '');
        if ($src !== '' && preg_match('#^(/images/[a-z0-9/_-]+\.(svg|png|jpe?g|webp)|https://[^\s"\'<>]+)$#i', $src) !== 1) {
            throw ValidationException::withMessages([$key => 'Images must be a site image (/images/...) or an https:// address.']);
        }

        return $src;
    }
}
