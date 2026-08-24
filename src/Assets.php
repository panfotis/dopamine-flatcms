<?php

declare(strict_types=1);

namespace Dopamine\FlatCms;

use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * The CSS and JS a single render actually needs.
 *
 * One instance per render, never shared: Cms outlives a request's render (the
 * tests reuse one Cms across fifteen renderPage() calls), so a collector held
 * any longer than one render would leak one page's component CSS into the next.
 *
 * Local files are inlined — measured at ~3 KB a page, cheaper than the request
 * that would fetch them — and external URLs become real <link>/<script> tags,
 * so nothing static is ever served from this origin. CSS is minified on the
 * way in; the file on disk stays the readable copy. Emission order is four
 * tiers: external tags, lower layers' globals (the engine itself ships none —
 * its placeholder styling rides with the welcome components), component assets
 * in render order, the site layer's globals. Site last is what lets a site
 * restyle any component from its own site.css without forking the component.
 *
 * One <style>/<script> per file rather than one concatenated block: a syntax
 * error, an uncaught exception or a top-of-file @import then breaks only the
 * file that carries it, not everything emitted after it.
 */
final class Assets
{
    /** @var list<array{root: string, rel: string}> engine-layer globals (tier 2) */
    private array $cssPre = [];
    /** @var list<array{root: string, rel: string}> site-layer globals (tier 4) */
    private array $cssPost = [];
    /** @var array<string, array{root: string, rel: string}> keyed by realpath — tier 3 */
    private array $cssComponent = [];

    /** @var list<array{root: string, rel: string}> */
    private array $jsPre = [];
    /** @var list<array{root: string, rel: string}> */
    private array $jsPost = [];
    /** @var array<string, array{root: string, rel: string}> keyed by realpath */
    private array $jsComponent = [];

    /** @var array<string, array<string, mixed>> external stylesheets, keyed by URL */
    private array $extCss = [];
    /** @var array<string, array<string, mixed>> external scripts, keyed by URL */
    private array $extJs = [];

    /** @var array<string, true> component types already collected */
    private array $seen = [];

    /** @var list<string> */
    private readonly array $themeDirs;

    /**
     * @param string|list<string> $themeDirs site layer first, engine last —
     *        the same order config.paths declares them in.
     */
    public function __construct(string|array $themeDirs)
    {
        $this->themeDirs = array_values(array_filter((array) $themeDirs, 'is_string'));

        // Manifests read engine-first so the site's globals land in the last
        // tier and win the cascade. A single-layer setup is its own engine:
        // its globals are the baseline, emitted before component CSS.
        $last = count($this->themeDirs) - 1;
        foreach (array_reverse($this->themeDirs, true) as $i => $dir) {
            $site = $last > 0 && $i === 0;
            foreach ($this->manifest($dir) as $kind => $entries) {
                foreach ($entries as $entry) {
                    $this->collect($kind, $entry, $dir, $site);
                }
            }
        }
    }

    /**
     * A component's assets: <type>.css / <type>.js beside its template — the
     * file existing is the whole declaration — plus any external `assets:` from
     * its schema. Keyed by type, so five heroes on a page contribute one entry.
     *
     * @param array<string, mixed> $schema
     */
    public function component(array $schema): void
    {
        $type = (string) ($schema['type'] ?? '');
        $dir  = (string) ($schema['dir'] ?? '');
        if ($type === '' || $dir === '' || isset($this->seen[$type])) {
            return;
        }
        $this->seen[$type] = true;

        foreach (['css', 'js'] as $kind) {
            if (is_file($dir . '/' . $type . '.' . $kind)) {
                $this->addLocal($kind, $dir, $type . '.' . $kind, 'component');
            }
        }

        foreach ((array) ($schema['assets'] ?? []) as $kind => $entries) {
            if (in_array($kind, ['css', 'js'], true)) {
                foreach ((array) $entries as $entry) {
                    $this->collect((string) $kind, $entry, $dir, false, externalOnly: true);
                }
            }
        }
    }

    /**
     * One extra file for this render, from a template that knows it needs it —
     * the admin editor on the edit screen. $root is the theme layer the calling
     * template resolved from, so an engine template can never be handed a
     * site's file of the same name.
     */
    public function attachFrom(string $root, string $rel): void
    {
        $kind = str_ends_with($rel, '.js') ? 'js' : 'css';
        $this->addLocal($kind, $root, $rel, 'component');
    }

    /** Everything for <head>: preconnects, external stylesheets, inline styles. */
    public function head(): string
    {
        $out = [];

        foreach ($this->hosts() as $host) {
            $out[] = '<link rel="preconnect" href="' . htmlspecialchars($host) . '" crossorigin>';
        }
        foreach ($this->extCss as $url => $attrs) {
            $out[] = '<link rel="stylesheet" href="' . htmlspecialchars($url) . '"' . $this->attrs($attrs) . '>';
        }
        foreach ([...$this->cssPre, ...array_values($this->cssComponent), ...$this->cssPost] as $file) {
            $content = self::minifyCss($this->read($file));
            if ($content !== '') {
                $out[] = "<style>\n" . $content . "\n</style>";
            }
        }

        return implode("\n", $out);
    }

    /** Everything for the end of <body>: external scripts, then inline scripts. */
    public function foot(): string
    {
        $out = [];

        foreach ($this->extJs as $url => $attrs) {
            // defer unless explicitly async: deferred scripts finish before
            // DOMContentLoaded, which is what makes them usable from the
            // wrapped local code below.
            $mode = ($attrs['async'] ?? false) === true ? ' async' : ' defer';
            $out[] = '<script src="' . htmlspecialchars($url) . '"' . $mode . $this->attrs($attrs) . '></script>';
        }
        foreach ([...$this->jsPre, ...array_values($this->jsComponent), ...$this->jsPost] as $file) {
            $content = $this->read($file);
            if ($content !== '') {
                // In code position </script> cannot legitimately appear, and in
                // a JS string '<\/script' means the same thing — so this cannot
                // change behaviour, only close the tag-breakout hole.
                $content = str_ireplace('</script', '<\/script', $content);
                $out[] = "<script>document.addEventListener('DOMContentLoaded', function () {\n"
                    . $content . "\n});</script>";
            }
        }

        return implode("\n", $out);
    }

    // ── collection ──────────────────────────────────────────────────────────

    /** @return array{css: list<mixed>, js: list<mixed>} */
    private function manifest(string $dir): array
    {
        $file = $dir . '/theme.yml';
        $data = [];
        if (is_file($file)) {
            try {
                $data = (array) (Yaml::parseFile($file) ?? []);
            } catch (ParseException $e) {
                // Fail soft on a live site; bin/doctor is where this is loud.
                error_log('[dopamine-flatcms] assets: ' . $file . ' does not parse — ' . $e->getMessage());
            }
        }

        return [
            'css' => array_values((array) ($data['css'] ?? [])),
            'js'  => array_values((array) ($data['js'] ?? [])),
        ];
    }

    private function collect(string $kind, mixed $entry, string $root, bool $site, bool $externalOnly = false): void
    {
        if (is_array($entry)) {
            $this->addExternal($kind, $entry);

            return;
        }

        $entry = (string) $entry;
        if (str_starts_with($entry, 'https://')) {
            $this->addExternal($kind, ['url' => $entry]);

            return;
        }
        if (preg_match('#^(https?:)?//#i', $entry) === 1) {
            // http:// and protocol-relative both downgrade silently; refuse.
            error_log('[dopamine-flatcms] assets: refusing non-https URL ' . $entry);

            return;
        }
        if ($externalOnly) {
            // A component's schema declares externals only; its local files
            // need no declaration at all.
            error_log('[dopamine-flatcms] assets: local path in schema assets: ' . $entry);

            return;
        }

        $this->addLocal($kind, $root, $entry, $site ? 'post' : 'pre');
    }

    private function addLocal(string $kind, string $root, string $rel, string $tier): void
    {
        // Confined to the declaring layer's own root: a manifest is developer-
        // authored, but ../../.env inlined into a public page is not a mistake
        // to fail soft on. realpath() also refuses escape through a symlink.
        $rootReal = realpath($root);
        $real     = realpath($root . '/' . $rel);
        if ($rootReal === false || $real === false
            || str_starts_with($rel, '/') || !str_starts_with($real, $rootReal . '/')) {
            error_log('[dopamine-flatcms] assets: ' . $rel . ' does not resolve inside ' . $root);

            return;
        }

        $file = ['root' => $root, 'rel' => $rel];
        match (true) {
            $tier === 'component' && $kind === 'css' => $this->cssComponent[$real] = $file,
            $tier === 'component'                    => $this->jsComponent[$real] = $file,
            $tier === 'post' && $kind === 'css'      => $this->cssPost[] = $file,
            $tier === 'post'                         => $this->jsPost[] = $file,
            $kind === 'css'                          => $this->cssPre[] = $file,
            default                                  => $this->jsPre[] = $file,
        };
    }

    /** @param array<string, mixed> $attrs */
    private function addExternal(string $kind, array $attrs): void
    {
        $url = (string) ($attrs['url'] ?? '');
        if (!str_starts_with($url, 'https://')) {
            error_log('[dopamine-flatcms] assets: external entry without an https url');

            return;
        }

        $bucket = $kind === 'css' ? 'extCss' : 'extJs';
        if (isset($this->{$bucket}[$url])) {
            // Deduped by URL; a redeclaration with different attributes is a
            // doctor failure, and at runtime the first declaration stands.
            if ($this->{$bucket}[$url] !== $attrs) {
                error_log('[dopamine-flatcms] assets: ' . $url . ' declared twice with different attributes');
            }

            return;
        }
        $this->{$bucket}[$url] = $attrs;
    }

    // ── emission helpers ────────────────────────────────────────────────────

    /** @param array{root: string, rel: string} $file */
    private function read(array $file): string
    {
        $content = @file_get_contents($file['root'] . '/' . $file['rel']);
        if ($content === false) {
            error_log('[dopamine-flatcms] assets: unreadable ' . $file['root'] . '/' . $file['rel']);

            return '';
        }

        return trim($content);
    }

    /**
     * Whitespace and comments out of inlined CSS; the file on disk stays the
     * readable copy. Deliberately conservative: quoted strings are carried
     * through untouched, and spaces around operators are kept because
     * `calc(100% - 2rem)` and descendant combinators need them. JS is never
     * minified — without a real parser that corrupts regex literals and
     * ASI-dependent code, and the files are a few KB at most.
     */
    private static function minifyCss(string $css): string
    {
        // Comments and strings in ONE alternation — leftmost match wins, so an
        // apostrophe inside a comment can never open a string and a "/*"
        // inside a string can never open a comment. Two separate passes get
        // exactly that wrong. Comments drop; strings park verbatim behind
        // placeholders so nothing below can touch `content: "a  b"`.
        $strings = [];
        $css = (string) preg_replace_callback(
            '#/\*.*?\*/|"(?:[^"\\\\]|\\\\.)*"|\'(?:[^\'\\\\]|\\\\.)*\'#s',
            static function (array $m) use (&$strings): string {
                if (str_starts_with($m[0], '/*')) {
                    return '';
                }
                $strings[] = $m[0];

                return "\x01" . (count($strings) - 1) . "\x01";
            }, $css);
        $css = (string) preg_replace('/\s+/', ' ', $css);
        // Only around punctuation that never needs a space. Not after ':' —
        // `.a :hover` and `.a:hover` are different selectors.
        $css = (string) preg_replace('/ ?([{};,]) ?/', '$1', $css);
        $css = str_replace(';}', '}', $css);

        return trim((string) preg_replace_callback('/\x01(\d+)\x01/',
            static fn (array $m): string => $strings[(int) $m[1]], $css));
    }

    /** @param array<string, mixed> $attrs */
    private function attrs(array $attrs): string
    {
        $out = '';
        if (($attrs['integrity'] ?? '') !== '') {
            $out .= ' integrity="' . htmlspecialchars((string) $attrs['integrity']) . '"';
            // SRI on a cross-origin asset needs a CORS response or the browser
            // cannot hash it — without this the asset fails to load at all.
            $attrs['crossorigin'] ??= 'anonymous';
        }
        if (($attrs['crossorigin'] ?? '') !== '') {
            $out .= ' crossorigin="' . htmlspecialchars((string) $attrs['crossorigin']) . '"';
        }

        return $out;
    }

    /** @return list<string> unique https origins across every external entry */
    private function hosts(): array
    {
        $hosts = [];
        foreach ([...array_keys($this->extCss), ...array_keys($this->extJs)] as $url) {
            $host = parse_url($url, PHP_URL_HOST);
            if (is_string($host)) {
                $hosts['https://' . $host] = true;
            }
        }

        return array_keys($hosts);
    }
}
