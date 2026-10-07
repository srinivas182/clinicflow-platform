<?php

declare(strict_types=1);

namespace App\Domains\Documents\Support;

use Dom\Comment;
use Dom\Element;
use Dom\HTMLDocument;
use Dom\Node;
use Dom\Text;

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
    /** Tags kept as they are (attributes still filtered). */
    private const ALLOWED_TAGS = ['p', 'br', 'hr', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'strong', 'b', 'em', 'i', 'u', 's', 'small', 'sub', 'sup', 'span', 'div',
        'blockquote', 'pre', 'code', 'ul', 'ol', 'li', 'a', 'img', 'table', 'thead', 'tbody', 'tfoot', 'tr', 'th', 'td', 'caption', 'figure', 'figcaption',
        'section', 'article', 'header', 'footer'];

    /** Tags removed together with everything inside them. */
    private const DROPPED_TAGS = ['script', 'style', 'iframe', 'frame', 'frameset', 'object', 'embed', 'applet', 'link', 'meta', 'base', 'form', 'input',
        'button', 'textarea', 'select', 'option', 'noscript', 'template', 'svg', 'math', 'audio', 'video', 'source', 'track', 'canvas', 'dialog', 'title', 'head'];

    /** Attributes allowed on any kept tag, plus per-tag extras. */
    private const ALLOWED_ATTRIBUTES = ['*' => ['class', 'title', 'dir', 'lang'], 'a' => ['href', 'target', 'rel'], 'img' => ['src', 'alt', 'width', 'height'],
        'td' => ['colspan', 'rowspan'], 'th' => ['colspan', 'rowspan'], 'ol' => ['start']];

    /**
     * Allowlist sanitiser (HTML5 parser): keeps known-safe tags and attributes, checks every link and image
     * address, and drops everything else — scripts, event handlers, styles, frames, forms, SVG/MathML,
     * remote images and javascript:/data: links. {{#list}}…{{/list}} blocks are kept intact, including inside tables.
     */
    public static function sanitise(string $html): string
    {
        $html = (string) preg_replace('#\{\{([\#/])([A-Za-z_.]+)\}\}#', '<!--cf-list:$1$2-->', $html);
        $doc = HTMLDocument::createFromString('<!DOCTYPE html><html><body><div id="cf-root">'.$html.'</div></body></html>', LIBXML_NOERROR);
        $root = $doc->getElementById('cf-root');
        if ($root === null) {
            return '';
        }
        self::cleanChildren($root);
        $out = '';
        foreach (iterator_to_array($root->childNodes) as $child) {
            $out .= $doc->saveHtml($child);
        }

        return (string) preg_replace('#<!--cf-list:([\#/])([A-Za-z_.]+)-->#', '{{$1$2}}', $out);
    }

    private static function cleanChildren(Node $parent): void
    {
        foreach (iterator_to_array($parent->childNodes) as $node) {
            self::cleanNode($node);
        }
    }

    private static function cleanNode(Node $node): void
    {
        if ($node instanceof Comment) {
            if (preg_match('#^cf-list:[\#/][A-Za-z_.]+$#', (string) $node->data) !== 1) {
                $node->remove();
            }

            return;
        }
        if (! $node instanceof Element) {
            if (! $node instanceof Text) {
                $node->parentNode?->removeChild($node);
            }

            return;
        }
        $tag = strtolower($node->localName);
        if ($node->namespaceURI !== 'http://www.w3.org/1999/xhtml' || in_array($tag, self::DROPPED_TAGS, true)) {
            $node->remove();

            return;
        }
        if (! in_array($tag, self::ALLOWED_TAGS, true)) {
            // Unknown tag: keep its contents, drop the tag itself.
            $children = iterator_to_array($node->childNodes);
            foreach ($children as $child) {
                $node->parentNode?->insertBefore($child, $node);
            }
            $node->remove();
            foreach ($children as $child) {
                self::cleanNode($child);
            }

            return;
        }
        $allowed = array_merge(self::ALLOWED_ATTRIBUTES['*'], self::ALLOWED_ATTRIBUTES[$tag] ?? []);
        foreach (iterator_to_array($node->attributes) as $attr) {
            $name = strtolower($attr->name);
            if (! in_array($name, $allowed, true)) {
                $node->removeAttribute($attr->name);
            }
        }
        if ($tag === 'a') {
            $href = $node->getAttribute('href');
            if ($href !== null && ! self::safeUrl($href, false)) {
                $node->setAttribute('href', '#');
            }
            if ($node->hasAttribute('target')) {
                $node->setAttribute('target', '_blank');
                $node->setAttribute('rel', 'noopener noreferrer');
            }
        }
        if ($tag === 'img') {
            $src = $node->getAttribute('src');
            if ($src === null || ! self::safeUrl($src, true)) {
                $node->remove();

                return;
            }
        }
        self::cleanChildren($node);
    }

    /** Links: relative, https/http, mailto, tel. Images: relative or embedded image data only (no remote images). */
    private static function safeUrl(string $url, bool $image): bool
    {
        $url = strtolower((string) preg_replace('/[\x00-\x20\x7F]+/', '', $url));
        if ($url === '' || str_starts_with($url, '/') && ! str_starts_with($url, '//') || str_starts_with($url, '#') || str_starts_with($url, '?')) {
            return true;
        }
        if ($image) {
            return preg_match('#^data:image/(png|jpe?g|gif|webp);base64,[a-z0-9+/=]+$#', $url) === 1 || preg_match('#^[a-z0-9._-]+(/[a-z0-9._-]+)*$#', $url) === 1;
        }
        if (preg_match('#^(https?:|mailto:|tel:)#', $url) === 1) {
            return true;
        }

        // A relative path has no scheme (no ":" before the first "/", "?" or "#").
        return preg_match('#^[^:/?\#]+(?:[/?\#]|$)#', $url) === 1 && ! str_contains(explode('/', $url)[0], ':');
    }

    /**
     * Resolve a dotted merge-field path (e.g. `patient.name`) against the data.
     *
     * @param  array<string, mixed>  $data
     */
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
