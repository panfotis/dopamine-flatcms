# Taking a page or a block off the site

> **Status:** proposed, not started. Companion to `dopamine-flatcms-plan-v2.md`
> and to `dopamine-flatcms-admin-block-management.md`, whose structural-save
> machinery the block half of this reuses. Like that document, this **lifts a
> bullet from the "Out of scope" list** and says why.

## Context

There is no unpublish. A page file existing in `content/pages/<locale>/` **is**
the publish state, and that was a deliberate decision, recorded in three places:

> `dopamine-flatcms-plan-v2.md:93` — Draft/publish workflow — saving publishes
> `README.md:621` — Draft / publish workflow — saving publishes
> `CLAUDE.md:205` — Do not add a feature from the "out of scope" list even if it seems easy.

Three things look like unpublish and are not. `seo.noindex` hides a page from
search engines only — `lang/en.php:198` says it outright: *"Anyone with the link
still sees it — this is not a lock."* `private: true` only forces `no-store`
cache headers ([Cms.php:1154](src/Cms.php#L1154)). Omitting the `nav:` key drops
a page from the menu while leaving it fully routable, which is how a landing page
stays reachable but unlisted ([Cms.php:505](src/Cms.php#L505)).

So today, taking a page down means deleting the file and adding a redirect, and
taking a block down means deleting it from the YAML and trusting revisions. Both
are developer work, and both are destructive.

**The two halves of this are different problems and get different mechanisms**,
because a page is a file and a block is not.

---

## Part A — Page: move the file, do not add a flag

A flag would have to be read and respected in roughly seventeen places: routing,
`Content::list()`, `findBySlug()`, nav, the sitemap and its alternate groups,
`pageUrl()`, `alternates()`, the admin page table, the link picker, `bin/doctor`,
and the fallback-locale branch in `public/index.php`. Every one of them is a
chance to forget, and one of them —
`Cms::alternates()` — bypasses `Content::list()` entirely and would have kept an
unpublished page in the language switcher without any test noticing.

Moving the file to `content/pages/<locale>/unpublished/` collapses all of it to
nothing. Three properties of the existing code make this work, and all three were
verified:

1. **`Content::scan()` is not recursive.** It globs
   `pagesDir() . '/*.yml'` ([Content.php:190](src/Content.php#L190)), so a
   subdirectory is invisible to it. Everything fed by `list()` — routing, nav,
   sitemap, link picker, `pageUrl()`, the panel's page table — drops the page for
   free.
2. **`Content::load()` builds its path directly** and returns `null` when the
   file is not there ([Content.php:219-222](src/Content.php#L219-L222), path from
   [:143](src/Content.php#L143)). This is what fixes `alternates()`, the one path
   that does not go through `list()`: it calls `contentIn($code)->load($id)`, gets
   `null`, and drops the language alternate on its own.
3. **Locales come from an explicit config map**
   ([config.php:128](config.php#L128)), not from scanning directories, so a
   directory named `unpublished` can never be mistaken for a language.

The result is not "a filter we remembered to apply everywhere". It is the absence
of a file — a case every one of those code paths has always had to handle,
because a page can always have been deleted.

Two further things fall out for free. **Revisions survive**, because
`revisionsDir()` is `.revisions/<locale>`
([Content.php:137](src/Content.php#L137)), a sibling of `pagesDir()` — so
unpublishing does not touch history and republishing finds it intact. And
**`bin/doctor` needs no change**: it currently fails a redirect whose `from`
shadows a live page ([doctor:797](bin/doctor#L797)), and after a move the page
genuinely is not live, so adding a redirect for the freed slug becomes legal
without relaxing the check. A flag would have forced that rule to be rewritten.

Git sees a rename, so history stays clean and the hourly content push keeps
backing the file up.

### What to build

- `Content::unpublish(string $id)` / `republish(string $id)` — a `rename()` under
  the site-wide content lock. Both belong in `Admin::MUTATIONS`.
- `Content::unpublished(): array` — one scan of the subdirectory, so the panel can
  list what is down and offer it back. This is the **only** new listing; nothing
  existing changes shape.
- Two admin actions, admin-only, CSRF-checked, plus a section in `list.twig`.
- **Purge the edge on unpublish** — `Cloudflare::tagFor($id)` and `site`, the same
  call `save()` already makes. Without it the page is served from Cloudflare until
  the TTL expires, which is the whole failure the feature exists to prevent.
- No change to `public/index.php`: `findBySlug()` misses and the existing 404 path
  runs. A 410 would be more correct but needs a reason to exist; a redirect in
  `redirects.yml` is the better answer when the content moved somewhere.

### The one check that is genuinely required

**Republish must refuse a slug that has been taken in the meantime.**

Two pages can already share a slug today — there is no write-time validation
anywhere in `Content.php` or `Admin.php`, and `findBySlug()` returns whichever the
sort happens to put first. It is not a problem *today* because page creation is
developer-only and `bin/deploy.sh:99` runs `bin/doctor` before the switch, where
[doctor:599-602](bin/doctor#L599) fails on duplicates.

Republish would be the first **runtime** path to that state, and doctor does not
run at runtime. This is the same principle §4 of
`dopamine-flatcms-admin-block-management.md` establishes: *where the panel takes
over something that previously only happened through a deploy, it inherits the
deploy's checks.* Reuse doctor's own normalisation — `rtrim($slug, '/') ?: '/'`.

Also worth handling, though not a correctness bug: a `link` field pointing at an
unpublished page resolves to `''` and renders as plain text, which is already the
behaviour for a deleted page. The panel's link picker should say "unpublished"
rather than silently dropping the option.

---

## Part B — Block: a flag, and one line on the public site

A block has no file to move, so this half does need a flag. A flag is also the
*right* answer here, because it keeps the block's position in the order — a
hidden block comes back exactly where it was, which a separate list could not
promise.

**The entire public-site change is one `continue`** beside the existing
unknown-type skip in `Cms::renderBlocks()`
([Cms.php:728](src/Cms.php#L728)) — and it covers `_header` and `_footer` too,
since `renderGlobal()` delegates there.

The panel side reuses the block-management machinery wholesale: the same
structural parameter, the same admin-only rule, the same exclusion of globals,
the same tests. It sits beside whichever version of
`dopamine-flatcms-admin-block-management.md` gets built — it is half of that
document's Version 1 (reorder + hide), and a fourth verb in its Version 2.

**Which version it belongs to changes what it is for.**

In Version 2 it is a safety valve: that plan lets an admin delete a block,
recoverable only through revisions. Hide is reversible with one click, and it is
what a client actually wants nine times out of ten — take the promotion banner
down for a month, put it back. Without it, "temporarily remove" and "permanently
delete" are the same button.

In Version 1 it is the *whole* removal story, and a better one — nothing is ever
destroyed, so nothing has to be recovered.

### The three non-obvious consequences

1. **`Form::blockOn()` must respect it.** It is called from
   `public/index.php:98` and from `Cms::cacheHeaders()`. A hidden form block would
   otherwise keep the page on `no-store` and keep starting a session for a form
   nobody can see. It also feeds the one-form-per-page count, so hiding one form
   should free the page to carry another.
2. **`Cms::pageSummary()` must skip it.** It walks blocks to derive the fallback
   SEO description and the og:image. A hidden block must supply neither, or the
   share card describes content no visitor can reach.
3. **Component assets stop shipping, correctly.**
   `$this->assets?->component($schema)` sits *after* the skip point in
   `renderBlocks()`, so a hidden block contributes no CSS or JS on its own. Worth
   an assertion in `tests/10_assets.php` so it stays true.

`bin/doctor` needs the key in its block shape validation, and its form-block count
should agree with whatever `blockOn()` decides.

---

## Why not page-level too, via a flag — and why not block-level via a move

Recording both, so neither gets re-proposed:

- A **page flag** is seventeen touch points against a rename's zero. There is no
  version of the flag that is cheaper, and the expensive parts are the ones
  easiest to forget.
- A **block move** — lifting hidden blocks into a separate YAML key — loses the
  block's position, so restoring it becomes a second decision the client has to
  make. The flag keeps the block where it is and changes one boolean.

## Deliberately skipped

- **Scheduled publishing** (go live at a date). Needs a scheduler; there is
  none, and cron for a brochure site is a maintenance burden with no client
  asking for it.
- **A draft state distinct from unpublished** — that is the editorial workflow the
  plan rules out at `:102` ("a client needing a real editorial workflow is a
  Statamic or WordPress project"). Unpublished/published is two states, not a
  workflow.
- **410 instead of 404.** Correct in theory; nothing consumes the difference today
  and `redirects.yml` is the better answer whenever the content actually moved.
- **Page creation and deletion from the panel.** Still adding and removing files,
  still developer-only. Unpublish is precisely the feature that makes deletion
  *unnecessary* for the client, so this stays closed —
  `tests/02_admin.php:673` stands.

## Verification

```bash
ddev exec bash tests/run.sh                # all ten files
ddev exec php -l src/Content.php src/Cms.php src/Admin.php
php bin/doctor                             # must stay green with pages unpublished
```

The assertions that matter, because they are the ones a flag would have got
wrong: after unpublishing a page, it is absent from `sitemap.xml`, from the nav,
from the link picker, from `<link rel="alternate">` **and** from the language
switcher; its URL 404s; a redirect for its old slug now passes doctor; its
revisions are still listed; and republishing restores all of the above. For
blocks: a hidden block renders nowhere, ships no CSS, supplies no og:image, and a
hidden form block leaves the page cacheable again.
