<?php

declare(strict_types=1);

namespace Dopamine\FlatCms;

use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerAction;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;

/**
 * The five and a half field types that cover a brochure site.
 *
 * Every value the client submits goes through sanitise() before it is written
 * to disk. In particular richtext is whitelisted down to a handful of inline
 * tags, so a paste from Word cannot smuggle <style>, <font> or a <script> into
 * the page and wreck the design.
 */
final class Fields
{
    public const TYPES = ['text', 'textarea', 'richtext', 'image', 'link', 'select'];

    /** Tags a client is allowed to produce in a richtext field. */
    private const ALLOWED_TAGS = ['p', 'br', 'strong', 'b', 'em', 'i', 'u', 'ul', 'ol', 'li'];

    /**
     * Elements whose text content is code rather than prose, and which the
     * sanitiser will not remove for us — see rich().
     */
    private const CODE_ELEMENTS = ['style', 'script', 'template', 'noscript'];

    /**
     * Hard ceiling on a richtext value, independent of any `max` in the schema.
     * Without it an 8 MB paste is stored and then copied into ten revisions.
     */
    private const RICHTEXT_LIMIT = 100_000;

    /**
     * @param array<string, mixed> $def     Field definition from schema.yml
     * @param array<string, mixed> $context Runtime constraints; `media_bases`
     *                                      lists the prefixes an image src may
     *                                      use. Empty means "reject everything
     *                                      that is not a relative upload path".
     */
    public static function sanitise(array $def, mixed $raw, array $context = []): string|bool
    {
        $type = (string) ($def['type'] ?? 'text');
        $value = is_scalar($raw) ? (string) $raw : '';

        return match ($type) {
            'textarea' => self::plain($value, $def['max'] ?? null, true),
            'richtext' => self::rich($value),
            'image'    => self::mediaPath($value, (array) ($context['media_bases'] ?? [])),
            'link'     => self::link($value),
            'select'   => self::select($def, $value),
            'boolean'  => self::boolean($raw),
            default    => self::plain($value, $def['max'] ?? null, false),
        };
    }

    private static function plain(string $v, mixed $max, bool $multiline): string
    {
        $v = str_replace(["\r\n", "\r"], "\n", trim($v));
        $v = $multiline
            ? preg_replace('/\n{3,}/', "\n\n", strip_tags($v))
            : trim(preg_replace('/\s+/u', ' ', strip_tags($v)));

        $v ??= '';
        if (is_int($max) && $max > 0 && mb_strlen($v) > $max) {
            $v = rtrim(mb_substr($v, 0, $max));
        }

        return $v;
    }

    private static function boolean(mixed $raw): bool
    {
        if (is_bool($raw)) {
            return $raw;
        }

        return in_array(strtolower(trim((string) (is_scalar($raw) ? $raw : ''))), ['1', 'true', 'yes', 'on'], true);
    }

    /**
     * The allowlist, built once. Everything not named here is removed as
     * markup; its text survives, which is what `strip_tags` used to do and
     * what a paste from Word or Google Docs depends on — a Docs paste wraps
     * the whole selection in <b><span>, and dropping unknown elements with
     * their children would silently delete the client's text.
     */
    private static function sanitizer(): HtmlSanitizer
    {
        static $sanitizer = null;

        return $sanitizer ??= new HtmlSanitizer(
            array_reduce(
                self::ALLOWED_TAGS,
                static fn (HtmlSanitizerConfig $c, string $tag): HtmlSanitizerConfig => $c->allowElement($tag),
                (new HtmlSanitizerConfig())
                    ->defaultAction(HtmlSanitizerAction::Block)
                    ->allowElement('a', ['href'])
                    ->allowLinkSchemes(['http', 'https', 'mailto', 'tel'])
                    ->allowRelativeLinks()
                    // We already cut the value to RICHTEXT_LIMIT *characters*;
                    // this is the same ceiling in bytes, so it can only ever
                    // fire on input our own guard failed to cut. It matters
                    // that it never fires: on overflow the sanitiser returns an
                    // empty string, which would silently erase the field.
                    ->withMaxInputLength(self::RICHTEXT_LIMIT * 4)
            )
        );
    }

    private static function rich(string $v): string
    {
        if (mb_strlen($v) > self::RICHTEXT_LIMIT) {
            $v = mb_substr($v, 0, self::RICHTEXT_LIMIT);
        }

        // Both the sanitiser and the DOM parser require valid UTF-8. Truncation
        // above cuts on a character boundary, so this only rejects input that
        // arrived broken.
        if (preg_match('//u', $v) !== 1) {
            return '';
        }

        // <style> is classified as a <head> element by the sanitiser, so in body
        // context it is unwrapped and its CSS is emitted as visible text — and
        // no dropElement('style') can reach it. Remove the elements whose text
        // is code rather than prose before the sanitiser sees them.
        $v = self::withDom($v, static function (\Dom\HTMLDocument $doc): void {
            foreach (self::CODE_ELEMENTS as $tag) {
                foreach (iterator_to_array($doc->getElementsByTagName($tag)) as $el) {
                    $el->remove();
                }
            }
        });

        $v = self::sanitizer()->sanitize($v);

        $v = self::withDom($v, static function (\Dom\HTMLDocument $doc): void {
            // The sanitiser has already dropped hostile schemes. link() adds
            // the rules it does not have — protocol-relative and backslash
            // URLs, and upgrading a bare domain — so a client typing
            // "example.gr" gets the same result in richtext as in a link field.
            foreach ($doc->getElementsByTagName('a') as $a) {
                $href = self::link((string) $a->getAttribute('href'));
                if ($href === '') {
                    $a->removeAttribute('href');
                    continue;
                }

                // Setting an existing attribute keeps its position, so target
                // and rel are appended after href.
                $a->setAttribute('href', $href);
                if (self::isExternal($href)) {
                    $a->setAttribute('target', '_blank');
                    $a->setAttribute('rel', 'noopener noreferrer');
                }
            }

            // Drop empty paragraphs the editor leaves behind. Matching on text
            // rather than on markup also catches <p><strong></strong></p> and
            // the U+00A0 that &nbsp; decodes to.
            foreach (iterator_to_array($doc->getElementsByTagName('p')) as $p) {
                if (preg_match('/^[\s\x{00A0}]*$/u', $p->textContent) === 1) {
                    $p->remove();
                }
            }
        });

        return trim($v);
    }

    /**
     * Parse a fragment, let $mutate rewrite it, hand back the fragment.
     *
     * HTML5 parsing never fails, so there is no error path: malformed input
     * comes back as whatever the parser made of it, which is exactly the tree
     * a browser would have built.
     */
    private static function withDom(string $html, callable $mutate): string
    {
        if (trim($html) === '') {
            return '';
        }

        $doc = \Dom\HTMLDocument::createFromString(
            '<!DOCTYPE html><html><body>' . $html . '</body></html>',
            LIBXML_NOERROR
        );

        $mutate($doc);

        return $doc->getElementsByTagName('body')->item(0)?->innerHTML ?? '';
    }

    /**
     * Accepted: absolute http(s) URLs, site-relative paths, fragments, mailto
     * and tel. Everything else becomes an empty string.
     */
    private static function link(string $v): string
    {
        $v = trim(strip_tags($v));
        if ($v === '') {
            return '';
        }

        // Protocol-relative ("//evil.gr") and backslash variants ("/\evil.gr",
        // which browsers normalise to "//") look like internal paths but are
        // not. Reject before anything else.
        if (preg_match('#^[/\\\\]{2}#', $v) || preg_match('#^/[\\\\]#', $v)) {
            return '';
        }

        if (preg_match('#^(/|\#|mailto:|tel:)#i', $v)) {
            return $v;
        }

        $scheme = strtolower((string) parse_url($v, PHP_URL_SCHEME));
        if (in_array($scheme, ['http', 'https'], true) && parse_url($v, PHP_URL_HOST) !== null) {
            return $v;
        }

        // Bare domain typed by a client: make it a real URL.
        if (preg_match('#^[a-z0-9.-]+\.[a-z]{2,}(/|$)#i', $v)) {
            return 'https://' . $v;
        }

        return ''; // javascript:, data:, anything else
    }

    /** A link is external when it has a host, not merely because it starts "http". */
    private static function isExternal(string $href): bool
    {
        return parse_url($href, PHP_URL_HOST) !== null;
    }

    /**
     * An image src may only point at media we host. Without this, an editor can
     * store `https://evil.tld/x.jpg`, which Cms::imageUrl() then wraps in
     * /cdn-cgi/image/... — turning the client's own zone into an open image
     * proxy serving attacker content and burning the transformation quota.
     *
     * Public because the derivative route applies the identical guard to an
     * anonymous GET. Two copies of this rule is one copy too many.
     *
     * @param list<string> $bases
     */
    public static function mediaPath(string $v, array $bases): string
    {
        $v = trim(strip_tags($v));
        if ($v === '') {
            return '';
        }

        if (str_contains($v, '..') || str_contains($v, "\0")) {
            return '';
        }

        foreach ($bases as $base) {
            $base = (string) $base;
            if ($base === '') {
                continue;
            }
            if (str_starts_with($v, '//') || str_starts_with($v, '\\')) {
                return '';
            }
            if (str_starts_with($v, $base)) {
                return $v;
            }
        }

        return '';
    }

    /** @param array<string, mixed> $def */
    private static function select(array $def, string $v): string
    {
        $options = array_keys(self::options($def));
        return in_array($v, $options, true) ? $v : (string) ($options[0] ?? '');
    }

    /**
     * @param  array<string, mixed> $def
     * @return array<string, string>
     */
    public static function options(array $def): array
    {
        $raw = $def['options'] ?? [];
        if (!is_array($raw)) {
            return [];
        }

        $out = [];
        foreach ($raw as $k => $label) {
            // Supports both `options: [a, b]` and `options: {a: Label A}`
            if (is_int($k)) {
                $out[(string) $label] = (string) $label;
            } else {
                $out[(string) $k] = (string) $label;
            }
        }

        return $out;
    }
}
