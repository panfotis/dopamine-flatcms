<?php

declare(strict_types=1);

namespace Dopamine\FlatCms;

use Symfony\Component\Yaml\Yaml;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFunction;

/**
 * Wires everything together. One instance per request.
 */
final class Cms
{
    public readonly Components $components;

    /**
     * The store for the language this request is in.
     *
     * Not readonly, and that is the whole of Phase 9's resolver: the entry
     * point works out the locale from the URL prefix and calls useLocale()
     * once, and everything downstream — nav, sitemap, the panel, the save path
     * — goes on asking `$cms->content` and is in the right language for free.
     * The alternative was threading a locale argument through twelve methods
     * that have no opinion about it.
     */
    public Content $content;

    public readonly Auth $auth;
    public readonly R2 $r2;
    public readonly Submissions $submissions;
    public readonly Cloudflare $cf;
    public readonly Locks $locks;
    public readonly Environment $twig;

    /** Panel strings, in the language the person editing the site reads. */
    public readonly Lang $lang;

    /** @var array<string, string>|null page id => slug, built on first use */
    private ?array $slugs = null;

    /** @var array<string, Content> one store per locale, built on demand */
    private array $stores = [];

    /** @var array<string, Lang> one catalogue per site locale, built on demand */
    private array $siteLangs = [];

    /** @var array<string, array{label:string,prefix:string,default:bool,fallback:string}>|null */
    private ?array $locales = null;

    /** The language this request is in. */
    private string $locale;

    /** @param array<string, mixed> $config */
    public function __construct(public readonly array $config)
    {
        $paths = $config['paths'];

        $this->lang = new Lang($paths['lang'] ?? __DIR__ . '/../lang', (string) ($config['admin_locale'] ?? Lang::SOURCE));

        $this->components = new Components($paths['components']);
        // The default until the entry point says otherwise, which it does on
        // every request that carries a locale prefix.
        $this->locale     = $this->defaultLocale();
        $this->content    = $this->contentIn($this->locale);
        $this->auth       = new Auth($config['auth'], $paths['cache'], $this->lang);
        $this->r2         = new R2($config['r2'], $paths['uploads']);
        // var/, never content/: visitor names, emails and messages must not
        // reach the hourly git push that backs the client's pages up.
        $this->submissions = new Submissions(dirname($paths['cache']) . '/submissions');
        $this->cf         = new Cloudflare($config['cloudflare']);
        $this->locks      = new Locks(dirname($paths['cache']) . '/locks');

        $loader = new FilesystemLoader([$paths['templates'], $paths['components']]);
        $this->twig = new Environment($loader, [
            'cache'      => $config['twig_cache'] ? $paths['cache'] . '/twig' : false,
            'autoescape' => 'html',
            'strict_variables' => false,
        ]);

        $this->twig->addFunction(new TwigFunction('img', $this->imageUrl(...)));
        $this->twig->addFunction(new TwigFunction('page_url', $this->pageUrl(...)));
        $this->twig->addFunction(new TwigFunction('site', fn (string $k): mixed => $config['site'][$k] ?? null));
        // The edit form asks the same question the save path asks, of the same
        // function. A template that decided for itself which fields to lock
        // would drift from the rule that is actually enforced.
        $this->twig->addFunction(new TwigFunction('may_edit', Components::mayEdit(...)));
        // Panel strings. Admin templates use it; a site's own components speak
        // whatever language their content file is in, which is a different
        // question with a different answer.
        $this->twig->addFunction(new TwigFunction('t', $this->lang->t(...)));

        /**
         * The engine strings a *visitor* sees — the contact form's refusals —
         * in the language of the page they appear on rather than the panel's.
         */
        $this->twig->addFunction(new TwigFunction('ts', fn (string $k, string|int ...$a): string
            => $this->siteLang()->t($k, ...$a)));
        // NOTE: there is deliberately no `|rich` filter. A filter that marks
        // arbitrary strings `is_safe: html` is an XSS primitive waiting for
        // someone to reach for it; richtext is already sanitised on save and
        // templates use `|raw` explicitly so the trust decision stays visible.
    }

    /**
     * The configured languages, normalised.
     *
     *   'el' => ['label' => 'Ελληνικά', 'prefix' => '',    'default' => true],
     *   'en' => ['label' => 'English',  'prefix' => '/en', 'fallback' => 'default'],
     *
     * **Resolution is by URL prefix**, and only by URL prefix. An earlier draft
     * also promised "both prefixes empty, split by domain, with no code
     * change", which cannot work: an empty prefix leaves no language anywhere
     * in the path. Host-based resolution is a separate mode with its own key,
     * its own code path and its own tests, and it is not this.
     *
     * `fallback` says what a non-default language does about a page it has no
     * translation of — `404`, or `default` to serve the default language's
     * version. It is meaningless on the default language itself, so it is
     * dropped here and bin/doctor refuses a config that sets one.
     *
     * @return array<string, array{label:string,prefix:string,default:bool,fallback:string}>
     */
    public function locales(): array
    {
        if ($this->locales !== null) {
            return $this->locales;
        }

        $configured = (array) ($this->config['locales'] ?? []);
        if ($configured === []) {
            // A site that never configured any is a single-language site, and
            // says so in site.locale. One shape downstream, either way.
            $configured = [(string) $this->config['site']['locale'] => ['default' => true]];
        }

        $out = [];
        foreach ($configured as $code => $def) {
            $def = (array) $def;
            $isDefault = ($def['default'] ?? false) === true;

            $out[(string) $code] = [
                'label'    => (string) ($def['label'] ?? $code),
                // '' for the default language means its URLs are unprefixed,
                // which is what every existing link on a live site already is.
                'prefix'   => rtrim((string) ($def['prefix'] ?? ''), '/'),
                'default'  => $isDefault,
                // 404 rather than `default` when unset: silently serving one
                // language under another language's URL is a decision, not a
                // default.
                'fallback' => $isDefault ? '' : ((string) ($def['fallback'] ?? '404') === 'default' ? 'default' : '404'),
            ];
        }

        return $this->locales = $out;
    }

    /** The language everything falls back to, and the one with no prefix. */
    public function defaultLocale(): string
    {
        foreach ($this->locales() as $code => $def) {
            if ($def['default']) {
                return $code;
            }
        }

        return (string) $this->config['site']['locale'];
    }

    /** The language this request is in. */
    public function locale(): string
    {
        return $this->locale;
    }

    /**
     * Switch this instance into a language. Called once per request by the
     * entry point, and once per screen by the panel.
     *
     * The locale is matched against the configured map *before* it reaches
     * Content, so a crafted path segment is refused by a lookup rather than by
     * a regex — and Content::assertSafeSegment() refuses it again on the way
     * to a directory name, because one guard is not a guard if it moves.
     */
    public function useLocale(string $locale): void
    {
        if (!isset($this->locales()[$locale])) {
            throw new \RuntimeException('Unknown locale.');
        }

        $this->locale  = $locale;
        $this->content = $this->contentIn($locale);
        $this->slugs   = null; // built from the current language's pages
    }

    /**
     * Engine strings in the language of the page being rendered.
     *
     * Separate from `$this->lang` on purpose: the panel speaks ADMIN_LOCALE to
     * the person editing, and a form refusal speaks to the visitor in the
     * language of the page they are standing on. One instance would make one of
     * those two wrong on every bilingual site.
     */
    public function siteLang(): Lang
    {
        return $this->siteLangs[$this->locale] ??= new Lang(
            (string) ($this->config['paths']['lang'] ?? __DIR__ . '/../lang'),
            $this->locale
        );
    }

    /** The store for one language, whichever this request happens to be in. */
    public function contentIn(string $locale): Content
    {
        return $this->stores[$locale] ??= new Content($this->config['paths']['content'], $locale, $this->lang);
    }

    /**
     * Split a request path into the language it names and the path inside it.
     *
     *   /en/contact -> ['en', '/contact']
     *   /en         -> ['en', '/']
     *   /contact    -> ['el', '/contact']   (the default has no prefix)
     *
     * Longest prefix wins, so a two-segment prefix is not shadowed by the
     * default's empty one.
     *
     * @return array{0: string, 1: string}
     */
    public function localeOf(string $path): array
    {
        $path = '/' . ltrim($path, '/');

        $best = [$this->defaultLocale(), $path];
        $bestLength = -1;

        foreach ($this->locales() as $code => $def) {
            $prefix = $def['prefix'];
            if ($prefix === '' || strlen($prefix) <= $bestLength) {
                continue;
            }

            if ($path === $prefix || str_starts_with($path, $prefix . '/')) {
                $best = [$code, substr($path, strlen($prefix)) ?: '/'];
                $bestLength = strlen($prefix);
            }
        }

        return $best;
    }

    /**
     * A stored slug as a URL in one language: the language's prefix in front.
     *
     * Every link on the site goes through here, which is why changing a prefix
     * in config moves the URLs with no code change — the criterion Phase 9 is
     * accepted against.
     */
    public function localeUrl(string $slug, ?string $locale = null): string
    {
        $prefix = $this->locales()[$locale ?? $this->locale]['prefix'] ?? '';

        return $prefix . '/' . ltrim($slug, '/');
    }

    /**
     * The same page in every language that has it, for `hreflang` and for the
     * language switcher — one list, because they are one question asked twice
     * and two lists would answer it differently.
     *
     * The page id is the translation identity, so this is a file-existence
     * check per language and nothing more. `x-default` points at the default
     * language, which is what a search engine should send an unmatched visitor.
     *
     * `url` is a path, for a link on the page. `abs` is absolute, because a
     * `hreflang` a search engine cannot resolve is worse than none.
     *
     * $page is the page being rendered, and therefore the one in the **current**
     * language — that entry is taken as given rather than re-read.
     *
     * @param  array<string, mixed> $page
     * @return list<array{locale:string,label:string,url:string,abs:string,current:bool,missing:bool}>
     */
    public function alternates(array $page): array
    {
        $id = (string) ($page['id'] ?? '');
        $base = rtrim((string) $this->config['site']['base_url'], '/');
        $out = [];

        foreach ($this->locales() as $code => $def) {
            $translated = $code === $this->locale
                ? $page
                : $this->contentIn($code)->load($id);

            // A language without this page still appears in the switcher, so a
            // visitor is never silently offered fewer languages on one page
            // than on another — it points at that language's home page.
            $slug = $translated === null ? '/' : (string) ($translated['slug'] ?? '/');

            $url = $this->localeUrl($slug, $code);

            $out[] = [
                'locale'  => $code,
                'label'   => $def['label'],
                'url'     => $url,
                'abs'     => $base . $url,
                'current' => $code === $this->locale,
                'missing' => $translated === null,
                // Which entry `x-default` points at — the answer a crawler
                // wants for a visitor who matches no language at all.
                'default' => $def['default'],
            ];
        }

        return $out;
    }

    /**
     * The URL for one image at one **allowed** width.
     *
     *   {{ img(fields.image.src, 1280) }}  -> /img.php?src=/uploads/x.jpg&w=1280
     *   {{ img(fields.image.src, 1280, 'webp') }} -> ...&f=webp
     *
     * A width outside the allowlist returns an empty string rather than a URL
     * that would 404: the finite set is the whole reason an anonymous GET
     * cannot mint arbitrary variants, and a template that asks for 1100px has
     * made a mistake that should show up as a missing srcset entry, not as a
     * broken image. picture.twig drops the empty ones.
     *
     * With CF_IMAGES_ENABLED the same call yields a /cdn-cgi/image URL and
     * Cloudflare does the work — but the width is validated either way, so
     * local and production ask for exactly the same set of variants. Local dev
     * used to diverge here: `transform` is off in DDEV, so this returned a bare
     * path locally and a transformation URL live, and nothing about the
     * responsive markup was ever exercised before deploy.
     */
    public function imageUrl(?string $src, int $width = 0, string $format = 'auto'): string
    {
        $src = (string) $src;
        if ($src === '') {
            return '';
        }

        $d = (array) $this->config['images']['derivatives'];

        if ($width <= 0) {
            return $src; // the original, at whatever size it was stored
        }

        if (!in_array($width, (array) ($d['widths'] ?? []), true)
            || !in_array($format, (array) ($d['formats'] ?? []), true)) {
            return '';
        }

        if (!$this->config['images']['transform']) {
            return (string) $d['route'] . '?src=' . rawurlencode($src) . '&w=' . $width
                . ($format === 'auto' ? '' : '&f=' . $format);
        }

        $opts = [
            'width=' . $width,
            'quality=' . (int) $this->config['images']['quality'],
            'format=auto',
            'fit=cover',
        ];

        // The source must be absolute for /cdn-cgi/image to fetch it.
        $abs = str_starts_with($src, 'http')
            ? $src
            : rtrim((string) $this->config['site']['base_url'], '/') . '/' . ltrim($src, '/');

        return '/cdn-cgi/image/' . implode(',', $opts) . '/' . $abs;
    }

    /**
     * Resolve a `link` field's stored page id to an href.
     *
     *   {{ page_url(fields.cta_url) }}  ->  /epikoinonia
     *
     * The empty string means "no such page", and a template must render that
     * as plain text rather than as an href — a link to nothing is worse than
     * no link, because it looks like it works. Phase 9 makes this per-locale:
     * the id is the same in every language, the slug beside it is not.
     */
    public function pageUrl(?string $id): string
    {
        $id = (string) $id;
        if ($id === '') {
            return '';
        }

        // One list() per request, however many links a page carries.
        $this->slugs ??= array_column($this->content->list(), 'slug', 'id');

        if (isset($this->slugs[$id])) {
            return $this->localeUrl($this->slugs[$id]);
        }

        // The page exists but this language has no translation of it. `404`
        // means the link renders as plain text, which is the same thing a link
        // to a deleted page has always done; `default` sends the visitor to the
        // version that does exist rather than to nothing.
        $default = $this->defaultLocale();
        if ($this->locale !== $default && $this->locales()[$this->locale]['fallback'] === 'default') {
            $page = $this->contentIn($default)->load($id);
            if ($page !== null) {
                return $this->localeUrl((string) ($page['slug'] ?? '/'), $default);
            }
        }

        return '';
    }

    /**
     * The site menu, from the `nav:` key on each page.
     *
     * Nothing in v1 defined where a menu came from — Content::list() sorts by
     * slug, alphabetically, which is a menu order only by accident. `nav` is
     * developer-owned like every other structural key, so a client cannot
     * reorder or rename the navigation from the panel.
     *
     * Pages without a `nav:` key are simply not in the menu, which is how a
     * landing page or a thank-you page stays reachable but unlisted.
     *
     * @return list<array{id: string, url: string, label: string, order: int}>
     */
    public function nav(): array
    {
        $out = [];
        foreach ($this->content->list() as $page) {
            if ($page['nav'] === null) {
                continue;
            }

            $out[] = [
                'id'    => $page['id'],
                // Prefixed, so the menu on the English site links to English
                // URLs without any template knowing a prefix exists.
                'url'   => $this->localeUrl($page['slug']),
                'label' => (string) ($page['nav']['label'] ?? $page['title']),
                'order' => (int) ($page['nav']['order'] ?? 0),
            ];
        }

        // Ties break on label rather than on filesystem order, so two pages at
        // the same order do not swap places between deploys.
        usort($out, static fn (array $a, array $b): int
            => [$a['order'], $a['label']] <=> [$b['order'], $b['label']]);

        return $out;
    }

    /**
     * Where a path should be sent instead of 404ing, or null.
     *
     * Nearly every one of these sites replaces an existing one, and the old
     * URLs are the client's search rankings. content/redirects.yml is a flat
     * `from: to` map; `to` may be a path or a page id, so a redirect written
     * against a slug survives that slug being renamed for the same reason a
     * `link` field does.
     *
     * One hop only. A chain is a configuration mistake, not a feature —
     * bin/doctor refuses a loop or a target that does not resolve.
     */
    public function redirectFor(string $path): ?string
    {
        $file = $this->config['paths']['content'] . '/redirects.yml';
        if (!is_file($file)) {
            return null;
        }

        $map = Yaml::parseFile($file);
        if (!is_array($map)) {
            return null;
        }

        $want = '/' . trim($path, '/');
        foreach ($map as $from => $to) {
            if ('/' . trim((string) $from, '/') !== $want) {
                continue;
            }

            $to = (string) $to;

            return str_starts_with($to, '/') || str_starts_with($to, 'http')
                ? $to
                : ($this->pageUrl($to) ?: null);
        }

        return null;
    }

    /**
     * Render a page: for each block, render its component template with that
     * block's fields, then drop the result into the layout.
     *
     * Three regions, one loop. The header and the footer are content too —
     * `_header.yml` and `_footer.yml`, edited in the panel like any page —
     * and their blocks go through exactly the same schema lookup, the same
     * defaults and the same unknown-component skip as the page's own.
     *
     * $extra is whatever the entry point has that this class has no business
     * knowing — the form's CSRF token and its errors, today. It is merged in
     * front of the shared variables rather than reached for from inside a
     * component, so a component still receives one flat set of variables.
     *
     * @param array<string, mixed> $page
     * @param array<string, mixed> $extra
     */
    public function renderPage(array $page, array $extra = []): string
    {
        // Resolved once and hung on the page, so `page.seo` is the same shape
        // in the layout as in any component that wants to read it.
        $page['seo'] = $this->seo($page);
        // The page's own address in the language being rendered. `slug` stays
        // what the file says; templates comparing "am I the current page"
        // compare URLs, and on a prefixed language those are not the same string.
        $page['url'] = $this->localeUrl((string) ($page['slug'] ?? '/'));

        // Not addGlobal(): a global is built when the environment is, which
        // would read every page file on every admin request too. Here it costs
        // exactly one list() and only on the path that renders a menu.
        //
        // Handed to the header and footer blocks unchanged, so a `site_header`
        // reads the *current* page and can mark the active menu item without
        // knowing it was rendered from a different file.
        $shared = $extra + [
            'page'       => $page,
            'nav'        => $this->nav(),
            'locale'     => $this->locale,
            // One list, used by the head for hreflang and by the header
            // component for the switcher. Two lists would disagree.
            'alternates' => $this->alternates($page),
        ];

        return $this->twig->render($this->layoutOf($page), $shared + [
            'header_blocks' => $this->renderGlobal('_header', $shared),
            'blocks'        => $this->renderBlocks((array) $page['blocks'], $shared),
            'footer_blocks' => $this->renderGlobal('_footer', $shared),
        ]);
    }

    /**
     * Which wrapper a page renders in: its own `layout:`, or `layout.twig`.
     *
     * A landing page with no menu, a print stylesheet, a page that has to open
     * inside someone else's iframe — the alternative is a `bare: true` boolean,
     * then a second one, and eventually a page-type system. §4 has said no page
     * types since v1, and one filename says the same thing without a taxonomy.
     *
     * **Developer-owned** (§10.3), like the page id and the slug: it names a
     * *template*, so a value from a request would be a file-read primitive.
     * `Admin::save()` never reads it, and the name is checked against the same
     * segment rule every other path segment goes through — a `layout:` that
     * does not resolve falls back rather than fatalling a live client site,
     * which is the same policy as an unknown component.
     *
     * @param array<string, mixed> $page
     */
    private function layoutOf(array $page): string
    {
        $name = (string) ($page['layout'] ?? '');
        if ($name === '' || preg_match('/^[a-z0-9_-]+$/i', $name) !== 1) {
            return 'layout.twig';
        }

        return $this->twig->getLoader()->exists($name . '.twig') ? $name . '.twig' : 'layout.twig';
    }

    /**
     * One region's blocks, as rendered HTML strings.
     *
     * @param  array<int, array<string, mixed>> $blocks
     * @param  array<string, mixed>             $shared
     * @return list<string>
     */
    private function renderBlocks(array $blocks, array $shared): array
    {
        $html = [];
        foreach ($blocks as $block) {
            $schema = $this->components->get((string) $block['type']);
            if ($schema === null) {
                // Unknown component: skip in production rather than fatal on a
                // live client site. Visible only in the admin panel.
                continue;
            }

            $html[] = $this->twig->render($schema['template'], $shared + [
                'block'  => $block,
                'fields' => $this->withDefaults($schema, $block['fields']),
            ]);
        }

        return $html;
    }

    /**
     * A global's blocks, or none at all when the file is not there.
     *
     * A site without a `_footer.yml` renders without a footer — the same
     * policy as the unknown-component skip above, and for the same reason: a
     * missing file is a developer's omission, and a fatal on every page of a
     * live client site is not the way to report it. bin/doctor says so instead.
     *
     * @param  array<string, mixed> $shared
     * @return list<string>
     */
    private function renderGlobal(string $id, array $shared): array
    {
        $global = $this->content->load($id);

        return $global === null ? [] : $this->renderBlocks((array) $global['blocks'], $shared);
    }

    /**
     * Fill in defaults for fields the content file does not define yet, so
     * adding a field to schema.yml never breaks an existing page.
     *
     * @param  array<string, mixed> $schema
     * @param  array<string, mixed> $values
     * @return array<string, mixed>
     */
    public function withDefaults(array $schema, array $values): array
    {
        $out = [];
        foreach ($schema['fields'] as $name => $def) {
            // blank() rather than '': an image or a list that nobody has filled
            // in yet must still be the right *shape*, or every template has to
            // branch on "is it a map or is it an empty string".
            $out[$name] = $values[$name] ?? $def['default'] ?? Fields::blank((string) ($def['type'] ?? 'text'));
        }

        // Deliberately NOT `+ $values`. Keys still sitting in the content file
        // after a field was removed from schema.yml must not reach templates —
        // if a field was deleted because it was unsafe, its value has never
        // been through the current sanitiser.
        return $out;
    }

    /**
     * Constraints handed to Fields::sanitise() at save time.
     *
     * @return array<string, mixed>
     */
    public function fieldContext(): array
    {
        $bases = (array) ($this->config['media_bases'] ?? ['/uploads/']);

        $r2Base = rtrim((string) ($this->config['r2']['public_base'] ?? ''), '/');
        if ($r2Base !== '') {
            $bases[] = $r2Base . '/';
        }

        return [
            'media_bases' => array_values(array_filter($bases)),
            // So a refusal names the field in the language the panel speaks.
            'lang' => $this->lang,
        ];
    }

    /**
     * The page's SEO values as a template should see them.
     *
     * Read on the render path only: the stored map is what the panel edits and
     * what Fields::map() validated on the way in, and this is the resolved
     * shape templates ask questions of. The fallbacks live here and nowhere
     * else, so no template has to know them:
     *
     *   - `title` falls back to the page title
     *   - `description` falls back to the page's first prose field
     *   - `og_image` falls back to the page's first image, then to
     *     site.og_default, and is made absolute — a social scraper does not
     *     resolve a relative URL
     *   - `url` is the page's own absolute address, for og:url
     *
     * Every one of them is resolved at render and never written back. A
     * derived description stored in the file would look filled in from the
     * panel, so nobody would ever replace it with a real one, and it would go
     * stale the moment the copy above it changed.
     *
     * @param  array<string, mixed> $page
     * @return array<string, mixed>
     */
    public function seo(array $page): array
    {
        $base = rtrim((string) $this->config['site']['base_url'], '/');

        // withDefaults() rather than the raw map: a key dropped from
        // Fields::SEO stops reaching the head on the next request rather than
        // lingering in every page file that still has it.
        $seo = $this->withDefaults(['fields' => Components::seoFields()], (array) ($page['seo'] ?? []));
        $src = (string) (((array) $seo['og_image'])['src'] ?? '');

        // One walk over the blocks, and only when there is something left to
        // fill in — a page whose SEO is fully written costs nothing here.
        $from = $seo['description'] === '' || $src === ''
            ? $this->pageSummary($page)
            : ['description' => '', 'src' => ''];

        $seo['title']       = $seo['title'] ?: (string) ($page['title'] ?? '');
        $seo['description'] = $seo['description'] ?: $from['description'];

        // og_image is decorative by declaration, so there is no alt here and no
        // og:image:alt in the head: the share card carries the title and the
        // description as text beside it.
        $src = $src ?: ($from['src'] ?: (string) $this->config['site']['og_default']);
        $seo['og_image'] = [
            'src' => $src === '' || str_starts_with($src, 'http') ? $src : $base . '/' . ltrim($src, '/'),
        ];

        $seo['url'] = $base . $this->localeUrl((string) ($page['slug'] ?? '/'));

        return $seo;
    }

    /**
     * What a page can say about itself when its `seo` block says nothing.
     *
     * Both answers come off the **schema** rather than off a heuristic: the
     * first `textarea` or `richtext` value in block order, and the first
     * `image`. "The first long text field" would need a length to tune and
     * would pick a different field the day someone edited the copy; the field
     * type is a question the component already answered, once, in schema.yml.
     *
     * Top-level fields only. An image inside a list row is one of many by
     * construction, and "the first row of the gallery" is not a considered
     * choice of share image — it is whichever one happens to be first.
     *
     * @param  array<string, mixed> $page
     * @return array{description: string, src: string}
     */
    private function pageSummary(array $page): array
    {
        $out = ['description' => '', 'src' => ''];

        foreach ((array) ($page['blocks'] ?? []) as $block) {
            $schema = $this->components->get((string) ($block['type'] ?? ''));
            if ($schema === null) {
                continue;
            }

            foreach ($schema['fields'] as $name => $def) {
                $value = ($block['fields'] ?? [])[$name] ?? null;
                $type  = (string) ($def['type'] ?? '');

                if ($out['src'] === '' && $type === 'image' && is_array($value)) {
                    $out['src'] = (string) ($value['src'] ?? '');
                }

                if ($out['description'] === '' && in_array($type, ['textarea', 'richtext'], true)) {
                    $out['description'] = self::summarise((string) (is_scalar($value) ? $value : ''));
                }
            }

            if ($out['src'] !== '' && $out['description'] !== '') {
                break;
            }
        }

        return $out;
    }

    /**
     * A meta description's worth of prose out of a content field.
     *
     * Richtext is HTML, so the tags come out and the entities come back — a
     * snippet reading "drag &amp;amp; drop" is worse than no snippet. The cut
     * is on a word boundary because Google shows what it is given: an
     * autogenerated description that stops mid-word looks like a broken site
     * rather than a busy one.
     */
    private static function summarise(string $html, int $max = 155): string
    {
        $text = trim((string) preg_replace(
            '/\s+/u',
            ' ',
            html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8')
        ));

        if ($text === '' || mb_strlen($text) <= $max) {
            return $text;
        }

        $cut = mb_substr($text, 0, $max - 1);
        $space = mb_strrpos($cut, ' ');

        // preg with /u rather than rtrim(): rtrim's mask is a set of *bytes*,
        // so trimming a multibyte character eats one byte off the end of a
        // Greek word and leaves invalid UTF-8 in the head of every page.
        return preg_replace(
            '/[\s,.·—-]+$/u',
            '',
            $space !== false ? mb_substr($cut, 0, $space) : $cut
        ) . '…';
    }

    /**
     * `/sitemap.xml`, generated from the content files.
     *
     * `lastmod` is the page file's mtime — the only honest answer a flat CMS
     * has, and one every save updates for free. No `changefreq` or `priority`:
     * Google has ignored both for years, and a value invented to fill the
     * element is a wrong claim on record rather than a missing one.
     *
     * Every language is in the one file, and every entry carries the whole
     * alternate group — including a link to *itself*, without which the group
     * is not valid — plus `x-default` pointing at the default language.
     */
    public function sitemap(): string
    {
        $base = rtrim((string) $this->config['site']['base_url'], '/');
        $default = $this->defaultLocale();

        // Every language's slug for every page id, in one pass. The alternate
        // group of a page is the same list from whichever language you ask, so
        // building it once beats a lookup per language per URL.
        $slugs = [];
        foreach (array_keys($this->locales()) as $code) {
            foreach ($this->contentIn($code)->list() as $page) {
                $slugs[$page['id']][$code] = $page['slug'];
            }
        }

        $abs = fn (string $slug, string $code): string => htmlspecialchars(
            $base . $this->localeUrl($slug, $code),
            ENT_XML1 | ENT_QUOTES,
            'UTF-8'
        );

        $out = [
            '<?xml version="1.0" encoding="UTF-8"?>',
            '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"'
                . ' xmlns:xhtml="http://www.w3.org/1999/xhtml">',
        ];

        foreach ($this->locales() as $code => $def) {
            foreach ($this->contentIn($code)->list() as $page) {
                if ($page['noindex']) {
                    // Asking a crawler not to index a page and then submitting
                    // it in the sitemap is asking twice and answering
                    // differently.
                    continue;
                }

                $out[] = '  <url>';
                $out[] = '    <loc>' . $abs($page['slug'], $code) . '</loc>';
                $out[] = '    <lastmod>' . date('c', $page['mtime']) . '</lastmod>';

                foreach ($slugs[$page['id']] as $altCode => $altSlug) {
                    $out[] = sprintf(
                        '    <xhtml:link rel="alternate" hreflang="%s" href="%s"/>',
                        $altCode,
                        $abs($altSlug, $altCode)
                    );
                }

                if (isset($slugs[$page['id']][$default])) {
                    $out[] = sprintf(
                        '    <xhtml:link rel="alternate" hreflang="x-default" href="%s"/>',
                        $abs($slugs[$page['id']][$default], $default)
                    );
                }

                $out[] = '  </url>';
            }
        }

        $out[] = '</urlset>';

        return implode("\n", $out) . "\n";
    }

    /**
     * `/robots.txt`, pointing at the sitemap.
     *
     * SITE_NOINDEX is honoured here as well as in the X-Robots-Tag header,
     * because they are one policy said in the two places a crawler looks. A
     * pre-launch domain whose pages say "noindex" while its robots.txt says
     * "help yourself" is one crawler away from being in the index anyway.
     */
    public function robotsTxt(): string
    {
        return "User-agent: *\n"
            . ($this->config['site']['noindex'] ? "Disallow: /\n" : "Allow: /\n")
            . "\nSitemap: " . rtrim((string) $this->config['site']['base_url'], '/') . "/sitemap.xml\n";
    }

    /**
     * The X-Robots-Tag value for this site, or null.
     *
     * Returned rather than emitted, for the same reason cacheHeaders() is:
     * header() is a no-op under the CLI SAPI the suite runs on, so a policy
     * that emitted itself could not be asserted at all.
     *
     * Site-wide and not per-page on purpose. It covers the window between "the
     * domain resolves" and "the client has approved the copy", which is exactly
     * when nobody is watching and a crawler arrives — including on the pages
     * somebody adds tomorrow.
     */
    public function robotsHeader(): ?string
    {
        return $this->config['site']['noindex'] ? 'noindex, nofollow' : null;
    }

    /**
     * Cache headers for a page, as literal header lines.
     *
     * Returned, never emitted: header() is a no-op under the CLI SAPI, which is
     * where the suite runs, so a policy that emitted itself could not be
     * asserted at all. The entrypoint puts these on the Response.
     *
     * A page marked `private: true` in its YAML bypasses edge caching
     * altogether. That is the v1 contact-form policy: a form page carries a
     * per-visitor CSRF token, and a shared cache that stores one visitor's
     * token and serves it to the next turns every submission into a rejected
     * one — or worse, a valid one attributed to the wrong session. A later
     * uncached-token endpoint would let the page itself be cached again, but
     * that is an optimisation to make only if traffic proves a five-page site
     * needs its contact page at the edge.
     *
     * @param  array<string, mixed> $page
     * @return list<string>
     */
    public function cacheHeaders(array $page): array
    {
        if (($page['private'] ?? false) === true) {
            // No Cache-Tag: there is nothing at the edge to purge, and emitting
            // one would imply otherwise.
            return ['Cache-Control: no-store, private'];
        }

        $c = $this->config['cache'];

        // A response with no page behind it — the sitemap, robots.txt — carries
        // `site` alone. Tagging the sitemap page:<id> would mean nothing ever
        // purged it, because the page that changed is never the sitemap; `site`
        // is the tag every save already purges, which is the whole point.
        $tags = array_filter([
            isset($page['id']) ? Cloudflare::tagFor((string) $page['id']) : '',
            'site',
        ]);

        return [
            sprintf(
                'Cache-Control: public, max-age=%d, s-maxage=%d',
                (int) $c['browser_max_age'],
                (int) $c['edge_max_age']
            ),
            'Cache-Tag: ' . implode(',', $tags),
        ];
    }
}
