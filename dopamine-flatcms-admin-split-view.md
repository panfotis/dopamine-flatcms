# Split-screen admin with per-component edit pencils

> **Status:** proposed, not started. Nothing in this document has been
> implemented. Companion to `dopamine-flatcms-plan-v2.md`, which remains the
> phase bible; this is a single self-contained change to the admin panel.

## Context

The panel today is a single-column form at `?action=edit`: an SEO `<details>`, a
page-title card, then one card per block. To see the result you click "Προβολή
σελίδας" ([edit.twig:110](templates/admin/edit.twig#L110)) and
alt-tab to another window. For a client editing a hero headline that is a slow
loop, and on a page with six blocks it is not obvious which card drives which
part of the page.

The ask was "make it feel like TinaCMS". Taking the two halves of Tina that fit
this codebase, and deliberately not the third:

- ✅ Split screen — form left, the real page right
- ✅ An edit pencil on each component; click it and the sidebar filters to that block
- ❌ Add / remove / reorder blocks — **out of scope**

The "+" button and drag handles contradict the core rule in `CLAUDE.md` ("the
client cannot add, remove, reorder or retype a component… enforced on save, not
merely hidden in the UI"). `Admin::save()` iterates `$page['blocks']` read from
disk and never the request ([Admin.php:351](src/Admin.php#L351)),
and `tests/02_admin.php:155-159` asserts those controls do not exist in the HTML.
Nothing below weakens either.

**This is the deliberately small version.** An earlier draft added a live
preview endpoint that re-rendered on every keystroke: ~120 lines of JS, a new
POST action, four JS gotchas and a Cloudflare Access edge case. Everything the
ask actually asked for turns out to be nearly free *without* it. The live
preview is written up at the bottom as a phase 2 that bolts on later without
redoing any of this.

Outcome: same save model, same lockdown guarantees, ~30 lines.

---

## Design

Four small pieces. No new PHP action, no new dependencies, no build step, no
npm — consistent with the dependency allowlist in `CLAUDE.md`.

### 1. Annotate blocks in `renderPage()` — always, not just in the panel

[Cms.php:237-250](src/Cms.php#L237) wraps each block's rendered
HTML:

```html
<div data-block-id="{{ block.id }}" style="display:contents">…</div>
```

`display:contents` generates no box, so component CSS that depends on layout
position (`.hero{position:relative}`, `.hero:not(:has(.hero-media))`) is
unaffected — the wrapper is invisible to layout, but events bubble to it and
`querySelector('[data-block-id=…]')` finds it.

**Always on, with no `$annotate` flag.** The attribute is identical for every
visitor — static markup, no auth, no personalisation — so it is cache-safe in
public HTML and costs a few bytes per block. A flag would mean threading a
boolean through the one render path to save those bytes. Not worth it.

This is what removes the need for a preview endpoint: the admin iframe can point
at the *real page URL* and still find its blocks.

Verified not to break the render suite: `tests/01_render.php:26` and `:219` count
`<section` occurrences, and the wrapper is a `<div>`.

### 2. Make the layout wrapper overridable

[_layout.twig:93](templates/admin/_layout.twig#L93) hardcodes
`<div class="wrap">`. Make it:

```twig
<div class="{% block wrapclass %}wrap{% endblock %}">{% block content %}{% endblock %}</div>
```

`list.twig` / `revisions.twig` / `denied.twig` / `error.twig` inherit the default
and do not change.

### 3. Split grid in `edit.twig`

Override `wrapclass` to a full-bleed CSS grid:

- Left ~440px: the existing `<form id="editor">`, markup **unchanged**.
- Right `1fr`: `<iframe id="preview" src="{{ page.slug }}">`, sticky, full height.
- `@media (max-width: 900px)`: collapse to one column, hide the iframe. The panel
  degrades to exactly what it is today on a phone.

Grid CSS goes in the existing `<style>` block in `_layout.twig` — the codebase
keeps all panel CSS inline there; do not introduce a static asset directory. The
savebar stays `position:fixed` but constrains to the sidebar column.

Each block card in the loop at
[edit.twig:75-106](templates/admin/edit.twig#L75) gets
`data-card="{{ block.id }}"` so the two panes can find each other. This is a
navigation hook, not a structural control — it is not `data-reorder` and it posts
nothing.

**Refresh-on-save is free.** Save already 303-redirects back to `?action=edit`
([Admin.php:417](src/Admin.php#L417)), so the whole admin
document reloads and the iframe reloads with it. `browser_max_age` is `0`
([config.php:224](config.php#L224)), so the browser refetches
rather than serving stale HTML.

### 4. Pencils and click-to-focus (~25 lines of vanilla JS)

`edit.twig` already carries ~140 lines of dependency-free ES in
`{% block foot %}` ([edit.twig:299-440](templates/admin/edit.twig#L299)).
Add to it, on the iframe's `load` event. The iframe points at a same-origin URL,
so the parent can reach `iframe.contentDocument` directly — no `postMessage`.

- Inject a small `<style>` into the preview document: dashed outline on
  `[data-block-id]:hover`, plus the pencil button styling.
- Inject one `<button>✎</button>` per `[data-block-id]`, positioned against the
  block's bounding box, revealed on hover.
- One `click` listener on the preview document: `e.preventDefault()` on
  everything (nav links must not navigate the preview away), then
  `e.target.closest('[data-block-id]')` → focus that block.

Both injections are built **from the parent**, so `hero.twig` and the other
component templates stay untouched and the public site never sees any of it.

"Focus a block" = set `hidden` on every other `.card`, and show a breadcrumb
`← Σελίδες / {{ page.title }} / {{ block.label }}` above the form. Clicking the
breadcrumb root clears the filter. Reverse direction: focusing an input scrolls
the preview to its block via `scrollIntoView()`.

**Hazard to respect:** hide cards with the `hidden` attribute or CSS only. Never
`disabled`, never remove inputs from the DOM. Hidden inputs still submit, and the
save path depends on it.

---

## Files touched

| File | Change |
|---|---|
| [src/Cms.php](src/Cms.php) | wrap each block in `renderPage()` (~4 lines) |
| [templates/admin/_layout.twig](templates/admin/_layout.twig) | `{% block wrapclass %}`, split-grid CSS |
| [templates/admin/edit.twig](templates/admin/edit.twig) | wrapclass override, iframe, breadcrumb, `data-card`, ~25 lines JS |
| [tests/01_render.php](tests/01_render.php) | assert the annotation |
| [tests/02_admin.php](tests/02_admin.php) | assert the split view adds no structural controls |

No new files, no new PHP action, no new dependencies.

**Effort:** an afternoon, low risk. Nothing here touches the save path, the
sanitiser, auth or caching.

---

## Verification

```bash
ddev exec bash tests/run.sh    # all 7 files green, not just the new checks
ddev exec php -l src/Cms.php
ddev launch /admin.php?action=edit&page=home
```

New checks:

- `01_render.php` — a rendered page contains `data-block-id="hero"`; the existing
  `substr_count($html, '<section') === 5` assertions at `:26` and `:219` still
  pass; the unknown-component page at `:217` emits no wrapper for the skipped
  block.
- `02_admin.php` — the edit view contains `data-card="hero"` and the iframe; the
  `missing()` assertions at `:155-159` still pass. Confirm the breadcrumb string
  is not "Προσθήκη ενότητας" and nothing emits `data-reorder`.

Manual: click the hero in the preview, confirm the sidebar filters to it and the
pencil appears on hover; click the breadcrumb root, confirm all cards return;
edit the headline, save, confirm the iframe shows the new text after the redirect;
narrow the window below 900px and confirm it collapses to today's layout.

One production-only note: the edge purge fires synchronously before the redirect
([Admin.php:415](src/Admin.php#L415)), but Cloudflare's purge is
not instant, so a save could momentarily reload a stale iframe. If that ever shows
up, `src="{{ page.slug }}?v={{ baseline }}"` fixes it in one line — the baseline
is the file hash, so it busts only when content actually changed. Not worth adding
pre-emptively.

---

## Phase 2, if and only if "save to see it" gets annoying

Everything above is unchanged by this — the annotation, the split layout and the
click-to-focus are identical either way. This is purely additive.

Add `?action=preview`: a **read-only** POST that is the conflict re-render path
at [Admin.php:204-213](src/Admin.php#L204) with
`Cms::renderPage()` on the end instead of `edit.twig`. CSRF-checked, loads the
page from disk, runs each block through `cleanValues(..., enforceRequired: false)`,
renders. **Not** in `Admin::MUTATIONS` — it writes nothing, takes no lock,
snapshots nothing. Safe because the HTML is the output of the identical sanitiser
that would run on save, and `Fields::map()` enforces `editable` per field at every
depth ([Fields.php:168](src/Fields.php#L168)).

Then debounce form input at 250ms and swap the result into the iframe. The five
things that make this the expensive half, all researched already:

1. **Swap `<main>`, not the document.** Replacing the whole page every keystroke
   re-parses CSS and re-decodes the hero image — a visible flash twice a second.
   Parse the response with `DOMParser` and assign only `main.innerHTML`. Scroll
   position survives for free.
2. **`fd.delete()` the file inputs.** `new FormData(form)` on a
   `multipart/form-data` form includes the selected image bytes; left in, every
   keystroke re-uploads the picture.
3. **Sync the richtext `contenteditable` on `input`.** The sync at
   [edit.twig:326-351](templates/admin/edit.twig#L326) only has
   to be correct at submit time today. If it fires on `blur`, richtext will not
   live-preview at all.
4. **`AbortController`.** Debounce alone does not stop a slow response landing
   after a fast one and painting stale HTML.
5. **Cloudflare Access session expiry.** When the session lapses Access returns a
   **302 to the login page**, not a 401. `fetch()` follows it, so the preview
   would silently paint the Cloudflare login screen. Check `res.redirected`, stop
   the debounce loop and show a banner. Note this would be a net *improvement* on
   today — the same expiry already breaks Save and loses the form — but it is
   invisible in dev, because `.ddev/config.yaml` sets `AUTH_DEV_BYPASS=1` and
   `Auth::bypassed()` short-circuits the entire Access path
   ([Auth.php:110-114](src/Auth.php#L110)).

Access needs no configuration change for any of this: the POST is same-origin to
`/admin.php` so the `CF_Authorization` cookie rides along, and the Access app is
scoped to path `admin.php` only (`README.md:346`), so the preview's
`/img.php?src=…` requests stay anonymous and work.

---

## Deliberately skipped

- **Live preview on every keystroke** — see phase 2 above. The four JS gotchas and
  the Access edge case exist *entirely* to serve live typing; everything else the
  ask wanted is nearly free without them. Ship the small version, use it for a
  week, then decide with evidence.
- **Add / remove / reorder blocks** — contradicts the core rule; a product
  decision, not a UI patch. Would require `Admin::save()` to accept structure from
  the request and would break `tests/03_lockdown.php`.
- **Edit pencils on the live public site** (Drupal-style contextual links) —
  [public/index.php](public/index.php) does no auth at all today
  and public pages are edge-cached with cache tags via `Cms::cacheHeaders()`
  ([Cms.php:529](src/Cms.php#L529)), asserted by
  `tests/06_production.php`. Pencils in public HTML create an authenticated
  variant of every URL: JWT verification on every page request, a Cloudflare
  cache-bypass rule for the `CF_Authorization` cookie, and a rewritten cache
  contract. The `data-block-id` annotation is cache-safe precisely because it is
  the same for everyone; an edit button would not be.
- **Form-in-an-overlay-iframe over a full-width page** — considered and dropped in
  favour of Tina's sidebar layout. Worth recording that it *would* have worked:
  `Fields::map()` keeps the stored value for any field absent from the POST
  ([Fields.php:169](src/Fields.php#L169)), so a form posting only
  `blocks[hero][…]` writes hero and leaves every other block untouched, with no
  change to `Admin::save()`.
- **Libraries.** htmx would do a debounced partial swap declaratively, but it
  swaps into the parent DOM and the iframe is what isolates the site's CSS from
  the panel's. Idiomorph (~5kb, no build) is worth remembering only if phase 2
  happens and `main.innerHTML` visibly flickers. TinaCMS itself is React + Next +
  Node — a rewrite, not an integration. Tiptap/Lexical need a bundler; there is no
  npm here.
- **Panel i18n** — breadcrumb strings stay hardcoded Greek per `CLAUDE.md` ("do not
  start introducing keys piecemeal"); i18n lands with the repo split.

## Unrelated doc bug spotted

`README.md:338` claims that with `AUTH_DEV_BYPASS` on, "anything arriving with a
loopback address is treated as logged in." That is false and contradicts
`CLAUDE.md` rule 5 — `Auth::bypassed()` reads the env var only and there is no
`REMOTE_ADDR` check. Worth fixing the sentence so nobody reintroduces the loopback
check it describes. Say the word and I'll include it.
