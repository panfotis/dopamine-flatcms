# Login-gated component showcase

> **Status:** proposed, not started. Nothing in this document has been
> implemented. Companion to `dopamine-flatcms-plan-v2.md`, which remains the
> phase bible; this is a single self-contained addition and touches nothing in
> `src/`.

## Context

There is no way to see what components a site has without opening
`components/` and reading nine `schema.yml` files. That matters most at the
moment a page is being built: adding a page is adding a file, so you hand-write
`type: hero` into a page YAML, and nothing in the panel or on the site tells you
which types exist, what fields each takes, or what any of them look like
rendered.

`bin/doctor` catches a `type:` with no component ([doctor:428](bin/doctor)), so a
typo never ships. It just catches it late, and it cannot tell you what the right
answer was.

The ask is a **front-end** page — the site's own CSS, not the panel's — showing
every component, reachable only by a logged-in user.

That last part makes it the first authenticated page outside `/admin.php`. The
design below keeps that boundary exactly where it already is rather than
inventing a second one.

## Approach: a separate entry point, not a routable page

Add `public/showcase.php`, mirroring `public/admin.php`. **Not** a page file
with an `auth: true` key.

Cloudflare Access authorises by **path**. `/admin.php` is a path it already
guards, so a second `.php` entry is one more line in the same Access
application. A routable page at `/showcase` would instead need Access to guard a
pretty URL served by `index.php`, and would drag in three problems that simply
do not exist for a separate entry:

- keeping it out of `Cms::sitemap()` ([src/Cms.php:829](src/Cms.php))
- keeping it out of `Cms::nav()`
- guaranteeing `Cms::cacheHeaders()` ([src/Cms.php:944](src/Cms.php)) never lets an
  authenticated render reach the edge

A file that is not a page file has no slug, no sitemap entry, no menu entry and
no cache tag **by construction**. An `auth: true` key would also put a one-word
typo between a private page and Cloudflare's cache, which is the wrong place for
a typo to live.

`nginx.conf.example` already executes any `.php` under the docroot
(`location ~ \.php$`, line 47), so no server change is needed.

## What it renders

The block list is **generated from `Components::all()`**, so a new component
folder appears in the showcase with nothing to remember and no second list to
keep in step.

```php
$blocks = $meta = [];
foreach ($cms->components->all() as $type => $schema) {
    $blocks[] = ['id' => $type, 'type' => $type, 'fields' => sample($schema)];
    $meta[]   = ['type' => $type, 'label' => $schema['label']];
}

echo $cms->renderPage([
    'id'     => '_showcase',
    'title'  => $cms->lang->t('showcase.title'),
    'slug'   => '/showcase',
    'layout' => 'showcase',
    'blocks' => $blocks,
], ['showcase' => $meta]);
```

`Cms::renderPage()` ([src/Cms.php:529](src/Cms.php)) takes an arbitrary page array —
no file on disk is needed. It gives the site header, footer, nav and layout for
free, and `renderBlocks()` already skips unknown components and fills defaults
through `withDefaults()`.

`layout: showcase` resolves through `Cms::layoutOf()` ([src/Cms.php:580](src/Cms.php)),
which falls back to `layout.twig` when the template is missing — so a site that
overrides templates cannot break this.

`Cms::alternates()` ([src/Cms.php:303](src/Cms.php)) is safe with the synthetic id:
`Content::load('_showcase')` returns `null` for a file that does not exist, and
the entry is marked `missing` rather than fatalling.

## Files

**`public/showcase.php`** (new, ~45 lines) — the whole feature:

1. `require` autoload, construct `Cms`, call `bootstrap_error_handler($cms)` —
   the same three lines as `public/admin.php`.
2. `$cms->auth->requireUser($request)` — throws `AccessDeniedException` when
   there is no valid Access JWT. Catch it and return 403 rendering
   `admin/denied.twig`, exactly as `Admin::route()` does
   ([src/Admin.php:88](src/Admin.php)). **Fails closed:** no Access policy on this path
   means nobody gets in, including you.
3. `$cms->useLocale()` from an optional `?locale=`, so a bilingual site can see
   both languages' component labels.
4. Build the blocks, render, send with `Cache-Control: no-store, private` and
   `X-Robots-Tag: noindex, nofollow`.

**`skeleton/public/showcase.php`** (new) — byte-identical copy.
`skeleton/public/` holds its own `admin.php`/`index.php`/`img.php`/`router.php`,
and its `admin.php` is currently byte-identical to the engine's. Keep that
property.

**`templates/showcase.twig`** (new, ~35 lines) — head copied from `bare.twig`
(no menu wanted here), then a label bar per block:

```twig
{% for html in blocks %}
  <div class="sc-label">
    <strong>{{ showcase[loop.index0].label }}</strong>
    <code>type: {{ showcase[loop.index0].type }}</code>
  </div>
  {{ html|raw }}
{% endfor %}
```

`renderBlocks()` returns rendered HTML strings, so the metadata travels beside
them as the `showcase` extra rather than inside the block loop.

**`lang/en.php` + `lang/el.php`** — three keys (`showcase.title`,
`showcase.intro`, `showcase.denied`). `en.php` is the source language.

## The sample-value helper

The only real logic, ~15 lines, private to `showcase.php`:

| Field type | Sample value |
|---|---|
| `text`, `textarea` | the field's own `label` |
| `richtext` | `<p>` + label + `</p>` |
| `select`, `boolean` | left to `withDefaults()` — the schema's own `default` |
| `image`, `image_list`, `video_embed`, `video_loop` | left blank |
| `list` | two rows, each built from the sub-schema the same way |

Images stay blank deliberately. Every component already guards on
`fields.image.src` before rendering a `<picture>`, so a blank image renders the
text-only variant rather than breaking; inventing one would mean inventing a
real file under `content/uploads/` with server-derived dimensions, and
`Fields::IMAGE` is explicit that width and height never come from anywhere but
the upload record or the file on disk. Mark it with a `ponytail:` comment naming
the ceiling and the upgrade — read the newest real upload — so it reads as a
decision rather than an oversight.

`contact_form` is the one component whose point is its inputs, and those come
from `form:` in its schema rather than `fields:`. Pass `form_inputs` through by
reusing `Form::inputs($schema)` — the same call `public/index.php` already
makes. No CSRF token and no handler, so the form renders complete but inert;
`strict_variables` is `false` ([src/Cms.php:97](src/Cms.php)) so the missing token
renders empty instead of throwing.

## Infrastructure step (not code)

**Cloudflare Access must be extended to cover `/showcase.php`** — add the path
to the Access application that already guards `/admin.php`. Until that is done
the page returns 403 for everyone. Locally, DDEV's `AUTH_DEV_BYPASS=1` covers it
with no Cloudflare involved; per `CLAUDE.md` §5 that variable must stay `0`
everywhere else and must never be inferred from the request.

## Verification

1. `ddev start && ddev launch /showcase.php` — every component renders once, in
   the site's own CSS, each under its label and `type:` string.
2. Add a throwaway `components/zzz_test/` with a `schema.yml` and a one-line
   twig, reload, confirm it appears with no other change, delete it.
3. `curl -sI https://<site>/showcase.php` — expect `Cache-Control: no-store,
   private` and `X-Robots-Tag: noindex, nofollow`.
4. With `AUTH_DEV_BYPASS=0` and no Access JWT, expect **403**, not a render.
5. `curl -s https://<site>/sitemap.xml | grep showcase` — expect no match. Same
   for the site menu.
6. `ddev exec php bin/doctor` — stays green.
7. `ddev exec bash tests/run.sh` — the full suite, per `CLAUDE.md`. Add one
   check to `tests/07_shipkit.php` beside the existing script checks (line 505):
   request `showcase.php` with no JWT and assert 403.

Both `bin/doctor` and the suite must run **inside DDEV**: the project requires
PHP >= 8.4.1 and a typical host still has 8.3, where `vendor/autoload.php`
refuses to load at all.

## Deliberately not built

- **No panel screen and no link from `list.twig`.** This is a front-end URL; a
  developer bookmarks it.
- **No `auth: true` page key.** One authenticated path is not a page-level auth
  system, and §"Out of scope" in the plan has no page-type taxonomy for a
  reason.
- **No `_showcase.yml` sample-content file.** Generating from
  `Components::all()` is automatic; a content file is a second thing to keep in
  step. If the generated copy proves too thin, that is the upgrade path — a
  `_`-prefixed id is already non-routable ([src/Content.php:95](src/Content.php)) and
  already editable in the panel, so it costs no new concept.

## Lighter variant

Drop `templates/showcase.twig` and the `lang/` keys and render through the
existing `bare.twig`: ~50 lines in one new file. You still see every component
rendered, but with no label bars — and therefore no `type:` strings, which is
most of what makes the page worth opening.
