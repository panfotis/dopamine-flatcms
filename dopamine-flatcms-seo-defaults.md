# Site-wide SEO defaults, editable in the panel

> **Status:** proposed, not started. Nothing in this document has been
> implemented. Companion to `dopamine-flatcms-plan-v2.md`, which remains the
> phase bible; this is a single self-contained change to the head and the
> settings screen.

## Context

Every page's SEO output is resolved in one place — `Cms::seo()`
([Cms.php:737-767](src/Cms.php#L737)) — and the fallbacks already form a chain:

| tag | chain today |
|---|---|
| `og:image` | page `seo.og_image.src` → page's first `image` field (`pageSummary()`) → `config['site']['og_default']` → **nothing emitted** |
| `description` | page `seo.description` → first `textarea`/`richtext`, summarised to 155 chars → **nothing emitted** |
| `title` | page `seo.title` → page `title` |

Two gaps prompted this:

1. **The only site-level default is `og_default`** ([config.php:94](config.php#L94))
   — env-driven, therefore developer-only. A client cannot set their own share
   card. When it is unset, a page with no images emits **no `og:image` and no
   `twitter:card` at all**, so the link pastes as a bare grey box.
2. **There is no site-level default description.** A page with no prose blocks
   ships with no `<meta name="description">`.

The head also omits three cheap standard tags: `og:site_name`, `og:locale` and
`og:image:width`/`height` — the last matters because Facebook and LinkedIn defer
rendering a card until they have fetched and measured the image, and every
upload **already stores** its dimensions ([Admin.php:946](src/Admin.php#L946))
only for `Cms::seo()` to discard them at line 760.

Outcome: an admin-editable default sharing image and default description, stored
once for the whole site, slotting in as the **last** resort so a page's own
content still wins; plus the three missing head tags.

### Decisions taken

- **One shared file**, not per-locale. The default description is therefore
  written in one language — accepted trade-off; the image is the main event.
- **Fallback order unchanged**: the site default sits *after* the page's
  auto-picked first image. A real image from the page beats a generic card, and
  `tests/01_render.php:138-141` already pins that ordering.
- **Edited on the existing Settings screen**, admin only.
- `config['site']['og_default']` **stays** as the final env-driven resort, so a
  developer can preset a card before the client ever logs in.
- **No `twitter:title`/`description`/`image`** — X falls back to `og:*`
  automatically, so they are duplicate tags with a second thing to keep in step.

### Where the weight is

The feature itself is ~30 lines: the fallback walk, the head tags, the lang
keys. The other 60% of the diff is that this is the project's **first writable
settings store** — a new action, a write path, an editable card, and the
lockdown tests any new write endpoint has to earn. That cost is unavoidable
given the client must set the card themselves; if they never do,
`SITE_OG_DEFAULT` in the env already does this today and none of the rest is
needed.

The read-only docblock at [Admin.php:680-692](src/Admin.php#L680) argues that
read-only *is* the feature, on the grounds that `config.php` resolves from the
environment and a value edited in the panel would be overwritten on the next
boot. That reasoning still holds for `config.php` and nothing below weakens it:
`content/site.yml` is a separate file that no env var feeds, so the config
tables stay read-only and developer-owned. The docblock and `settings.intro`
both need rewording to say so.

---

## Design

### 1. Storage — `content/site.yml`

Sibling of `content/redirects.yml`. A missing file is a no-op, not an error.

```yaml
# Site-wide SEO defaults. Edited in /admin → Settings.
seo:
  description: 'Δημιουργούμε ιστοσελίδες που φορτώνουν γρήγορα.'
  og_image: {src: /uploads/2026/og-card-ab12.jpg, width: 1200, height: 630}
```

### 2. Field schema — filtered, not redeclared

New `Admin::siteSchema()` beside `seoSchema()` ([Admin.php:429](src/Admin.php#L429)):

```php
private function siteSchema(): array
{
    $fields = array_intersect_key(
        Components::seoFields(),
        array_flip(['description', 'og_image'])
    );

    // The same two fields asking a different question: the SEO hints answer
    // "what if this page says nothing", these answer "what if no page does".
    $fields['description']['hint'] = 'site.description_hint';
    $fields['og_image']['hint']    = 'site.og_image_hint';

    return ['label' => 'Site defaults', 'fields' => $fields];
}
```

Filtering `Components::seoFields()` ([Components.php:102](src/Components.php#L102))
rather than adding a `Fields::SITE` const keeps `og_image`'s `decorative: true`
and its `Fields::IMAGE` sub-schema coming from the single declaration in
`Fields::SEO` ([Fields.php:150](src/Fields.php#L150)) — the same "a second copy
is how `text_image` grew a stray alt field" reasoning that `seoFields()` itself
is documented with.

`title`, `noindex` and `canonical` are deliberately excluded: a site-wide
canonical is simply wrong, and site-wide noindex already exists as
`config['site']['noindex']` ([config.php:89](config.php#L89)).

### 3. Read path — `Cms::siteDefaults()`

New memoised method, locating the file the same way the redirects read does at
[Cms.php:506](src/Cms.php#L506):

```php
private ?array $siteDefaults = null;

/** Site-wide SEO defaults, or the empty shape when nobody has set any. */
public function siteDefaults(): array
{
    if ($this->siteDefaults !== null) {
        return $this->siteDefaults;
    }

    $file = $this->config['paths']['content'] . '/site.yml';
    $data = is_file($file) ? (Yaml::parseFile($file) ?? []) : [];

    return $this->siteDefaults = $this->withDefaults(
        ['fields' => Components::seoFields()],
        (array) ($data['seo'] ?? [])
    );
}
```

Then in `Cms::seo()`, replace the one-line `$src` resolution at
[Cms.php:759-762](src/Cms.php#L759) with an explicit first-non-empty walk, so the
chain reads as the list it now is and the dimensions survive:

```php
$defaults = $this->siteDefaults();

$seo['description'] = $seo['description']
    ?: ($from['description'] ?: (string) $defaults['description']);

// Four sources, in the order that lets a page speak for itself first. The
// last is the env-set card, which is a bare string with no dimensions.
$image = ['src' => ''];
foreach ([$seo['og_image'], $from['image'], $defaults['og_image'],
          ['src' => (string) $this->config['site']['og_default']]] as $candidate) {
    if ((string) ((array) $candidate)['src'] !== '') {
        $image = (array) $candidate;
        break;
    }
}

$src = (string) $image['src'];
$seo['og_image'] = [
    'src'    => $src === '' || str_starts_with($src, 'http') ? $src : $base . '/' . ltrim($src, '/'),
    'width'  => (int) ($image['width'] ?? 0),
    'height' => (int) ($image['height'] ?? 0),
];

// For og:locale. Optional per language, so a config that has not set one
// emits no tag rather than a wrong one.
$seo['locale'] = (string) ($this->config['locales'][$this->locale]['og_locale'] ?? '');
```

`pageSummary()` ([Cms.php:785](src/Cms.php#L785)) returns
`['description' => …, 'src' => …]` today. Change it to return the whole image
array as `image`, so dimensions flow from the auto-picked source too — it
already has `$value` in hand at line 800 and merely narrows it to `src`.

The docblock at [Cms.php:714-736](src/Cms.php#L714) enumerates the fallback
chain in prose and must be updated with it.

### 4. Write path

**Dispatch** — one more arm in `dispatch()` ([Admin.php:145](src/Admin.php#L145)):

```php
'site_save' => $this->siteSave($request, $user),
```

and `'site_save'` added to `MUTATIONS` ([Admin.php:40](src/Admin.php#L40)) so it
takes the content lock. A separate action rather than a POST branch inside
`settings()`, precisely so `settings` stays out of `MUTATIONS` and merely
*viewing* the screen does not take an exclusive lock.

**Handler** — mirrors `save()` but with no page, no baseline and no advisory
lock, since nothing else writes this file:

```php
private function siteSave(Request $request, array $user): Response
{
    $this->requireAdmin($user);
    $this->checkCsrf($request);

    $file   = $this->cms->config['paths']['content'] . '/site.yml';
    $stored = is_file($file) ? (array) ((Yaml::parseFile($file) ?? [])['seo'] ?? []) : [];

    // The same walk as every other schema in this class, so an og_image src
    // outside media_bases is dropped here exactly as it is on a page save.
    $seo = $this->cleanValues(
        $this->siteSchema(),
        $stored,
        (array) $request->request->all('seo'),
        $this->context(),
        $user['role']
    );

    Content::writeYaml($file, ['seo' => $seo]);
    unset($_SESSION['uploads']);

    // Every page's head carries these, and every page carries the `site` tag.
    $purge = $this->cms->cf->purge(['site']);

    return new RedirectResponse('?action=settings' . ($purge['ok']
        ? '&ok=' . urlencode($this->cms->lang->t('flash.site_saved'))
        : '&warn=' . urlencode($purge['message'])), 303);
}
```

`cleanValues()` ([Admin.php:404](src/Admin.php#L404)) and `context()`
([Admin.php:208](src/Admin.php#L208)) are reused verbatim — `context()` is what
carries `$_SESSION['uploads']`, which remains the only place an image's
dimensions may come from.

> A refusal from `cleanValues()` throws and lands on the generic error handler
> rather than re-rendering the form with per-field messages. Both fields are
> optional, so the only way to reach it is a forged `src`. Add the per-field
> re-render only if a required field ever appears on this screen.

**Atomic write** — `Content::save()` ([Content.php:257](src/Content.php#L257))
already does tmp-write + `rename()` but is page-scoped. Extract its body into a
static `Content::writeYaml(string $file, array $data, string $header = …)` and
have `save()` call it. One implementation, no second copy of the tmp/rename
dance and no second place for the atomicity to drift.

### 5. Panel form

**New partial `templates/admin/_editor_foot.twig`** — move the `T` string object
and `{{ source('admin/editor.js') }}` out of
[edit.twig:287-311](templates/admin/edit.twig#L287) verbatim, comments and all;
`edit.twig`'s `{% block foot %}` becomes a one-line include.

**`templates/admin/settings.twig`** gains an editable card *above* the existing
read-only tables:

- `{% import 'admin/edit.twig' as f %}` at the top —
  [edit.twig:181](templates/admin/edit.twig#L181) declares `field()` as a
  top-level macro, so it imports cleanly and brings the entire image picker,
  its upload button and its clear button with it.
- Called as
  `f.field(def, 'seo-' ~ name, 'seo[' ~ name ~ ']', seo[name]|default(''), false, [], [], errors|default({}))`
  — the `pages`/`page_ids` arguments are empty because neither field is a link.
- `{% block foot %}{% include 'admin/_editor_foot.twig' %}{% endblock %}`.

**Three hard requirements from `editor.js`**, which is not defensive at the top:

| line | requires |
|---|---|
| [editor.js:18](templates/admin/editor.js#L18) | a form with `id="editor"` — `form.querySelector` throws otherwise |
| [editor.js:20](templates/admin/editor.js#L20) | a `[name=csrf]` input inside that form |
| [editor.js:98,110,113](templates/admin/editor.js#L98) | an element with `id="status"`, written unguarded by the upload handler |

So the form is:

```twig
<form method="post" id="editor" enctype="multipart/form-data">
  <input type="hidden" name="action" value="site_save">
  <input type="hidden" name="csrf" value="{{ csrf }}">
  …fields…
  <button class="btn">{{ t('edit.save') }}</button>
  <span class="status" id="status"></span>
</form>
```

Everything else in `editor.js` is `querySelectorAll` (an empty list is fine) or
delegated off `data-*`, so no repeaters, richtext or block cards are needed —
which is the payoff of the file being data-attribute driven.

`settings()` ([Admin.php:693](src/Admin.php#L693)) must now also pass `csrf`,
the site field set and the current values, and render the `ok`/`warn` flash the
redirect carries back.

### 6. Head tags — one file now

> **Updated:** the head was extracted from the two layouts into
> [theme/head.twig](theme/head.twig), which both include. This edit lands there
> once, not in `layout.twig` and `bare.twig` — neither of which contains a head
> any more. `tests/01_render.php` now asserts on rendered output that every
> layout publishes the identical head, rather than comparing the two files.

The edit itself is unchanged:

```twig
<meta property="og:site_name" content="{{ site('name') }}">
{% if page.seo.locale %}<meta property="og:locale" content="{{ page.seo.locale }}">{% endif %}
…
{% if page.seo.og_image.src %}
<meta property="og:image" content="{{ page.seo.og_image.src }}">
{% if page.seo.og_image.width %}
<meta property="og:image:width" content="{{ page.seo.og_image.width }}">
<meta property="og:image:height" content="{{ page.seo.og_image.height }}">
{% endif %}
<meta name="twitter:card" content="summary_large_image">
{% endif %}
```

**config.php** — one optional key per language at
[config.php:112-115](config.php#L112):

```php
'el' => ['label' => 'Ελληνικά', 'prefix' => '', 'default' => true, 'og_locale' => 'el_GR'],
'en' => ['label' => 'English', 'prefix' => '/en', 'fallback' => 'default', 'og_locale' => 'en_US'],
```

### 7. Lang — `lang/en.php` and `lang/el.php`

New keys, following the `seo.*` block at [en.php:190-200](lang/en.php#L190):

- `site.title`, `site.intro` — the card heading and its one line of copy
- `site.description_hint`, `site.og_image_hint` — the two overridden hints
- `flash.site_saved`

Reword `settings.intro` ([en.php:32](lang/en.php#L32), [el.php:28](lang/el.php#L28)),
which currently promises the whole screen is developer-owned and read-only.

---

## Tests

`tests/run.sh` is the runner.

- **tests/01_render.php:55-149** pins every fallback layer, including the
  `og_default` case at :138-141 — that one still passes unchanged, since the
  priority order is untouched. Add: a `site.yml` default applying to an
  image-less page; the page's own first image still beating it; a missing
  `site.yml` being harmless; and `og:site_name` / `og:locale` /
  `og:image:width` present in **both** layouts (the existing head-parity
  assertion covers the second layout for free).
- **tests/02_admin.php** — the settings screen renders the form; a valid POST
  writes `content/site.yml` with the expected shape.
- **tests/03_lockdown.php** — mirror the existing SEO cases at :131-212 for the
  new endpoint: an `og_image.src` outside `media_bases` stored as `''`; a
  non-admin `site_save` refused; a missing or wrong CSRF refused; undeclared
  keys dropped.

## Seeds and docs

- `bin/new-site` need **not** seed a `site.yml` — absent means empty defaults,
  and that path is covered by a test.
- `bin/doctor` — optional: warn when neither `site.yml` nor `og_default` is set,
  since that is exactly the "links paste as a grey box" state. Nice-to-have.
- Grep `README.md` and `CLAUDE.md` for the fallback chain and for the "settings
  is read-only" claim; update both.

## Verification

1. `cd dopamine-flatcms && tests/run.sh` — all suites green.
2. Start the dev server, then `/admin.php?action=settings` as an admin: upload a
   default card, save, confirm `content/site.yml` is written with `src` **and**
   `width`/`height`.
3. `curl -s localhost:8080/about | grep -E 'og:|twitter:'` on a page with no
   images — the default card, `og:site_name`, `og:locale` and the two dimension
   tags must all be present.
4. Same curl on a page **with** an image — its own image must still win.
5. `rm content/site.yml` and reload — the page still renders, falling through to
   `og_default` or to no `og:image` at all.
6. As an `editor` (non-admin), confirm `?action=settings` and a forged
   `site_save` POST are both refused.
