# Dopamine FlatCMS — Plan v3: engine seams

Goal: logic that belongs to the engine should live in the engine and arrive via
`composer update`; decisions that belong to a site should live in that site's
`config.php`. No sites exist yet, so no phase needs a migration path or backwards
compatibility.

Phases are ordered by dependency. Each is self-contained and leaves the suite green.

---

## Governing rule

**`Cms` stays the facade.** Twig functions are registered in its constructor and themes
call `img()`, `pageUrl()`, `nav()`. Every extraction below moves the implementation, not
the API: `Cms` keeps the method and delegates. No theme changes in any phase.

---

## Phase 0 — Memoize `Content::scan()`

**Twenty minutes, no design decisions, do it first.**

`Content::list()` is `return $this->scan(false);` — every call re-globs `pages/*.yml` and
re-parses each file. Per public render that is three passes over the whole site
(`findBySlug()` at `Content.php:242`, `nav()` at `Cms.php:584`, the slug map at
`Cms.php:547`). `sitemap()` is worse: `Cms.php:1056` and `1078` each loop over every
locale, so a bilingual site parses every page **four times** to emit one file.

Behind the edge cache this is invisible. The panel and `bin/doctor` are not cached.

```php
/** @var array<string, list<array{...}>> */
private array $scanned = [];

private function scan(bool $global): array
{
    return $this->scanned[$global ? 'g' : 'p'] ??= $this->doScan($global);
}
```

Invalidate in `save()` and `restore()` — both already go through `Content`, so there is
one place to clear. Do **not** memoize across requests; this is per-instance only.

**Acceptance:** a counter (or a `filemtime` spy) proving one render performs one glob per
locale. Existing tests must not change.

---

## Phase 1 — `src/Site.php`

**The strongest item in this plan.**

`skeleton/public/index.php` is 168 lines of request logic. `admin.php` is 21 and does
everything through `Admin::handle()`. Same problem, opposite decision.

The precise defect is not "untested" — every *ingredient* is tested. `canonicalPath()`
(`01_render.php:117-121`), `localeOf()` (`:479`) and `cacheHeaders()`
(`06_production.php:424-440`) all have coverage. What has none is the **composition**:

- the order the ingredients run in
- the `isMethodSafe()` guard that stops a POST to `/about/` becoming a GET
- query-string preservation across the 301
- the `fallback: default` branch and the re-render as the fallback locale
- redirect-vs-404 precedence
- POST/redirect/GET with 303, and the 422 on refusal
- CSRF token and `form_opened_at` regenerating on *every* render including a refusal

The 303/422 assertions that do exist (`02_admin.php`, `03_lockdown.php`) are
`Admin::handle()`'s, not the public site's.

**Moves into `Site::handle(Request): Response`:** the `match()` over
`/sitemap.xml` `/sitemap.xsl` `/robots.txt`, `localeOf()` + `useLocale()`, the
trailing-slash 301, the locale fallback, `redirectFor()` and the 404, the entire form
branch, and the `cacheHeaders()` / `robotsHeader()` application.

**Stays in the site** (`skeleton/public/index.php`, ~11 lines):

```php
<?php

declare(strict_types=1);

use Dopamine\FlatCms\Cms;
use Dopamine\FlatCms\Site;
use Symfony\Component\HttpFoundation\Request;

require dirname(__DIR__) . '/vendor/autoload.php';

$cms = new Cms(require dirname(__DIR__) . '/config.php');
Dopamine\FlatCms\bootstrap_error_handler($cms);

(new Site($cms))->handle(Request::createFromGlobals())->send();
```

**Design notes.**

`Site`, not a method on `Cms` — `Cms.php` is already 1,251 lines.

`handle()` returns a `Response` and never calls `send()` or `exit`, exactly as
`Admin::handle()` does. That is the whole reason the composition becomes testable, and it
is the one signature in this plan that is expensive to change later. Get it right now.

Keep the private helpers small and named after the decision they make — `feedFor()`,
`redirectToCanonical()`, `renderPage()`, `handleForm()` — so the top of `handle()` reads
as the sequence of decisions a request goes through rather than as a wall.

The session is opened only on a page that carries a form. Preserve that: it is what keeps
every other page cacheable.

**Acceptance — new `tests/11_routing.php`:**

- `/about/` → 301 to `/about`, query string intact
- POST to `/about/` → no redirect (a 301 would drop the submission)
- a locale that lacks the page, with `fallback: default` → serves the default language at
  the default language's own canonical URL
- unknown slug in `redirects.yml` → 301; not in it → 404 with `no-store`
- successful submit → 303 to `?sent=1`; refused submit → 422 **and a fresh CSRF token**
- form page → `no-store, private`; ordinary page → `s-maxage` + `Cache-Tag`
- with `SITE_NOINDEX` → `X-Robots-Tag` present on the 404 and the 301, not only on pages

---

## Phase 2 — Custom field types

The only item here with a concrete request behind it rather than a hypothetical one: the
first client who wants a date picker or a map currently forces a fork.

**Half of it is already open.** `admin-theme/edit.twig:247` is

```twig
{% include ['@admin/fields/' ~ def.type ~ '.twig', '@admin/fields/text.twig'] %}
```

A site dropping `admin-theme/fields/map.twig` already gets its input, with a text
fallback. The panel needs nothing.

**Five places are closed**, four in `Fields.php` and one outside it:

| Location | What is hardcoded |
|---|---|
| `Fields::TYPES` (:28) | the allowlist |
| `Fields::sanitise()` (:322) | the `match()` |
| `Fields::blank()` (:300) | the empty *shape* per type |
| `Fields::demand()` (:266) | what "empty" means per type |
| `Components.php:162` | `['image', 'image_list']` — which types hold media |

**Where the registry lives.** Every method is `static`, so a `static $registry` would leak
between tests — `tests/` run in one process. Use what already threads through every call
site: `$context`. `Cms::fieldContext()` builds it, `items()` passes it down into
sub-schemas, `image()` and `linkMap()` already take it. `$context['types']` rides along
for free and carries no state.

The only signature that changes is
`blank(string $type)` → `blank(string $type, array $context = [])`.

**The contract:**

```php
interface FieldType
{
    /** The empty value's shape, so a template can branch on it before anyone fills it in. */
    public static function blank(): string|bool|array;

    /** @param array<string, mixed> $def  @param array<string, mixed> $context */
    public static function sanitise(array $def, mixed $raw, array $context): string|bool|array;

    /** Whether `required: true` is satisfied — `''` is not the answer for every type. */
    public static function isEmpty(mixed $value): bool;

    /** Media paths this value holds. Replaces the hardcoded list in Components.php. */
    public static function mediaRefs(mixed $value): array;
}
```

`mediaRefs()` is what stops `Components.php` knowing type names at all: each type declares
its own media, and `image_list` becomes an ordinary consumer of the same method rather
than a second special case.

Declared in `config.php`, developer-owned like `paths`:

```php
'field_types' => ['map' => \Acme\FlatCms\MapField::class],
```

**Two safety rules, both non-negotiable.**

*Built-ins always win.* The registry fills gaps; it never overrides. A `field_types` entry
naming `image`, `richtext`, `link` or `url` is ignored — those carry `media_bases`, the
HTML sanitizer and the `href` rule, and a site must not be able to relax them by accident.
Report it through `bin/doctor` rather than dropping it silently forever.

*A missing class costs a field, it does not throw.* Exactly the principle already applied
to `editable`: a class that does not exist or does not implement `FieldType` falls back to
`plain()`, and `doctor` reports it. A typo must not take a client's site down.

`media` and `page` stay out of `TYPES`, for the reason already documented at
`Fields.php:334`.

**Acceptance:** a fixture type registered in the test config that round-trips through
save → disk → render; a hostile save proving an unregistered type is sanitised as `plain`
and not passed through; a `field_types` entry naming `image` proving the built-in still
runs; the existing `03_lockdown.php` suite unchanged and green.

---

## Phase 3 — `src/Seo.php`

Two separate changes, and one is much clearer than the other.

**3a. The XSL is a template, not a string.** `sitemapXsl()` is ~57 lines of XSL in a
heredoc inside a PHP class. Move it to `@flatcms/sitemap.xsl.twig`. This is right
regardless of whether anything else in this phase happens, and it makes the stylesheet
overridable from a site's theme like every other template.

**3b. The class extraction.** `seo()`, `pageSummary()`, `summarise()`, `sitemap()`,
`robotsTxt()`, `robotsHeader()` — `Cms.php` ~916–1220.

Be honest about the coupling: `pageSummary()` reads `$this->components`, and `sitemap()`
needs `contentIn()`, `localeUrl()` and `alternates()`. So `Seo` takes `Cms` in its
constructor; this is a collaborator extraction, not a dependency-free lift. The value is
that `Cms` stops growing and this cluster gets its own test file — not that the coupling
disappears.

`Cms::seo()` stays as a delegate; `renderPage()` calls it.

**Acceptance:** existing SEO assertions pass unchanged; one new test that a site's own
`sitemap.xsl.twig` wins over the engine's.

---

## Optional — `Media::serve()`

Listed for completeness and **not recommended as scheduled work**.

`img.php` is 49 lines, self-contained, already tested end-to-end by `tests/_img_route.php`
in an isolated process including peak-memory growth, and `readfile()` streams rather than
buffering. The only argument for changing it is consistency once `Site::handle()` and
`Admin::handle()` both return `Response` — and it is a hot path where skipping
HttpFoundation is defensible.

Do it if it falls out of Phase 1 for free. Do not spend a day on it.

---

## Deferred — the other three seams

Not a plugin system: no discovery, no activation state, no admin UI, no version
negotiation, no lifecycle. A "plugin" is a Composer package named explicitly in a site's
`config.php`. A client never installs anything.

- **`routes`** — `'/webhook/stripe' => fn(Request $r, Cms $c): Response => …`, checked by
  `Site::handle()` before the page lookup. Covers most of what would otherwise want a
  plugin.
- **`afterSave(string $pageId, array $page)`** — cache purge is currently hardcoded in
  `Admin::save()`. One event there covers webhooks, Slack, extra purges, rebuilds.
- **`beforeRender(array $page, array $vars): array`** — one chance to add to the template
  context. Bludit spends twelve hooks on this; one method is enough.

All three are a day's work *on the day a real site needs them*. What must be right now is
the `Site::handle(Request): Response` signature (Phase 1) and `Admin::save()` having a
single exit point — those are what make adding the seams later cheap.

---

## Dropped from the previous draft, with reasons

**`src/Locales.php`.** The stated defect was wrong. `contentIn()` already memoizes
(`Cms.php:336`), so `useLocale()` and `contentIn()` are not "two mechanisms" — the
proposed acceptance test passes today. `useLocale()` mutating is fine: it is called once
per request by the front controller. That left "the file is long" as the only argument,
which is not a reason to add an indirection layer. Phase 0 is the real fix that was hiding
behind this one.

**A `bin/doctor` check for a stale front controller.** No sites exist to fall behind.

**Version negotiation between engine and theme.** Same reason.

---

## Explicitly out of scope

- Plugin discovery, activation, admin UI, hook lifecycle
- Anything that changes the API templates see
- Any refactor whose justification is a line count
