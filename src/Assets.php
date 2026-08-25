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
 * Local files are written to content-addressed bundles under /assets/ and
 * linked — the name is the hash of the bytes, so a bundle is immutable, is
 * cached forever by the browser and never needs a CDN purge. External URLs
 * become real <link>/<script> tags. CSS is minified on the way in; the file on
 * disk stays the readable copy. Emission order is four tiers: external tags,
 * lower layers' globals (the engine itself ships none), component assets in
 * render order, the site layer's globals. Site last is what lets a site
 * restyle any component from its own site.css without forking the component.
 *
 * With no writable target — the panel, or a misconfigured box — every tier
 * falls back to being inlined instead, which is what this class did before
 * bundles existed and remains a fully supported way to run.
 *
 * The two contracts bundling changes, both enforced by bin/doctor:
 *  - CSS resolves relative url() against /assets/css/, not the page, so
 *    references must be root-relative or absolute; @import and @charset are
 *    position-sensitive and refused outright.
 *  - Concatenated JS shares one parse: a syntax error breaks the whole bundle,
 *    not one file. Per-file DOMContentLoaded wrappers still isolate runtime
 *    errors and scope, and files are joined with "\n;\n" so a missing trailing
 *    semicolon cannot merge two statements.
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

    /** Where bundles are written, or null to inline (the panel, and tests). */
    private readonly ?string $publicAssets;

    /** Set once a write fails: the rest of this render inlines. */
    private bool $failed = false;

    /**
     * @param string|list<string> $themeDirs site layer first, engine last —
     *        the same order config.paths declares them in.
     * @param string|null $publicAssets directory behind /assets/; null inlines.
     */
    public function __construct(string|array $themeDirs, ?string $publicAssets = null)
    {
        $this->themeDirs = array_values(array_filter((array) $themeDirs, 'is_string'));
        $this->publicAssets = $publicAssets !== null && $publicAssets !== '' ? rtrim($publicAssets, '/') : null;

        // Manifests read engine-first so the site's globals land in the last
        // tier and win the cascade. The FIRST root is always the site layer,
        // single-root installs included — that is the documented styling
        // ladder ("add rules to site.css, emitted last, wins the cascade").
        // Gating this on having more than one root made a single-root site's
        // globals emit BEFORE its component CSS, silently losing every
        // equal-specificity override.
        foreach (array_reverse($this->themeDirs, true) as $i => $dir) {
            $site = $i === 0;
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

    /** Everything for <head>: preconnects, external stylesheets, then the CSS. */
    public function head(): string
    {
        $out = [];

        foreach ($this->hosts() as $host) {
            $out[] = '<link rel="preconnect" href="' . htmlspecialchars($host) . '" crossorigin>';
        }
        foreach ($this->extCss as $url => $attrs) {
            $out[] = '<link rel="stylesheet" href="' . htmlspecialchars($url) . '"' . $this->attrs($attrs) . '>';
        }

        // Three tiers, always in this order, whether bundled or inlined:
        // lower-layer globals, this page's components, the site's globals last
        // — which is what lets site.css restyle any component.
        $tiers = [
            'base' => $this->cssPre,
            'page' => array_values($this->cssComponent),
            'site' => $this->cssPost,
        ];

        foreach ($tiers as $prefix => $files) {
            $parts = [];
            foreach ($files as $file) {
                $content = self::minifyCss($this->read($file));
                if ($content !== '') {
                    $parts[] = $content;
                }
            }
            if ($parts === []) {
                continue;
            }
            // Newline-joined: CSS has no ASI hazard, but a bundle that ended
            // mid-comment would swallow the next file's first rule.
            $bundled = $this->bundle('css', $prefix, implode("\n", $parts));
            $out[] = $bundled !== null
                ? '<link rel="stylesheet" href="' . htmlspecialchars($bundled) . '">'
                : "<style>\n" . implode("\n</style>\n<style>\n", $parts) . "\n</style>";
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
        $locals = [...$this->jsPre, ...array_values($this->jsComponent), ...$this->jsPost];

        // Libraries first: a {file:, wrap: false} entry installs its globals at
        // top level, before any wrapped code queues behind DOMContentLoaded.
        // Bundled, every local tag is `defer`, so document order IS execution
        // order — external defers, then lib, then the wrapped tiers — and all
        // of them finish before DOMContentLoaded fires, which is what lets the
        // wrapped code's listeners register in time.
        $lib = [];
        foreach ($locals as $file) {
            if ($file['wrap'] ?? true) {
                continue;
            }
            $content = $this->read($file);
            if ($content !== '') {
                $lib[] = str_ireplace('</script', '<\/script', $content);
            }
        }
        if ($lib !== []) {
            // "\n;\n" between files: a library whose last statement omits its
            // semicolon must not swallow the next file's leading `(` through
            // automatic semicolon insertion.
            $bundled = $this->bundle('js', 'lib', implode("\n;\n", $lib));
            $out[] = $bundled !== null
                ? '<script src="' . htmlspecialchars($bundled) . '" defer></script>'
                : "<script>\n" . implode("\n;\n", $lib) . "\n</script>";
        }

        $tiers = [
            'base' => $this->jsPre,
            'page' => array_values($this->jsComponent),
            'site' => $this->jsPost,
        ];
        foreach ($tiers as $prefix => $files) {
            $parts = [];
            foreach ($files as $file) {
                if (!($file['wrap'] ?? true)) {
                    continue;
                }
                $content = $this->read($file);
                if ($content !== '') {
                    // In code position </script> cannot legitimately appear, and
                    // in a JS string '<\/script' means the same thing — so this
                    // cannot change behaviour, only close the tag-breakout hole.
                    $content = str_ireplace('</script', '<\/script', $content);
                    // Each file keeps its own listener: that is what stops two
                    // components' top-level `const`s from colliding once they
                    // share one bundle scope.
                    $parts[] = "document.addEventListener('DOMContentLoaded', function () {\n"
                        . $content . "\n});";
                }
            }
            if ($parts === []) {
                continue;
            }
            $bundled = $this->bundle('js', $prefix, implode("\n;\n", $parts));
            $out[] = $bundled !== null
                ? '<script src="' . htmlspecialchars($bundled) . '" defer></script>'
                : '<script>' . implode("</script>\n<script>", $parts) . '</script>';
        }

        return implode("\n", $out);
    }

    /**
     * One tier written to a content-addressed file; null means "inline it".
     *
     * The name IS the invalidation: change a byte and the URL changes, so the
     * file can be served immutable and never needs a CDN purge. Nothing is
     * pre-checked — no is_writable() gate, because on a fresh checkout the
     * directory does not exist yet and a gate would pin the site to inline
     * mode forever. We attempt the write; only a real failure falls back.
     */
    private function bundle(string $kind, string $prefix, string $content): ?string
    {
        if ($this->publicAssets === null || $this->failed) {
            return null;
        }

        $name = $prefix . '-' . substr(hash('xxh128', $content), 0, 12) . '.' . $kind;
        $dir  = $this->publicAssets . '/' . $kind;
        $path = $dir . '/' . $name;
        $url  = '/assets/' . $kind . '/' . $name;

        if (is_file($path)) {
            return $url;
        }
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return $this->bundlesOff('cannot create ' . $dir);
        }
        // tmp + rename, so a concurrent request never reads a half-written
        // bundle: rename() is atomic within a filesystem.
        $tmp = $path . '.' . getmypid() . '.tmp';
        if (@file_put_contents($tmp, $content) === false || !@rename($tmp, $path)) {
            @unlink($tmp);

            return $this->bundlesOff('cannot write ' . $path);
        }

        return $url;
    }

    /** One log line per render, then inline everything for the rest of it. */
    private function bundlesOff(string $why): null
    {
        if (!$this->failed) {
            $this->failed = true;
            error_log('[dopamine-flatcms] assets: ' . $why . ' — falling back to inline delivery');
        }

        return null;
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
            // Exactly one of url|file. Both or neither is a manifest typo;
            // fail soft here, bin/doctor is where it gets loud.
            if (isset($entry['url']) === isset($entry['file'])) {
                error_log('[dopamine-flatcms] assets: map entry needs exactly one of url or file');

                return;
            }
            if (isset($entry['url'])) {
                $this->addExternal($kind, $entry);

                return;
            }
            if ($externalOnly) {
                error_log('[dopamine-flatcms] assets: local path in schema assets: ' . (string) $entry['file']);

                return;
            }
            // {file: path, wrap: false} — a local library that must execute at
            // top level: inside the DOMContentLoaded wrapper `this` is the
            // listener's currentTarget (document), so a UMD bundle resolving
            // its global via `this` installs itself on the wrong object and
            // window.gsap never exists. Only JS distinguishes wrap; for CSS
            // the map form is just a path.
            $this->addLocal($kind, $root, (string) $entry['file'], $site ? 'post' : 'pre',
                ($entry['wrap'] ?? true) !== false);

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

    private function addLocal(string $kind, string $root, string $rel, string $tier, bool $wrap = true): void
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

        $file = ['root' => $root, 'rel' => $rel, 'wrap' => $wrap];
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
