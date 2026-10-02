<?php

declare(strict_types=1);

namespace App\Domains\Documents\Support;

/**
 * Safe merge-field renderer for provider-edited templates. Templates are never
 * executed as Blade/PHP: only {{ dotted.keys }} are replaced (HTML-escaped) and
 * {{#list}}…{{/list}} repeats a block for each row.
 */
final class TemplateRenderer
{
    /**
     * @param  array<string, mixed>  $data
     */
    public static function render(string $template, array $data): string
    {
        $template = (string) preg_replace_callback(
            '/\{\{#\s*([a-z0-9_.]+)\s*\}\}(.*?)\{\{\/\s*\1\s*\}\}/is',
            function (array $m) use ($data): string {
                $rows = self::lookup($data, $m[1]);
                if (! is_array($rows)) {
                    return '';
                }

                return implode('', array_map(
                    fn ($row) => self::render($m[2], is_array($row) ? array_merge($data, ['item' => $row]) : $data),
                    $rows,
                ));
            },
            $template,
        );

        return (string) preg_replace_callback(
            '/\{\{\s*([a-z0-9_.]+)\s*\}\}/i',
            function (array $m) use ($data): string {
                $value = self::lookup($data, $m[1]);

                return is_scalar($value) ? htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : '';
            },
            $template,
        );
    }

    /**
     * Strip anything that could run code or load remote content from a template body.
     */
    public static function sanitise(string $html): string
    {
        $html = (string) preg_replace('#<(script|iframe|object|embed|link|meta)\b[^>]*>.*?</\1>#is', '', $html);
        $html = (string) preg_replace('#<(script|iframe|object|embed|link|meta)\b[^>]*/?>#is', '', $html);
        $html = (string) preg_replace('/\son\w+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $html);
        $html = (string) preg_replace('/(href|src)\s*=\s*("|\')\s*(javascript|https?):[^"\']*\2/i', '$1=$2#$2', $html);

        return $html;
    }

    private static function lookup(array $data, string $path): mixed
    {
        $value = $data;
        foreach (explode('.', $path) as $key) {
            if (! is_array($value) || ! array_key_exists($key, $value)) {
                return null;
            }
            $value = $value[$key];
        }

        return $value;
    }
}
