# Dopamine FlatCMS — Build Plan v2.1

**Supersedes v1.** Rewritten after two adversarial reviews of the plan and the
prototype, then amended with a production-readiness review. What changed and
why is in §13.

**Status:** Phases 0–3 done, **268 checks passing**. Next up is Phase 4 (media
core). Security fixes from the review applied.
**Audience:** Claude Code in VS Code, against DDEV. Read this and `CLAUDE.md`
before changing anything.

---

## 1. What this is

A flat-file CMS for small client sites (3–10 pages). No database, no build step.

- **Structure** — which components a page has, in what order, with what fields —
  lives in files, edited by the developer.
- **Content** — the values in those fields — is edited by the client in a panel.

The client cannot add, remove, reorder or retype a component. Enforced on save,
not merely hidden in the UI (§10).

### Working today

| Area | State |
|---|---|
| Component discovery, page rendering, slug routing | Done |
| Schema-driven admin forms | Done |
| Fields: `text` `textarea` `richtext` `image` `link` `select` `boolean` | Done |
| Save lockdown + sanitisation | Done, 73 tests |
| Cloudflare Access auth (JWT verified) | Done |
| Roles: `admin`/`editor` from `config/roles.yml`, unlisted email denied | Done (Phase 3) |
| `editable: admin`, enforced on save | Done (Phase 3) |
| Request/Response via `symfony/http-foundation` | Done (Phase 2) |
| R2 upload (hand-rolled SigV4) | Done |
| Edge cache purge on save (`Cache-Tag`) | Done |
| Cloudflare image transformations | Done — **replaced by GD in Phase 4**, see there |
| Revisions — snapshot, list and restore, admin-only | Done (Phase 3) |
| Security hardening from the review | Done, 65 tests |

### Not built, and needed before a client site ships

Deploy process · navigation/menus · redirects from old sites · 500 page ·
new-site scaffold · staging noindex. *(The editable site header and footer that
were on this list are Phase 6.5, done.)*

**These block launch. Phase 0 closes their production contracts and Phases 3–7
build them; they are not "later".** This includes SEO and a contact form, which
is why the pilot site is Phase 8 — see §6.

---

## 2. Decisions — do not relitigate

| Decision | Choice | Why |
|---|---|---|
| Runtime | PHP 8.4 + Twig on a Hetzner VPS | Existing stack; server already paid for |
| Storage | Flat YAML files | 3–10 pages; a database earns nothing |
| Auth | Cloudflare Access only (`cf_access` + `none` for dev) | Free to 50 users; no credential to manage |
| Roles | Explicit `admin` / `editor` allowlist; unlisted email denied | Authentication must not silently grant edit access |
| Media storage | **`content/uploads/`, committed to git**; R2 optional | One backup path for everything the client owns; ~50 MB a site |
| Media delivery | **GD derivatives** at a finite width allowlist, generated on demand, cached in `var/` | Bounds CPU/disk use and avoids Cloudflare's account-wide transformation quota |
| Image markup | **Always `<picture>`**, from one overridable Twig partial | WebP + JPEG needs it anyway; one shape beats per-component branching |
| Image storage | Object: `src`, `alt`, `width`, `height` | Alt can't be forgotten; dimensions kill layout shift |
| Multi-image | Own field type (`image_list`) | 30 photos through a generic repeater is unusable |
| Video | Embeds (YouTube/Vimeo) + muted loops ≤10 MB | Transcoding is a pipeline, not a feature |
| Embeds | Parsed `{provider, id}`, never pasted HTML | Client-supplied iframes are an XSS hole |
| Page identity | The **filename**, shared across locales | One identifier, nothing to keep in sync |
| Internal links | Store the page id, not the URL | A slug change must never break a link |
| Page types | None — a page is a free-form list of blocks | Constraints only pay off if clients compose pages |
| Site header/footer | **Global blocks** in `_header.yml` / `_footer.yml`, rendered into every layout | A logo, a phone number and a row of social links are content; a second storage mechanism for them is not |
| Global identity | A page id starting with `_` is **not routable** | One prefix rule, instead of a `routable:` key, a second directory, or a globals store carrying its own transaction, baseline and revision code |
| i18n | One content file per language | Allows genuinely different slugs per language |
| Locale resolution | **URL prefix only** | See Phase 9; host-based is a separate, later mode |
| Form delivery | SMTP relay **and** stored on disk | A bounced message must not vanish |
| Turnstile | `editable: admin` toggle, default off, keys in `.env` | Most sites never need it; a control the client can switch off is not a control |
| Submissions location | `var/submissions/`, gitignored | Visitor PII must not reach a git remote |
| Panel language | `t()` over a per-locale PHP array, picked by `admin_locale` config | ~90 strings; a catalogue system is the wrong shape for a flat map |
| Panel source language | **English**, `el` is a translation | The core is a distributed package; a Greek default is a fork waiting to happen |
| Panel language selection | **Per site, not per user** | One install, one client; Access gives no place to store a preference |
| Repo split | **After the pilot**, before Phase 9 | Validate the product before paying package/release overhead |
| Packages | Two: `flatcms` (engine) + `flatcms-skeleton` (`create-project`) | A library alone is not runnable; starter components need no repo of their own |

### Out of scope

- Adding/removing/reordering components from the panel — **including in a
  global.** `_header.yml` and `_footer.yml` are page files, so §10.1 and §10.3
  already cover them and are not relaxed because the file is shared
- Per-page header/footer overrides (that is what `layout:` is for), and any
  region beyond the two
- Self-service account management (Access decides who gets in)
- Draft/publish workflow — saving publishes
- A general media library (per-field upload only; `image_list` is a grid inside one field, not a shared asset browser)
- Blog/collection listing with pagination, tags, archives
- Nested repeaters (a `list` inside a `list`)
- Video transcoding, renditions, HLS, multipart upload
- Self-hosted video with sound or controls
- Search · e-commerce · memberships · comments
- Additional auth modes (§2.1)

A client needing a blog with pagination or a real editorial workflow is a
Statamic or WordPress project, not this.

### 2.1 Auth options considered and rejected

| Option | Why not, for now |
|---|---|
| Password login (`users.yml`, argon2id, no reset) | ~120 lines vs ~15 for roles-over-Access, and it publishes a guessable endpoint on 20 sites where today an unauthenticated request never reaches PHP. **Roles do not require it.** If a site can't use Cloudflare, add it then — roles key off an email either way. |
| Magic link / email OTP | Nearly free once the contact form brings `symfony/mailer`. Only worth it if a site can't use Cloudflare. |
| Cloudflare Tunnel + header trust | Attractive later — the origin loses its public port and `firebase/php-jwt` can go. A config change when Tunnel exists, not a reason to plan now. **If you adopt it, `AUTH_DEV_BYPASS` must be `0`** — see §10.7. |
| Authelia / Authentik / Keycloak | One SSO for 20 panels, but a service to run and patch. Revisit past 20 sites. |

Always keep your own address on every Access policy, so a client's mail problem
can't lock you out of their site.

---

## 3. Repository layout

**Single repo through the pilot.** Splitting earlier makes every phase an
edit-in-vendor / tag / bump cycle for a package whose product fit has not yet
been tested. Keep development versions at `0.x`; there is no semver stability
promise while Phases 2–8 still change contracts and storage.

After Phase 8, split into **two packages**. Tag `v1.0.0` only after the split's
cold-install test passes and the pilot has completed its first real edit,
deploy, backup and restore cycle. Phase 5 adopts the locale-directory page
layout even for a single-language site, so Phase 9 adds locales without another
page-storage migration:

```
dopamine/flatcms            the engine. src/, templates/admin/, lang/,
                            starter components/, tests/ + fixture site.
                            Lives in vendor/. Never edited from a site.

dopamine/flatcms-skeleton   the runnable project. public/index.php,
                            public/admin.php, config.php, .env.example,
                            templates/layout.twig, one demo page, .ddev/.
                            `composer create-project` target — copied once,
                            then it is the site and stops tracking upstream.
```

A client site is `create-project` output in its own git repo. That is not a
third package; it is just "each site is a repo", which is already true today.

```
pelatis-gr/                      ← from create-project, then yours
  composer.json                  → requires dopamine/flatcms
  vendor/dopamine/flatcms/       ← the engine
  components/                    ← this client's own components
  content/                       ← this client's content
  templates/layout.twig
  config.php  .env
  public/index.php  public/admin.php
```

That is the development/source layout. Production uses atomic releases with
client-owned state outside the release directory:

```
/var/www/pelatis/
  current -> releases/20260816-143000/
  releases/<timestamp>/                 # code + vendor; disposable
  shared/content/                       # its own private git repository
  shared/var/                           # cache, locks, submissions; never deployed
  shared/.env                           # secrets/config; never deployed or committed
```

`CONTENT_PATH` and `VAR_PATH` point the engine at the shared directories; nginx
aliases `/uploads/` directly to `shared/content/uploads/`. The site code
repository does not track `content/` in production. This is the concrete meaning
of "deploy code only": switching `current` can neither merge nor overwrite a
client save.

The content repository is separate because it has a different writer and
lifecycle. Its backup job takes a site-scoped lock, runs `git add -A` (not
`commit -am`, which misses new uploads and revisions), commits only when dirty,
pushes, and reports a failed push. A scheduled restore drill clones that remote
into an empty directory and verifies pages and images before declaring the
backup healthy.

PHP namespace `Dopamine\FlatCms\`, PSR-4 from `src/`. Twig's loader takes site
paths **before** package paths, so any admin template or starter component is
overridden by creating a file of the same name — no configuration, no
registration. Renovate watches the core repo and opens update PRs per site.

**Why two, not three.** An earlier draft had a third package,
`dopamine/flatcms-components`, for "shared starter widgets, overridable per
site". Its whole mechanism is Twig loader precedence — which works identically
when the components ship inside core. Splitting them buys a third tag cycle, a
third Renovate PR on every site, and a version-compatibility matrix between two
packages that are always released together. Fold them into `dopamine/flatcms`.

**Why a skeleton and not just a library.** A composer library is not runnable —
`composer require dopamine/flatcms` leaves you with `vendor/` and no docroot,
no config, no content. Every PHP project that is installable solves this with a
`create-project` skeleton (`symfony/skeleton`, `laravel/laravel`), and it is
what makes the goal — download it and it runs — literally true:

```bash
composer create-project dopamine/flatcms-skeleton mysite
cd mysite && ddev start && ddev launch /admin.php
```

The skeleton is a template, not a dependency: it is copied once and never
updated. Only the engine is a real dependency, and only the engine gets
Renovate PRs.

**Distribution stays private VCS for now** — a `repositories` entry pointing at
the private repo, no Packagist, no Satis. That is a distribution choice, not an
architectural one: going public later is a `composer.json` name, a Packagist
submission and **a license decision** — `composer.json` currently says
`proprietary`, which is correct for private use and must be changed
deliberately, not noticed after the fact. Nothing above changes either way.

**The split's own deliverables:**

1. `tests/fixtures/site/` with its own components and content. The current suite
   asserts on Greek strings from the demo content (`'Μικρά site, χωρίς βαρύ CMS'`,
   `substr_count($html,'<section') === 4`), so "tests pass from inside the
   package" is impossible without a fixture site.
2. **Panel i18n** — see §3.1. Hardcoded Greek is correct for 20 Greek sites and
   wrong the moment the core is a package someone else installs. This is that
   moment, and not before: doing it earlier means paying the per-string tax
   through four phases that are still churning the panel UI.
3. **The skeleton actually works from cold.** `composer create-project` into an
   empty directory, `ddev start`, and both the site and the panel render with
   the demo page — on a machine that has never seen this project. Test it by
   doing exactly that, not by reasoning about it. `bin/new-site` from Phase 5 is
   then just this, plus renaming.
4. **Release discipline.** The package remains `0.x` until this split. The first
   `v1.0.0` tag is cut only after the cold install and pilot upgrade pass; future
   content-format changes require backward-compatible reads plus a doctor fix,
   or a major version with an explicit migration.

### 3.1 Panel i18n

Not a phase of its own — it ships with the split, ~0.5 d.

```php
// lang/en.php — the source language. Flat map, one file per language.
return ['save' => 'Save', 'pages.all' => 'All pages', …];

// lang/el.php — a translation of it, same keys.
return ['save' => 'Αποθήκευση', 'pages.all' => 'Όλες οι σελίδες', …];

// Cms::__construct — $this->lang = require "lang/{$config['admin_locale']}.php";
public function t(string $key): string
{
    return $this->lang[$key] ?? $key;   // missing key renders the key, never fatals
}
```

Registered once as a Twig function; `'admin_locale' => env('ADMIN_LOCALE', 'en')`
in `config.php`.

**English is the default and the source language.** The panel is Greek today
because every site so far is Greek, but the core ships as a package and a Greek
default makes every non-Greek install start with a fork. `lang/en.php` is
authored first and is the file a new key lands in; `lang/el.php` is a translation
of it.

Consequence: **every Greek client site sets `ADMIN_LOCALE=el` in its `.env`** —
added to §12. Forgetting it is not silent (the client opens an English panel on
day one) but it is embarrassing, so it belongs on the checklist, not in folklore.

Keys stay symbolic (`pages.all`), not English-string-as-key. Using the English
copy as the key would drop `lang/en.php` entirely, but then editing English
wording silently un-translates the Greek — and Greek is the language the client
actually reads. A symbolic key that loses its translation renders `pages.all`:
ugly, and impossible to miss. Loud beats tidy for the language that matters.

**Selection is a config value, not a UI dropdown.** A selector implies two admins
in one install wanting different languages. Each install serves one client, and
Cloudflare Access hands over an email with no account record — so a dropdown
means first inventing a preference store to solve a problem no install has. Add
it if a site ever has two admins in different languages, which is also when
there would be somewhere to persist the choice.

Scope of the migration pass, so it is not discovered mid-split:

- ~40 strings across the four `templates/admin/*.twig` files
- 13 strings in `Admin.php` and `Content.php`
- **`Cloudflare.php` returns English into the Greek panel today.** `purge()`'s
  three messages reach the client verbatim via `Admin::save()`'s `&warn=`. They
  become keys like everything else — this is a live bug the pass happens to fix.
- `Auth.php`'s origin-bypass 403 stays English. A client arriving through Access
  never sees it; it is for whoever hit the origin directly.
- Two assertions in `05_concurrency.php` match on Greek panel copy
  (`'Η σελίδα άλλαξε από αλλού'`, `'είναι ακόμα στη'`). Rewrite them against
  `t()` output. Per §11 the assertion may change; the case may not be dropped.

#### Storage formats considered and rejected

| Format | Why not, for now |
|---|---|
| **`.po`/`.mo` (gettext)** | Needs `.po` → `.mo` compilation — a build step, and "no build step" is the first line of §1. `ext-gettext` additionally needs the locale generated on the box (`locale-gen el_GR.UTF-8`) across 20 servers, and caches the `.mo` per process, so a copy fix is invisible until the domain name is bumped or PHP-FPM restarts. Avoiding `ext-gettext` means a pure-PHP `.po` parser, i.e. a dependency, i.e. `symfony/translation`. |
| `symfony/translation` | A catalogue system — domains, fallback chains, ICU, a loader per format — for what is one flat map per language. |
| JSON or YAML files | Honest, but strictly worse than a PHP array: parsed on every request rather than opcached, and YAML re-reads a file the panel already loads Symfony's parser for elsewhere. |

**What would make `.po` correct:** a non-developer doing the translating (Poedit,
Weblate, Crowdin all speak `.po` and nothing speaks PHP arrays), past ~200
strings, or a language with non-trivial plural rules. None of those is true at
~53 strings translated by the person writing the code. If a client ever
translates their own panel, that is the trigger to revisit — and converting a
flat map to `.po` is mechanical.

Plurals: Greek and English are both 2-form. The one or two cases here are two
keys, not a plural engine.

---

## 4. Component contract

Two files, no registration step:

```
components/testimonial/
  schema.yml          # the fields the client sees
  testimonial.twig    # how it renders
```

Attached by **you**, in the page file:

```yaml
blocks:
  - id: testimonial-1     # unique per page; form field names derive from it
    type: testimonial     # must match the folder name
    fields:
      quote: '…'
```

### Template variables

| Variable | Contents |
|---|---|
| `fields` | this block's values, schema defaults filled in |
| `block` | the block's `id` and `type` |
| `page` | `title`, `slug`, `seo`, sibling `blocks` |

Plus globals `img()`, `site()` and (from Phase 5) `nav`. A component must never
read another block's values; shared values belong on the page.

A component may be placed in a page or in a global (§4, Globals), and the
variables are identical either way: a header or footer block receives the
**current** `page` and the same `nav`, so a `site_header` can mark `aria-current`
on the active item. Nothing in the contract changes and no component needs to
know where it was placed. `layout.twig` owns the `<header>`, `<main>` and
`<footer>` landmarks — a global's component renders the contents, not a second
landmark element.

### Why there is no `object` field type

A block **is** an object — a map validated against a schema. So is a `list`
item, so is the `image` map:

| Shape | Has a template | Repeats |
|---|---|---|
| block | yes | no — the page lists them |
| `list` item | no | yes |
| `image`, `video_embed` | no | no — fixed keys |

A standalone `object` type would be a fourth spelling with no new capability. If
you want a named group of fields, make it a component.

What matters is that all of them go through **one** code path: a map validated
against a sub-schema, undeclared keys dropped. That is invariant 4 in §10, and
it is why a new field type does not add a security surface.

### Schema evolution

`Cms::withDefaults()` fills fields a content file lacks, so **adding** a field
never breaks existing pages. **Removing** one now genuinely removes it: as of
the review fixes, `withDefaults()` no longer leaks undeclared keys to templates,
and `Admin::save()` rebuilds each block's `fields` from the schema key set, so
orphans disappear on the next save instead of persisting unsanitised.

### Pages are free-form

No page types. Four pages can have four entirely different component sets.
Instead of a type system:

- **`layout:`** — optional per-page wrapper, defaults to `layout.twig`.
  `layout: bare` renders the page with no header and no footer, which is what a
  landing page or a page that opens inside an iframe wants. Developer-owned
  (§10.3), because it names a template and a value from a request would be a
  file-read primitive; a name that does not resolve falls back rather than
  fatalling, and `bin/doctor` warns about it
- **Presets** — `content/_presets/landing.yml` to copy when starting a page.
  A convention, never loaded at runtime.

**Page creation is developer-only.** The panel lists and edits pages that exist;
adding one is adding a file. Set that expectation with clients: a new Services
page is five minutes of your time, not self-service.

### Globals

A page file whose id starts with `_` is a **global**: content that renders on
every page rather than at a URL of its own.

```
content/pages/el/_header.yml
content/pages/el/_footer.yml
```

It is an ordinary page file — `title:` (the panel's label for it) and `blocks:` —
so it gets `load()`, `save()`, `transaction()`, `baseline()`, `snapshot()`,
`revisions()`, `restore()`, the advisory locks, CSRF, the role table and
`Fields::map()` without a line of code that is only about globals. That is the
whole reason for the choice: a dedicated `content/globals.yml` would be a second
copy of the page machinery, and a second save path is a second place for §10.1,
§10.2 and §10.3 to drift apart.

The prefix means exactly one thing: **not routable.** A global has no slug, is
never served at a URL, never in `/sitemap.xml`, never in `nav()`, and never
offered as a `link` target. `Content::isGlobal()` is the only copy of that rule,
and `Content::list()` — already the single feed for routing, the sitemap,
`nav()`, `pageUrl()` and the panel's page table — is the only place it is
applied. The panel lists globals separately, off a sibling `Content::globals()`.

A global carries no `seo:`, and its blocks are not in `page.blocks`, so
`Cms::pageSummary()` cannot pull the site logo into a page's `og:image` or the
footer address into its meta description.

---

## 5. Local environment

`.ddev/config.yaml`:

```yaml
name: flatcms
type: php
docroot: public
php_version: "8.4"
webserver_type: nginx-fpm
web_environment:
  - AUTH_DEV_BYPASS=1
  - MAIL_DSN=smtp://localhost:1025
```

```bash
ddev start && ddev composer install
ddev launch            # site
ddev launch /admin.php # panel
ddev mailpit
ddev exec bash tests/run.sh
```

`AUTH_DEV_BYPASS` is now an **explicit** flag, never inferred from
`REMOTE_ADDR`. The old loopback check both failed in DDEV (requests arrive from
the router container) and would have opened the panel to the internet behind
Cloudflare Tunnel.

---

## 6. Build order

The governing change from v1: **a real client site goes live in the middle of
the sequence, not at the end.** Everything after Phase 8 is driven by what that
site actually needed.

Each phase ends with the full suite green.

| Phase | Work | Estimate |
|---|---|---|
| 0 | ~~Close production contracts: state/deploy boundary, versioning, media route/budgets, form caching, production auth guard~~ **done** | 1–2 d |
| 1 | ~~`symfony/html-sanitizer`~~ **done** | 0.5–1 d |
| 2 | ~~`symfony/http-foundation`~~ **done** | 1.5–2 d |
| 3 | ~~Roles + revision restore~~ **done** | 1 d |
| 4 | ~~Media core: image object, bounded GD transformations, `<picture>`, recursive sanitise, `list`, `link` picker~~ **done** | 5–7 d |
| 5 | ~~Ship kit: atomic deploy, content backup, doctor, nav, redirects, 500, site kit~~ **done** | 3–5 d |
| 6 | ~~SEO + sitemap~~ **done** | 1–1.5 d |
| 6.5 | ~~Site header + footer as global blocks~~ **done** | 1–1.5 d |
| 7 | ~~Contact form~~ **done** | 3–4 d |
| 8 | **Pilot client site — the launch gate** | site only |
| — | Panel i18n (§3.1) **done**; repo split + `v1.0.0` still gated on the pilot | 1.5–2 d |
| 9 | ~~i18n~~ **done** | 3–4 d |
| 10 | ~~`image_list` + video~~ **done** | 3–4 d |
| | **Platform total** | **~24–34 dev days** |

Then each client site is design + components + content migration + launch:
**3–8 days each**. Content migration for 20 sites is the largest single time
sink in the whole project and is not platform work.

### Why the pilot is Phase 8, not Phase 5

An earlier draft of this plan made Phase 5 "ship kit **+ pilot client site**,
2–3 d + site", with SEO and the contact form as Phases 7 and 8 — *after* the
pilot, on the reasoning that the live site would tell us what they should be.

That does not survive contact with an actual client:

- **Every one of the 20 sites has a contact page.** The demo content already
  ships `epikoinonia.yml` and a `contact_cta` component. A form is not a
  discovery the pilot makes for us; it is a certainty on day one.
- **Phase 5 puts redirects in the ship kit to protect the client's rankings**
  when their old site is replaced — while serving no meta description, no
  `og_image` and no sitemap until Phase 7. The two halves of that argument
  contradict each other.

So SEO and the form were never "driven by what the pilot needed"; they were
always launch-blocking, and burying them behind the pilot only hid 3.5–4.5 days
inside a "2–3 d" estimate. They now run before it, and Phase 8 is the pilot
alone.

**§13.1 still holds.** The governing change from v1 — a real site goes live in
the middle of the sequence, not at the end — is intact: the pilot ships before
i18n and before `image_list`/video, on a platform that can actually serve a
client. It moved from "the middle of an estimate that was wrong" to "the middle
of one that is right".

What genuinely *is* pilot-driven, and stays after it: i18n (most clients are
Greek-only), galleries and video (Phase 10, behind a paying client), Turnstile
and CSV export (deferred within Phase 7, see there).

**No migration tooling before the pilot.** Nothing is live, so Phases 1–7 may
change demo content directly. Phase 5 moves the demo and pilot to
`content/pages/<default-locale>/*.yml` before launch, even though the runtime
still exposes one language. That makes Phase 9 additive instead of a breaking
storage migration. After `v1.0.0`, a format change must ship compatibility or a
real migration; "edit the files by hand" is no longer an acceptable upgrade
contract.

### Phase 0 — Close production contracts

This is documentation, executable spikes and acceptance fixtures, not a feature
phase. It prevents the largest decisions from being invented halfway through
Media or Ship Kit:

1. Record the production layout from §3: atomic code releases, shared
   `content/` as a separate private repository, and shared `var/`.
2. Keep package releases at `0.x` through the pilot; define the Phase 5
   single-locale directory layout as the permanent page-storage shape.
3. Define the media derivative route, finite widths, format rules, source
   adapters, memory budget and cache ceiling before writing the GD code.
4. Choose the contact-form caching policy now: **form pages are private and
   bypass edge caching in v1**. A later uncached-token endpoint is an
   optimisation only if traffic proves the contact page needs edge caching.
5. Add a production boot guard: `APP_ENV=prod` refuses to start when
   `AUTH_MODE=none`, `AUTH_DEV_BYPASS=1`, the Access audience is empty, or the
   roles file is missing.

**Done when:** the production paths are represented in a fixture config; an
atomic-release spike preserves shared state across two releases; a private form
page is demonstrably not edge-cached; the derivative route rejects a width not
in the allowlist without decoding an image; and the four unsafe auth
configurations fail closed in tests.

### Phase 1 — `symfony/html-sanitizer`

Independent of everything else and it retires hand-rolled security code, so it
goes first. Delete `Fields::rich()`'s regex. Allowlist:
`p br strong b em i u a ul ol li`; `href` on `<a>` only; schemes
`http https mailto tel`; external links get `target="_blank" rel="noopener noreferrer"`;
empty paragraphs dropped; the 100 000-character ceiling stays.

**Done when:** every richtext *case* in `03_lockdown.php` and `04_hardening.php`
still passes. Assertions may be **rewritten** — the sanitiser normalises markup,
so exact output strings will differ — but no case may be dropped, and the
attribute-order and entity changes must be reviewed rather than asserted away.

### Phase 2 — `symfony/http-foundation`

Scope, explicitly — v1 said only "`Admin::handle()` returns a `Response`", which
left an agent to invent the rest:

- `Auth::requireUser()` throws `AccessDeniedException`; it must not `echo`/`exit`
- `Admin::handle()` returns a `Response`
- `Cms::sendCacheHeaders()` returns headers rather than emitting them
- `public/index.php` and `admin.php` build a `Request`, get a `Response`, `->send()`
- Uploads read from `Request::$files`

**Done when:** `tests/_do_save.php`, `_bad_csrf.php`, `_no_auth.php`,
`_loopback_no_bypass.php` and `_bad_page.php` are deleted and their assertions
run in-process against a built `Request`. All prior assertions pass.

**Settled while doing it.** `_stale_save.php` moved in-process too; `_boot.php`
and `_img_route.php` stayed subprocesses on purpose (§11). `Cms::cacheHeaders()`
already returned header lines from Phase 0, so the emitting wrapper was simply
deleted. `symfony/mime` is deliberately **not** installed, so uploads sniff with
`finfo` — `UploadedFile::getMimeType()` fatals without it. `Request::isSecure()`
ignores `X-Forwarded-Proto` until trusted proxies are configured, so the session
cookie reads that header explicitly; and the session cache limiter is disabled
(`cache_limiter => ''`) or PHP emits a second, conflicting `Cache-Control`.

### Phase 3 — Roles + revision restore

```yaml
# config/roles.yml — no secrets, safe to commit
- { email: fotis@wearedope.com, role: admin }
- { email: pelatis@example.gr,  role: editor }
```

An authenticated email absent from `config/roles.yml` receives **403**, not an
implicit editor role. Cloudflare Access authenticates the address; this file is
the site's authorization allowlist. `Auth::user()` returns email and role for a
listed user. `editable` gains a third value:

| `editable` | admin | editor |
|---|---|---|
| `true` | edits | edits |
| `admin` | edits | sees, locked |
| `false` | sees, locked | sees, locked |

Enforced on save, exactly like `false` is today.

**Revision restore** ships here, because v1 gated it behind the admin role while
no phase actually built it — and §12 leaned on revisions as the answer to
drafts. `Content::snapshot()` exists; there is no `restore()`, no listing, no UI.

Restore must **re-run the schema walk and sanitiser**, not `copy()` the old file
back. A revision taken before Phase 1's stricter allowlist would otherwise be
written to disk unsanitised and rendered with `|raw`.

Revision listing and restore are admin-only mutating flows with CSRF protection.
An editor cannot list revision contents, restore one, or forge the action.

**Done when:** an editor cannot write an `editable: admin` field even by forging
the request; an admin can; an unlisted authenticated email is denied; restore
round-trips through sanitisation; only an admin can list and restore revisions;
and `03_lockdown.php` covers all forged cases.

**Settled while doing it.** `Components::mayEdit()` is the single copy of the
`editable` table; the edit form calls it through a Twig function, so a template
can never disagree with what the save path enforces. An `editable:` value
outside `true|admin|false` normalises to `false` — a schema typo costs a field
rather than opening one. `Content::restore()` walks the **current** file's
blocks and feeds the revision through `cleanValues()`, so a revision supplies
values and never structure, and it runs inside `transaction()` so the version it
replaces is snapshotted first. Revision filenames are matched against the exact
shape `snapshot()` writes *and* against the page id. The listing shows dates
only, never revision contents, to anybody.

### Phase 4 — Media core

One phase because it is one refactor. `Fields::sanitise()` is typed
`string|bool` and does `is_scalar($raw) ? (string) $raw : ''`; every type below
is an array. Splitting these means writing the recursion twice.

**Recursive sanitise + save.** Maps and lists validated against a sub-schema,
undeclared sub-keys dropped, exactly as at the top level. Before the item loop:
`array_values()`, reject non-lists, `array_slice(0, $max)` — so a posted
50 000-item list is truncated *before* 50 000 sanitiser passes, and
attacker-chosen array keys can't turn a list into a map in the YAML.

**`image` becomes an object:**

```yaml
image:
  src:    https://media.pelatis.gr/uploads/2026/08/team-a1b2c3.jpg
  alt:    Η ομάδα μας στο γραφείο
  width:  2400
  height: 1600
```

`width`/`height` prevent layout shift and are free (`downscale()` already decodes
at upload). `src` is constrained to `config.media_bases` — an editor must not be
able to point it at a third-party host. **`width`/`height` are server-derived**:
the save must take them from a server-side record of the upload (session-held,
one-time token), never from the request body.

**Alt text.** Today it is per-component convention and already inconsistent:
`text_image` carries a separate `image_alt` text field beside `image`, while
`hero` has no alt field at all and its template hardcodes `alt=""`. Add an image
to a new component and there is nothing to remind you. Folding `alt` into the
object fixes the *pairing* — add an image field, get an alt field — but "alt
can't be forgotten" is a mechanism, not a hope, and needs two more things:

```yaml
image:
  type: image
  label: Εικόνα
  decorative: true       # renders alt="", shows no alt input
```

1. **Alt is required whenever `src` is set.** An image field left empty saves
   fine; one with a file and no description is refused, using the existing
   `required` machinery — no second validation path (that is why
   `symfony/validator` is rejected). The condition matters: an unconditional
   rule would make Phase 6's `og_image`, which defaults to an empty map on
   every page, unsaveable.
2. **`decorative: true` is the escape hatch, and it is developer-set.** The hero
   background is the real case: it sits behind text, carries no information, and
   `alt=""` is the *correct* markup for it. Blanket-requiring alt without this is
   the classic accessibility own-goal — it produces "image", "photo",
   `logo123.jpg` typed to clear a validation error, which is worse than empty
   because a screen reader announces the junk instead of skipping the element.

It belongs in `schema.yml` because whether an image is meaningful or decorative
is a **design** decision, known to whoever placed the component and not to the
client. That is the core rule, applied: the developer declares the contract, the
client fills it. It is also why a forged `decorative` in a request must be
ignored like any other schema value (§10.3).

`text_image`'s `image_alt` field folds into `image.alt` and disappears; `hero`
gains `decorative: true` and keeps rendering `alt=""`, now by declaration rather
than by a hardcoded attribute nobody would find.

In the `<picture>` partial, `alt` goes on the `<img>` and **never on a
`<source>`** — sources take no alt, and splitting it across them is a common way
to end up with none at all. The partial reads it from `image.alt` itself, so a
component cannot forget to wire it up.

No migration script. This phase runs before the pilot, so the only content that
changes shape is demo content — edit it by hand and move on.

**Transformations move from Cloudflare to GD.** Cloudflare's 5 000 free unique
transformations a month are **per account, shared across every zone** — not per
site. Twenty sites at ~200 uniques each (50 images × 4 widths) is ~4 000 against
that cap with no headroom, and the counter resets monthly while re-uploads and
new breakpoints keep minting fresh uniques. The paid tier is cheap, so the real
objection is not the bill: one shared quota across 20 clients is a shared
failure domain you cannot attribute or rebill, and §12's per-site "alert at
5 000" was never implementable against an account-level counter.

`downscale()` is the seed, not the finished derivative service. The public route
accepts an image identity and one configured width; it never accepts a free-form
source URL, filesystem path, fit string or quality value. The v1 width allowlist
is `[320, 640, 960, 1280, 1600, 2400]`. A miss outside that set returns 404
before loading source bytes, so arbitrary URLs cannot fill disk or hold PHP
workers encoding attacker-chosen variants.

- Derivatives live in `var/cache/images/`, **gitignored**, keyed by source
  content hash + width + output format. A per-key lock prevents duplicate
  encodes; atomic rename prevents partial files.
- A source adapter reads either a file beneath `content/uploads/` or the exact
  object key beneath the configured R2 base. R2 reads have redirects disabled,
  a timeout and byte cap. A user-provided URL is never fetched.
- WebP plus a source-appropriate fallback: JPEG for opaque images, PNG for
  transparency. **AVIF upload and output are removed in v1** — current GD
  support is build-dependent, and the prototype can otherwise write JPEG bytes
  with an `.avif` name when downscaling.
- Upload normalization applies EXIF orientation, re-encodes to strip EXIF/GPS
  metadata, preserves alpha where relevant, and records the normalized
  intrinsic dimensions. It runs even when the image is already under
  `store_max_edge`; "small enough" must not retain location metadata.
- The pixel limit is derived from the PHP worker memory budget with room for
  source and destination buffers, not `pixels × 4` alone. A representative
  48 MP phone image must either normalize successfully or be rejected with a
  client-visible error without exhausting the worker.
- Cache growth is bounded by the finite variants and a configured disk ceiling;
  pruning never touches originals. Disk usage and repeated generation failures
  are monitored in Phase 5.
- `ext-gd` remains required in `composer.json`. `img()` keeps its job—returning a URL for one
  allowed width—but validates the width instead of minting arbitrary variants.

Local dev stops diverging from production as a side effect — today `transform`
is off in DDEV, so `img()` returns a bare path locally and a `/cdn-cgi/image`
URL live.

**Every image renders as `<picture>`.** Without `format=auto` doing content
negotiation, WebP-with-JPEG-fallback needs a `<source type>` on every image
anyway, so there is no case where a bare `<img>` is the simpler option. One
markup shape, no per-component branching; art direction is then just adding
`media` to a source on the few components that need it.

It is a **Twig partial, not a PHP helper** — building markup in PHP would mean
marking it `is_safe: html`, which is the mistake §9 already records removing the
`|rich` filter for. As a template, autoescaping handles `alt` for free and a
site overrides it by creating a file of the same name, which is the override
mechanism §3 already relies on.

```twig
{% include 'picture.twig' with {
     image: fields.image,
     widths: [800, 1200, 1600],
     sizes: '(min-width: 1200px) 1100px, 100vw',
     lazy: false
   } only %}
```

`width`/`height` come from the image object and must be the **original's**
intrinsic values — the browser needs the aspect ratio to reserve the box, and
that is what kills layout shift. `lazy` defaults to true but **the hero must
pass `false`**: lazy-loading the above-the-fold image delays LCP, and it is the
single most common way responsive images are got wrong.

**`link` becomes a page picker.** Stores the **page id** — which is the
filename, and is deliberately the same in every locale. Resolution at render
time is `id` → `content/pages/<current locale>/<id>.yml` → that file's `slug`.

This is why the filename, not the slug, is the identity: `contact.yml` exists in
both `el/` and `en/` while their slugs are `/epikoinonia` and `/contact`. An
earlier draft carried a `translation_key` field inside each file instead — a
second identifier for the same thing, duplicated across N files, with no
enforcement and silent failure on a typo. Deleted.

An id that no longer resolves renders as plain text and is flagged in the panel,
never as a dead `href`.

**`list`** — repeater over a fixed sub-schema for FAQ, team, features. One level
only. `item_label` names each row in the panel.

**Done when:** an `faq` component built purely from `schema.yml` + a template
round-trips; 200 items into a `max: 20` list truncates; an undeclared sub-field
drops; `boolean` stores a real YAML bool; a forged `width` in the request is
ignored; a `src` outside `media_bases` is rejected; renaming a slug leaves every
internal link intact; **saving a non-decorative image with an empty alt is
refused, a `decorative: true` image renders `alt=""` with no alt input, and a
forged `decorative` in the request is ignored**; every image on every page
renders through `picture.twig` with `width`/`height` set and the hero not lazy;
unsupported widths fail before decode; concurrent misses create one valid
derivative; local and R2 sources both work; PNG alpha survives; EXIF orientation
is correct and GPS metadata is absent; AVIF is refused; and a large phone image
cannot exhaust the PHP worker.

### Phase 5 — Ship kit

Everything here is needed before any client sees anything, and none of it was in
v1. The pilot site itself is Phase 8 — this phase, plus 6 and 7, is what it
needs underneath it.

**Deploy.** Use the §3 release/shared layout. `bin/deploy.sh` builds a new release
without touching live state: fetch an exact code revision → Composer install
`--no-dev -o` → `composer audit` → tests → `bin/doctor` against shared content →
local HTTP smoke test → atomically switch `current` → purge everything. Keep the
previous release for one-command rollback. A failed pre-switch step leaves the
current site untouched; a failed post-switch smoke test switches back.

Resolve the private Composer repository credential/deploy-key story here and
document rotation before cloning site two. Deployment purges everything because
a template change has no page id and would otherwise leave year-long stale HTML.

**Content backup.** `shared/content/` is its own private repository. Hourly:

1. acquire the site-wide content lock introduced here and acquired by every
   CMS content mutation;
2. `git add -A`, including new uploads/revisions and deletions;
3. commit only when dirty and push;
4. emit a monitored failure if commit or push fails.

The recovery point is therefore at most one successful interval, not "whenever
someone remembers to push". A weekly job clones the remote elsewhere, runs
`bin/doctor`, and requests one page and one uploaded image. A green cron exit
without that restore drill is not accepted as proof of backup.

**Uploads live in `content/uploads/` and go to git with everything else.**

They were in `public/uploads/`, gitignored, and nothing copied them anywhere —
so a lost box restored every page perfectly with every image on it broken. R2
was the answer, but it is off by default, and provisioning a bucket, a custom
domain and a scoped token per site is real work on 20 sites to protect ~50 MB.

These are 3–10 page brochure sites. Thirty to eighty images, stored at
`store_max_edge: 2400`, is roughly 25–100 MB — nothing for a git repo. Uploads
are hash-suffixed (`team-a1b2c3.jpg`), so replacing an image writes a *new*
blob rather than rewriting an old one, which is the usual reason binaries in git
turn bad. Move them under `content/`, delete `/public/uploads/` from
`.gitignore`, and the monitored content-backup job now backs up everything the
client owns. One mechanism, one restore: `git clone` and the site is whole.

Serving: an nginx `location /uploads/ { alias .../content/uploads/; }` — no
symlink, and stored `src` values do not change, so `media_bases` stays
`['/uploads/']` and `/cdn-cgi/image` keeps working exactly as now.

**The ceiling, and the exit.** This stops being right at a gallery site —
Phase 10's `image_list`, 30 photos a page — or any site past a few hundred MB.
That site flips `R2_ENABLED=1`; [`R2.php`](src/R2.php) already implements both
paths and `media_bases` already appends the R2 base when configured. It is a
config change, not a rewrite, which is the whole reason committing to git is
safe to try first. Also un-gitignore `content/.revisions/` while you are there —
same argument, smaller files.

**Navigation.** Nothing in v1 defined where a menu comes from; `Content::list()`
sorts by slug alphabetically. Add optional per-page `nav: {label, order}`, expose
`{{ nav }}` as a per-locale Twig global. ~40 lines, and it must be in place
before Phase 9 or the menu won't switch languages.

All pages move to `content/pages/<default-locale>/` in this phase, even on a
single-language site. Locale resolution still exposes only the configured
default language until Phase 9; adopting the final storage shape now prevents a
post-`v1.0.0` migration.

**Redirects.** Nearly all 20 sites replace an existing site. `content/redirects.yml`
checked before the 404 — ~20 lines in `index.php`, and it protects the client's
rankings.

**Error handling.** A Twig error after a schema rename is currently a white page
with a PHP fatal on a live site. Global exception handler → `500.twig`, logged,
`display_errors=0` in the production checklist.

**Site kit.** `bin/new-site` scaffold (site 1 will be copy-paste; by site 6 they
will have drifted) · site-level `favicon` and `og_default` · `SITE_NOINDEX` env
flag emitting `X-Robots-Tag: noindex` so a pre-launch domain can't be indexed.

**Doctor.** `bin/doctor` starts here, before the first deploy. It validates YAML
shape, unique slugs and block ids, component schemas, component templates,
layouts, redirects and redirect loops, internal page ids, configured paths and
permissions, required PHP extensions, safe production auth settings, and the
presence of writable shared `content/` and `var/`. Phase 9 extends it with
cross-locale checks; it does not introduce it.

**Operations.** Add CI on PHP 8.4 for tests, doctor and `composer audit`; log
rotation; monitored disk, content-backup, submission-retry and retention jobs;
and a simple public smoke check. Cloudflare setup includes an explicit cache
rule for public HTML, a bypass for `/admin.php` and private/form responses, and
a verified purge. Headers alone are not treated as proof that HTML is cached.

**Done when:** a failed deploy leaves the current release and shared state
untouched; a successful deploy can roll back and purges the edge; an automated
clone of the content remote restores pages *and* images; a newly uploaded,
previously untracked image is present in that clone; backup/push/cron failures
raise an alert; `bin/doctor` rejects every invalid case above; CI is green; the
nav renders from `nav:` keys rather than slug order; a path in `redirects.yml`
301s before the 404 fires; a Twig error renders `500.twig` instead of a stack
trace; `SITE_NOINDEX=1` emits the header; and Cloudflare shows public HTML cached
while admin and form pages bypass cache.

### Phase 6 — SEO + sitemap

Per-page `seo` block, a **collapsed** card at the top of the edit form:

```yaml
seo:
  title:       ''    # max 60, falls back to page title
  description: ''    # max 155
  og_image:    { src: '', alt: '', width: 0, height: 0 }
  noindex:     false
  canonical:   ''
```

`/sitemap.xml` generated from the content files, `lastmod` from mtime,
`xhtml:link` alternates, no `changefreq`/`priority`, `noindex` pages excluded,
`/robots.txt` pointing at it.

**Tag it `site`, not `page:<id>`.** A sitemap tagged per-page is never purged.
Anything rendering cross-page data — sitemap, nav, hreflang, resolved links —
carries the `site` tag, which every save now purges.

Per-language for free, since `seo` lives in the per-locale file.

**Done when:** a page with no `seo.title` falls back to its page title; `/sitemap.xml`
lists every page with a `lastmod` and excludes `noindex` ones; `/robots.txt`
points at it; saving any page purges the `site` tag so the sitemap is not stale;
`seo` renders as a collapsed card that an editor can ignore.

### Phase 6.5 — Site header and footer

The last engine change before the pilot. Numbered 6.5 so the deferred Phase 7 and
everything after it keep the numbers they already have in the tests, the commits
and this document.

Today `layout.twig` hardcodes the menu and a `©` line, so a client who wants a
logo, a phone number, opening hours or a row of social links in the footer has
nowhere to put them. Config keys are not the cheap way out: a logo is an `image`
field, which means the upload path — `finfo` sniff, GD re-encode that strips
EXIF/GPS, R2, server-derived `width`/`height` — and `picture.twig` derivatives. A
string in `config.php` carries none of that. What a given site's header holds is
also not known up front and differs per client, which is exactly the variation
`schema.yml` absorbs without engine code.

The design is §4, "Globals". Header and footer blocks **replace** the hardcoded
markup rather than sitting beside it: the layout keeps the landmarks and the site
CSS, and a `site_header` component renders the menu from the `nav` variable it
already receives.

1. **`src/Content.php`** — `isGlobal()`, and `list()`/`globals()` over one
   private scan. `list()` keeps its current return shape and now excludes
   globals; that single change is what removes them from routing, the sitemap,
   `nav()`, `pageUrl()` and the panel's page table at once.
2. **`src/Cms.php`** — extract the block loop in `renderPage()` into
   `renderBlocks(array $blocks, array $shared): array` and call it three times:
   `_header`, the page, `_footer`. A missing global file renders as an empty
   region, never a fatal — the same policy as the unknown-component skip beside
   it. Cost is two extra YAML parses per render, on a path that already parses
   every page file for `nav()`, behind the edge cache.
3. **`templates/layout.twig`** — drop the hardcoded `<nav class="site-nav">` and
   the `©` line; render `header_blocks` inside `<header>` and `footer_blocks`
   inside `<footer>`, each behind a condition so a missing global leaves no
   empty landmark. The nav and footer CSS goes with the markup, into the
   components' own `<style>` blocks, exactly as every other component ships its
   styles; the layout keeps the tokens and the page-wide rules.
4. **`components/site_header/`, `components/site_footer/`** — the starter pair,
   two files each and no registration, per §4. `site_header.twig` renders a logo
   `image` plus the `nav` loop, carrying the `aria-label` and `aria-current` that
   move out of the layout; `site_footer.twig` renders a copyright `text`, contact
   details and a `list` of links. A `list` is what makes social URLs and footer
   links client-editable **rows** without making blocks client-editable.
5. **`content/pages/el/_header.yml`, `_footer.yml`** — seeds carrying those two
   blocks, so a fresh site starts with today's output rather than a blank header.
6. **`src/Admin.php` + `templates/admin/list.twig`** — a second short table for
   `content->globals()`, linking to the existing `?action=edit&page=_header`. The
   edit, save, upload, revisions and restore flows are untouched.
7. **SEO card off for globals** — `edit.twig` hides it and `save()` skips the SEO
   walk when `isGlobal()`, so an inert `seo:` map is never written into a global
   and no editor is shown a card that does nothing.
8. **`bin/doctor`** — validate the globals' blocks with the same walk as pages
   (it iterates `list()` today, which now excludes them), and warn when a global
   file is missing, since the menu now lives in one.

**Cache purging needs no new code, and that is deliberate.** Every page response
already carries the `site` tag and every save already purges it (Phase 6), so
editing the header purges the whole site by the rule that is already there.

**Done when:** the menu and footer render from `_header.yml`/`_footer.yml` on
every page; an editor changes the footer phone number in the panel and sees it
site-wide after one purge; `GET /_header` is a 404; `_header` is absent from
`/sitemap.xml`, the menu and the `link` picker; a save to a global rides the same
transaction, baseline and revision path as a page; and the full suite is green.

### Phase 7 — Contact form

Built. The two things Phase 0 put in place for it — `private: true` on the page
that carries the form, and `var/submissions/` gitignored — are what made it a
feature rather than a retrofit, exactly as intended.

The spec below is what was built, with two deviations recorded at the end.

Client edits: heading, intro, success message, GDPR consent text. The **input
fields are developer-defined** in the schema. The **recipient is
`editable: admin`** and validated with `FILTER_VALIDATE_EMAIL` as a single
address — as client-editable text it lets an editor redirect every lead
off-site, or fan out with `a@b.gr, attacker@x`.

Pipeline, in this order:

1. **Rate limit** — first, so a POST flood can't force one outbound HTTPS call per worker
2. Honeypot + minimum time-to-submit
3. Validation (`filter_var` and `Fields`, not `symfony/validator`)
4. Atomically create `var/submissions/YYYY-MM/<random-id>.json`
5. Send via `symfony/mailer`
6. POST/redirect/GET

One file per submission is deliberate at this volume: delete-one is an unlink,
mail retry/status updates can use atomic replace, and a deletion never races an
append/rewrite of a shared monthly JSONL file. The id is random and never
derived from visitor data. Files contain no raw client IP; the rate limiter uses
only its hash and expires independently.

**Turnstile is built, defaulted off, and switched on from the panel.** Rate
limit plus honeypot plus minimum time-to-submit is ~15 lines and stops
effectively all drive-by bot traffic on a brochure site — that is the always-on
baseline, and most sites will never need more. Turnstile slots in at step 3 when
one does.

The toggle is an **`editable: admin` boolean** on the form component:

| | admin (you) | editor (the client) |
|---|---|---|
| `turnstile` toggle | flips it | sees it, locked |

That is the `editable: admin` value Phase 3 introduces, used for exactly the
reason the mail recipient uses it: a spam control the client can switch off is
not a spam control. Enforced on save like every other `editable` value
(§10.2) — not merely disabled in the form.

**Keys stay in `.env`** (`TURNSTILE_SITE_KEY`, `TURNSTILE_SECRET`). Never in a
content file: the panel writes those to disk and pushes them to a git remote
hourly, and a secret is not content. Toggle on with no keys configured →
behave as off and say so in the panel. Refusing to render the form because a key
is missing would cost the client leads over your configuration error.

**Failure mode: fail open.** If `siteverify` is unreachable, accept the
submission. The honeypot, the minimum time-to-submit and the rate limiter have
all already run, so a Cloudflare outage degrades one layer of four rather than
silently eating a client's leads for an afternoon. Same principle as mail
failure below: losing a lead is the worse outcome. Log every fail-open so a
sustained one is visible rather than inferred.

**Toggling purges.** The form's HTML changes, and pages are edge-cached for a
year. It is an ordinary content save, so the existing purge-on-save covers it —
noted because "I turned it on and nothing changed" is otherwise an hour of
confusion.

**Caching.** Form pages are `Cache-Control: private, no-store` in v1, and the
Cloudflare cache rule respects that response. This keeps a normal session-bound
CSRF token correct and makes the low-traffic contact page the only page that
misses the edge. Do not add a token endpoint until measured traffic justifies
the extra state and JavaScript. A test must inspect the actual Cloudflare cache
status, not just the origin header.

**Real client IP.** Behind Cloudflare, `REMOTE_ADDR` is Cloudflare, so a per-IP
counter rate-limits the entire internet as one bucket. Add `set_real_ip_from` /
`real_ip_header CF-Connecting-IP` to nginx **with the Cloudflare ranges**, and
key counters on `hash('sha256', $ip)` — never the raw value in a filename.

**Deliverability:** never send from the Hetzner box. SMTP relay (Brevo,
Postmark, Mailgun), SPF and DKIM on the client's domain. If mail fails the
submission is already on disk — mark it unsent, log, show the visitor success,
surface it in the admin-only panel and retry it from a monitored cron with a
bounded backoff. Losing a lead is worse than a misleading error, but silently
leaving it on disk is not delivery.

**GDPR.** Submissions live in `var/submissions/`, **gitignored** — v1 put them
under `content/` and told you to push `content/` to GitHub, which replicates
visitors' names, emails and messages to a remote with no retention limit and no
erasure path. Add a retention cron (delete >12 months) and an admin-only,
CSRF-protected delete-one button. A daily encrypted off-host backup keeps 30
days, is access-restricted, and expires deleted records naturally; the privacy
notice documents that backup window. This is separate from the content git
remote, which must never receive submissions.

CSV export is deferred — the panel list covers a brochure site's volume. When it
lands it must prefix `= + - @` with `'`, or the file executes formulas in Excel.
Written here so the next person doesn't rediscover it.

**As built, two deviations:**

- **The real client IP comes from nginx, not from PHP.** `set_real_ip_from` +
  `real_ip_header CF-Connecting-IP` (nginx.conf.example ships it, with the
  refresh cron for the ranges) makes `REMOTE_ADDR` correct before PHP sees it,
  and nginx checks who it is talking to before believing the header — which PHP
  cannot. `FORM_TRUST_CF_IP` reads the header directly for an origin that cannot
  do that, and defaults to **off**: on a directly reachable origin it lets
  anyone mint their own rate-limit bucket.
- **The rate limiter runs before the CSRF check, not after.** The spec put it
  first in the pipeline and that is what "first" has to mean: a flood of
  tokenless POSTs still costs a session start and a hash each, and the counter
  is the cheapest possible refusal.

**Done when:** a submission survives a mail failure on disk, is visible as
unsent, and a retry delivers it without losing the record; ambiguous SMTP
timeouts are flagged for admin review rather than blindly retried forever;
concurrent create/delete/retry operations do not corrupt another record; only an admin can view or delete
submissions; retention and encrypted-backup expiry are tested; the recipient
cannot be changed by an editor, only an admin, and only to one valid address;
the rate limiter keys off the trusted `CF-Connecting-IP` rather than
Cloudflare's own address; the form page and CSRF token bypass the real edge;
**an editor cannot flip the Turnstile toggle even by forging the request, an
admin can, and an unreachable `siteverify` still accepts the submission.** The
forged-toggle case goes in `03_lockdown.php` next to the forged
`editable: admin` field from Phase 3.

### Phase 8 — Pilot client site

No platform work. Design, components, content migration, launch — the 3–8 days a
client site costs, against a platform that can now actually carry one.

This is the launch gate, and the point of the whole ordering: everything before
it is what a site cannot ship without, everything after it is driven by what this
site turns out to need.

Run §12 as an actual checklist, not a memory. Then let the site argue with the
plan: what it needed and Phases 1–7 did not provide is the real input to 9 and
10, and to whether the deferred pieces (Turnstile, CSV export, galleries)
deserve to exist at all.

### Phase 9 — i18n

```php
'locales' => [
  'el' => ['label' => 'Ελληνικά', 'prefix' => '',    'default' => true],
  'en' => ['label' => 'English',  'prefix' => '/en', 'fallback' => 'default'],
],
```

**Resolution is by URL prefix.** v1 also claimed "both prefixes empty, split by
domain, must work without code changes" — impossible, since an empty prefix
leaves no locale information in the path. Host-based resolution is a **separate
mode** with its own config key, its own code path and its own tests; do not
build it now.

`fallback` applies only to non-default locales (`404` or `default`); it is
meaningless on the default locale and must be rejected there.

Content is already at `content/pages/<locale>/<id>.yml` from Phase 5. **The
filename is the translation
identity** — `el/contact.yml` and `en/contact.yml` are the same page, with
`slug: /epikoinonia` and `slug: /contact` respectively. There is no
`translation_key` field; a second identifier inside the file would only be one
more thing to mistype.

Consequences: `Content::load()` takes a locale, and both the locale and the page
id go through one shared `assertSafeSegment()` (also used for R2 keys) before
touching a path. The locale is matched against the configured map first.

**`bin/doctor` extension** — diffs the file sets across locale directories and reports
pages present in one language but missing from another, plus internal links
whose id resolves nowhere. This is the payoff of filename-as-identity: a typo is
visible in `ls content/pages/*/` and mechanically checkable before deploy, which
a free-text key inside a file never was.

Also: language switcher data in Twig, `hreflang` alternates including
`x-default`, nav resolving to the current locale, and a panel locale selector
with a per-language page list flagging missing translations.

**Done when:** both languages render at the right URLs with correct `hreflang`;
changing prefix config moves URLs with no code change; a missing translation
honours its fallback; the panel shows untranslated pages; the nav switches
language; a crafted locale segment cannot escape `content/pages/`.

### Phase 10 — `image_list` + video

Only when a client is paying for a gallery.

`image_list`: bulk select/drag, parallel upload, thumbnail grid, reorder,
inline alt.

**Open question, now decided: `required` relaxes for `image_list`.** Alt stays
required on a standalone `image` and is never demanded of a gallery row. Thirty
forced descriptions produce thirty junk strings, and a screen reader reads those
out instead of skipping them — the failure mode is worse than the gap. The input
is still rendered, with its hint, and `decorative: true` on the field still
forces `alt=""` for a gallery that genuinely carries no information. One switch,
`require: false` in the field context, which is the same one the conflict
re-render already uses.

Reorder is up/down buttons, not drag-and-drop: ten lines, keyboard-accessible
for free, and no library in a project with no front-end build. Add dragging if
someone genuinely complains. This is the largest single UI item in the project, going into a
232-line `edit.twig` with no front-end build.

`video_embed`: YouTube and Vimeo only. Store `{provider, id, hash, title, thumb}`,
never pasted HTML. Facade rendering — thumbnail plus play button, iframe on click
via `youtube-nocookie.com`, so nothing third-party loads (and no cookie banner is
forced) until the visitor acts.

**Thumbnail fetching was specified and then not built, deliberately.** The
spec was right that the oEmbed `thumbnail_url` is third-party controlled and
that fetching it is SSRF — host allowlist, no redirects, timeout, size cap,
magic bytes, and a failure that cannot fail the save. What it did not ask is
whether the fetch earns any of that. It does not: the poster is an ordinary
`image` field beside the embed, uploaded like every other image on the site.
That removes the SSRF surface rather than guarding it, costs nothing the client
does not already know how to do, and gives a better frame than the provider's
automatic one. If a client ever asks for the automatic thumbnail, the guarded
fetch above is the specification to build.

`video_loop`: MP4 in R2, hard 10 MB cap, poster, always
`muted autoplay loop playsinline`. No transcoding, no multipart upload.

---

## 7. Field type reference

| Type | Input | Stored as | Sanitised to |
|---|---|---|---|
| `text` | single line | string | plain text, whitespace collapsed, `max` |
| `textarea` | multi line | string | plain text, blank lines collapsed |
| `richtext` | contenteditable, B/I/list/link | string | allowlist; 100 000-char ceiling |
| `image` | thumbnail + upload + alt | map | `src` restricted to `media_bases`; dimensions server-set |
| `image_list` | bulk upload grid | list of image maps | each as `image`, truncated at `max` |
| `video_embed` | paste a URL | map | URL parsed, provider allowlisted, pasted HTML rejected |
| `video_loop` | upload + poster | map | MP4 only, ≤10 MB, MIME + extension |
| `link` | page picker + custom URL | map | internal page id must resolve in the current locale; `//` and `/\` rejected |
| `select` | dropdown | string | must match a declared option |
| `boolean` | checkbox | bool | real bool |
| `list` | repeater | list of maps | each item recursed against the sub-schema |

Options: `label`, `hint`, `max`, `min`, `required`, `default`, `placeholder`,
`options`, `editable`, `fields`, `item_label`, `decorative` (`image` only).

`editable` takes `true`, `false` or `admin` — all three render accordingly
**and** are enforced on save, never UI-only.

---

## 8. Page file format (target)

```yaml
# content/pages/el/contact.yml
# The filename ("contact") is the page id and the translation identity.
# en/contact.yml is the same page with a different slug.
title: Επικοινωνία
slug: /epikoinonia
layout: layout.twig
nav: { label: Επικοινωνία, order: 40 }

seo:
  title: ''
  description: ''
  og_image: { src: '', alt: '', width: 0, height: 0 }
  noindex: false
  canonical: ''

blocks:
  - id: hero
    type: hero
    fields:
      heading: '…'
      image: { src: '…', alt: '', width: 2400, height: 1600 }
```

A **global** is the same file with fewer keys — no `slug`, no `nav`, no `seo`,
no `private`, because it is never served at a URL (§4, Globals):

```yaml
# content/pages/el/_footer.yml
# The leading underscore is the whole rule: not routable.
title: Υποσέλιδο          # the panel's label for it, not a page title
blocks:
  - id: footer
    type: site_footer
    fields:
      copyright: '…'
      phone: '…'
      social:                # a `list` — the client edits rows, not blocks
        - { label: Instagram, url: 'https://…' }
```

---

## 9. Fixed in the review pass

Applied to the prototype; regression tests in `tests/04_hardening.php`.

| Was | Now |
|---|---|
| `AUTH_DEV_BYPASS` defaulted to `1`; bypass granted on loopback `REMOTE_ADDR` | Defaults to `0`; `isLocal()` deleted; explicit flag only |
| `(bool) env(...)` — the string `"false"` was `true` | `env_bool()`: only `1/true/yes/on` |
| `Fields::path()` accepted any URL → open image proxy via `/cdn-cgi/image` | `src` restricted to `config.media_bases` |
| `//evil.com` and `/\evil.com` stored as internal links | Rejected; external decided by host, not string prefix |
| `href` harvested from inside another attribute's value | Attributes parsed as name/value pairs |
| Richtext unbounded — an 8 MB paste copied into 10 revisions | 100 000-character ceiling |
| `withDefaults()` returned `$out + $values` → orphans reached templates | Schema keys only |
| Removed fields persisted in the file forever, unsanitised | `save()` rebuilds `fields` from the schema key set |
| Concurrent saves silently lost each other's edits | `flock` across read-modify-write + sha256 baseline check; the refused editor's input is handed back in a re-rendered form, plus advisory "X is in this page" markers |
| Two revisions in one second overwrote each other | Random suffix in the filename |
| Save purged `page:<id>` only; `site` never purged | Both purged |
| Admin errors echoed exception text with absolute paths | Logged; client sees a safe Greek message |
| GD decoded any 12 MB upload — a 30000² PNG is ~3.6 GB | `getimagesizefromstring()` + `max_pixels` guard |
| Session cookie not `Secure` | Set under HTTPS |
| `|rich` filter marked arbitrary strings `is_safe: html` | Removed |
| Test cleanup wiped the whole `.revisions/` directory | Scoped to fixtures |

---

## 10. Security invariants

A change that weakens one is a bug even if the tests pass.

1. **Save is schema-driven, never input-driven.** Blocks come from disk; only
   fields declared in that component's schema are read from the request. The
   result is rebuilt from the schema key set.
2. **`editable: false` and `editable: admin` are refused server-side**, not just
   disabled in the UI.
3. **Block `id`, `type`, order and count come from the file only.** So do the
   page id (the filename), `slug` and `layout`.
4. **Every value passes `Fields::sanitise()`** with its declared type before
   touching disk — recursing into `list`, `image` and `image_list`, dropping
   undeclared sub-keys. Server-derived values (image dimensions) are never read
   from the request.
5. **Richtext is allowlist-based**, never blocklist-based, and bounded.
6. **No client-supplied HTML is stored and re-rendered.** `video_embed` stores a
   parsed provider and id; the iframe is built by the template.
7. **The auth bypass is explicit configuration**, never inferred from
   `REMOTE_ADDR`, a header, or anything else in the request. `AUTH_DEV_BYPASS=0`
   in production — mandatory before adopting Cloudflare Tunnel. Production
   refuses to boot with the bypass or `AUTH_MODE=none` enabled, an empty Access
   audience, a missing roles file, or an authenticated email absent from it.
8. **Media `src` values point only at hosts we control** (`config.media_bases`).
9. **Uploads are never executed**, and never decoded before their declared
   dimensions are checked. Derivatives accept only configured widths and
   server-resolved sources; rejected variants fail before reading image bytes.
10. **CSRF on every mutating request**; the token is never served from
    edge-cached HTML.
11. **Writes are atomic and serialised** — `flock` across read-modify-write, a
    baseline hash to refuse stale saves, snapshot before overwrite. A refused
    save must re-render the editor's submitted values, sanitised, never discard
    them.
12. **Restoring a revision re-runs sanitisation.** Never a file copy.

**Globals add no invariant, and must not add a save path.** The `_` prefix (§4,
Globals) is a *routing* rule, not a security boundary: a global is a page file,
so what protects it is 1, 2 and 3 above, unchanged. A separate store for shared
content would have meant a second implementation of all three, which is a second
place for them to drift — which is why there isn't one.

`tests/03_lockdown.php` and `tests/04_hardening.php` defend these. Extend them
when adding a field type; never weaken them to make a feature pass.

---

## 11. Testing

```bash
ddev exec bash tests/run.sh     # 268 checks
```

- `01_render.php` (18) — discovery, rendering, image URLs
- `02_admin.php` (31) — form generation, CSRF, auth, uploads, absence of structural controls
- `03_lockdown.php` (73) — hostile save: XSS, `javascript:`, locked fields, injected fields, retyping, Word paste; roles, forged `editable: admin`, forged revision flows, restore sanitisation
- `04_hardening.php` (65) — every issue in §9, plus roles-file and `editable` fail-closed cases
- `05_concurrency.php` (16) — stale-save refusal, work preservation, presence markers
- `06_production.php` (65) — §10.7 boot guard, production paths, atomic release, derivative contract, form caching

Phase 6.5 adds, to the files that already exist rather than to a new one:

- `01_render` — header and footer blocks appear on a page render, inside the
  right landmark; a missing global file renders the page without them and
  without a fatal
- `02_admin` — the panel lists both globals; a save to `_header` writes; the
  edit screen still emits no add/reorder/type input, and no SEO card
- `03_lockdown` — the hostile-input battery aimed at `page=_header`: undeclared
  field dropped, `editable: false` refused, `blocks[x][type]` ignored
- `04_hardening` — `GET /_header` is a 404; `_header` is not in `/sitemap.xml`,
  not in `nav()`, not an option in the `link` picker
- `05_concurrency` — one stale-baseline conflict on `_header`, proving a global
  rides the same transaction as a page

Rules: every phase adds assertions; a new field type requires a hostile-input
case; the suite is green before a phase is done. Assertions may be **rewritten**
when an implementation legitimately changes output (Phase 1), but no **case** may
be dropped.

**Requests run in-process.** Phase 2 made `Admin::handle()` return a `Response`,
so a test builds a `Request` and asserts on the status, headers and body. Only
`_boot.php` (needs a real environment for the production guard) and
`_img_route.php` (measures peak memory, which is process-global) still fork.
`tests/lib.php` mints genuine Cloudflare Access JWTs against a throwaway keypair,
so role tests exercise the real auth path rather than a config knob — a fake
identity switch would be exactly the backdoor `roles.yml` exists to prevent.

CI runs the suite on PHP 8.4, `composer validate`, `composer audit`, and
`bin/doctor` against valid and intentionally invalid fixtures. Phase 4 adds real
JPEG/PNG/WebP fixtures for orientation, alpha, metadata, memory rejection and
concurrent derivative generation. Phase 5 adds an atomic deploy/rollback smoke
test plus a clean content-remote restore. Phase 7 exercises form submission,
mail failure/retry, retention and permissions through HTTP; string-level unit
tests alone are not enough for these operational flows.

---

## 12. Production checklist (per site)

- [ ] nginx docroot at `current/public/`; atomic `current` release symlink; `shared/content/` and `shared/var/` outside releases
- [ ] `set_real_ip_from` + `real_ip_header CF-Connecting-IP`, ranges refreshed by cron
- [ ] `shared/content/` and `shared/var/` writable by the PHP-FPM user; release code read-only
- [ ] nginx `location /uploads/` aliases `shared/content/uploads/`; uploads cannot execute
- [ ] `shared/.env` present, excluded from releases/git, `APP_ENV=prod`, **`AUTH_MODE=cf_access`**, **`AUTH_DEV_BYPASS=0`**, non-empty Access AUD, `display_errors=0`; unsafe values fail the boot check
- [ ] `ADMIN_LOCALE` set for the client's language — `el` on a Greek site; the default is `en`
- [ ] Access application on `/admin.php`, client email allowed, **your address too**, AUD set
- [ ] `config/roles.yml` explicitly lists every client editor and your admin; an unlisted Access identity receives 403
- [ ] R2 **only if this site needs it** (a gallery, or uploads past a few hundred MB): bucket + custom domain + scoped token; `media_bases` matches. Otherwise skip — uploads are in git.
- [ ] `shared/var/cache/images/` writable by `www-data`, bounded and monitored (derivatives regenerate; no backup needed)
- [ ] Cloudflare public-HTML cache rule, admin/form bypass, cache purge token; HIT/BYPASS and purge verified against the real zone
- [ ] `set_real_ip_from` + `real_ip_header CF-Connecting-IP` with the Cloudflare ranges, refreshed by cron — without it the form's rate limiter treats the whole internet as one bucket
- [ ] SMTP relay; SPF + DKIM on the client's domain; `MAIL_DSN`, `FORM_TO` and `FORM_FROM` set — an unconfigured box stores every submission unsent
- [ ] `bin/mail-retry` on a 15-minute cron and `bin/prune-submissions` daily, both monitored: a retry nobody runs is not delivery, and a retention policy nobody runs is a sentence in a privacy notice
- [ ] Turnstile keys in `.env` **only if** the site turns the toggle on — off is the default and the normal case
- [ ] `shared/content/` is a separate private git remote; monitored hourly `git add -A`/commit/push job; weekly clean-clone restore proves pages and images work
- [ ] `shared/var/submissions/` is excluded from content git; admin-only access; retry and 12-month retention jobs monitored; encrypted off-host backup expires after 30 days
- [ ] CI, `bin/doctor`, deploy smoke test, rollback, log rotation, disk alert and cron-failure alert verified
- [ ] `SITE_NOINDEX=1` until launch day
- [ ] Redirect map from the old site
- [ ] Hero image passes `lazy: false` — every other image may lazy-load

---

## 13. What changed from v1

1. **A pilot client site moved into the middle of the sequence** (Phase 8).
   v1 produced no live site until every phase was done — months of unbilled work
   before any validation, and client #1 would have forced rework of phases
   already called green.
2. **Estimates corrected** from "about a week" to ~18–25 platform days.
3. **Repo split moved after Phase 4 in the initial v2.** Doing it first meant
   seven phases of vendor-edit friction and a semver promise the phases
   themselves would break. The v2.1 amendment moves it once more, after the
   pilot (§13.24).
4. **v1's Phase 3 was four features in one.** Split: media core (Phase 4) now
   carries the recursion refactor that `list` also needs; `image_list` and video
   deferred to Phase 10, behind a paying client.
5. **A ship kit was missing entirely** — deploy, navigation, redirects, 500 page,
   new-site scaffold, staging noindex. All of it blocks launch.
6. **Deploy vs content conflict named:** client saves write into the directory
   git tracks, and a code deploy never purged the edge.
7. **i18n contradiction resolved:** prefix-based resolution only; host-based is a
   separate mode.
8. **Page identity is the filename**, shared across locales, and internal links
   store that id. An earlier draft added a `translation_key` field inside every
   file — a duplicate identifier with no enforcement, which broke silently on a
   typo.
9. **Revision restore given a home** (Phase 3) instead of being assumed to exist.
10. **Submissions moved out of `content/`** — v1 would have pushed visitor PII to
    GitHub.
11. **Security gaps specified** rather than assumed away: SSRF in thumbnail
    fetching, Cloudflare `REMOTE_ADDR` in the rate limiter, CSRF tokens in
    edge-cached HTML, client-editable mail recipient, CSV formula injection,
    unbounded list items.
12. **Sixteen real defects fixed in the prototype** (§9), with 35 regression
    tests.

### Changed within v2

13. **The pilot was pulled out of Phase 5 and made Phase 8**, with SEO and the
    contact form moved ahead of it. v2 shipped with a pilot that silently
    depended on two later phases — 3.5–4.5 days hidden inside a "2–3 d"
    estimate. Reasoning in §6, "Why the pilot is Phase 8, not Phase 5".
14. **Panel i18n specified** (§3.1): a `t()` helper over per-locale PHP arrays,
    **English as the source language and default**, language chosen per site by
    `ADMIN_LOCALE`, never a per-user selector. `.po`/gettext and
    `symfony/translation` rejected with reasons, so neither gets re-proposed.
15. **Turnstile made an `editable: admin` toggle, default off**, with keys in
    `.env` and an explicit fail-open decision — rather than always-on with a key
    to provision on 20 sites that mostly don't need it. CSV export deferred.
    Phases 5, 6 and 7 given the "Done when" criteria the other phases had.
16. **Migration scripts dropped before the pilot.** Nothing is live: Phases 1–7
    reshape demo content directly, including adoption of the final locale
    directory layout in Phase 5. After `v1.0.0`, compatibility or a real
    migration is required; the no-tooling exception ends at the pilot.
17. **Uploads moved to `content/uploads/` and into git.** They were gitignored
    in `public/uploads/` with nothing copying them anywhere, so a lost box
    restored every page perfectly with every image broken. R2 was the nominal
    answer but defaults to off and costs a bucket, a domain and a token per
    site. At ~25–100 MB a brochure site, git is one mechanism instead of three.
    R2 stays as the documented exit for galleries and large sites — a config
    flag, since `R2.php` already implements both paths.
18. **Orphaned uploads named, and cleanup deliberately not built.** Clearing an
    image field leaves the file on disk forever, and an unsaved upload is
    already written. ~5 MB a year, permanent once in git. No pruning tool: an
    image the live page dropped may still be referenced by a revision, so
    "unreferenced" is not "orphaned", and a naive prune breaks revision restore.
19. **Image transformations moved from Cloudflare to GD** (Phase 4), after
    confirming the 5 000 free uniques a month are **per account**, not per zone
    — ~4 000 across 20 sites once images are responsive, and a quota no client
    can be billed for. `downscale()` already had the GD code. `ext-gd` was
    undeclared in `composer.json` the whole time.
20. **`<picture>` everywhere**, from one overridable Twig partial rather than a
    PHP helper — no `is_safe: html`, `alt` escaped by autoescaping, per-site
    override for free. Breakpoints stay in templates; they were never a
    `schema.yml` concern, which is the client's field contract.
21. **Alt text given a mechanism instead of an intention.** v2 said the image
    object meant "alt can't be forgotten" but never made it enforceable. Alt is
    now **required by default and refused on save when empty**, with
    `decorative: true` as a developer-set escape hatch — because blanket-
    requiring it produces "image" and `logo123.jpg` typed to clear a validation
    error, which a screen reader reads aloud instead of skipping.
22. **Three repos became two packages.** `dopamine/flatcms-components` was a
    third tag cycle and a third Renovate PR per site for a mechanism — Twig
    loader precedence — that works identically with the components inside core;
    folded in. `<client>-gr` was never a package, just "each site is a repo".
    In its place, `dopamine/flatcms-skeleton`: a `create-project` target, so the
    stated goal (download it, run it) is literally true rather than "require the
    library and then assemble a docroot by hand".

### Production-readiness amendment (v2.1)

23. **Phase 0 added** to close the state/deploy boundary, versioning, derivative
    route and budgets, form-cache behavior and production auth guard before
    implementation makes those decisions accidentally.
24. **The package split moved after the pilot.** Development stays `0.x`; the
    first stable tag follows a real edit/deploy/backup/restore cycle. Phase 5
    adopts the permanent locale-directory shape so Phase 9 is additive.
25. **Production state is outside atomic code releases.** `shared/content/` is
    its own private repository and `shared/var/` is not deployed. The backup job
    uses `git add -A`, reports push failures, and is proven by restore drills, so
    new uploads are actually backed up.
26. **Authorization now fails closed.** An Access-authenticated but unlisted
    email is denied, revision operations are admin-only, and production refuses
    to boot under bypass/none auth or incomplete Access configuration.
27. **GD became a bounded media service, not just `imagescale()`.** Widths are
    allowlisted; local and R2 sources are server-resolved; cache growth and
    memory are bounded; EXIF orientation/GPS, PNG alpha and the current AVIF
    mismatch have explicit behavior and tests.
28. **Contact-form caching is decided.** Form pages are private in v1; one file
    per submission makes delete/retry concurrency tractable; unsent mail is
    visible and retried; PII backup and expiry are explicit.
29. **Doctor and operations moved before launch.** Schema/content validation,
    CI, atomic deploy/rollback, cache verification, smoke tests, disk/cron
    alerts and monitored backups are Phase 5 acceptance criteria rather than a
    production checklist left for each site to improvise.
30. **Estimates include production work.** The platform range is now 24–34 days,
    reflecting the media, form, backup and deployment behavior the launch gate
    actually requires.

### Panel-language amendment (v2.4)

35. **Panel i18n landed before the repo split, not with it.** §2 pairs them
    because the split is what makes a Greek default a fork risk; the helper
    itself has no dependency on the split, and shipping it first means the
    pilot is built against the panel every later site gets. English is the
    source and the default, `el` is a translation, `ADMIN_LOCALE` picks per
    site, and an unrecognised value renders English rather than failing.
36. **Two catalogues, not one.** The panel speaks `ADMIN_LOCALE` to the person
    editing; the handful of engine strings a *visitor* sees — the contact
    form's refusals — speak the language of the page they appear on. One global
    would make one of those two wrong on every bilingual site.
37. **A missing key renders as itself.** Which is also why a component's own
    `label:` needs no branching: a built-in key like `field.alt` translates, and
    a developer's literal "Κεντρική ενότητα" passes through untouched.
    `bin/doctor` reports a translation that has fallen behind.

### i18n and media amendment (v2.3)

32. **Locale resolution is a swap, not a parameter.** `Cms::useLocale()` is
    called once per request by the entry point and swaps `$cms->content` for
    that language's store; nav, sitemap, links, the panel and the save path go
    on asking the same property and are in the right language for free. The
    alternative — threading a locale argument through twelve methods with no
    opinion about it — was rejected. Revisions moved to
    `.revisions/<locale>/`, because `contact` is a different document in each
    language and one history for both lets a restore put English copy into a
    Greek page.
33. **`assertSafeSegment()` refuses rather than strips.** The old page-id guard
    stripped, so `../home` quietly resolved to `home` and an obvious attack was
    answered with a page. One guard now covers the page id and the locale, and
    the locale is matched against the configured map before it reaches it.
34. **Video thumbnails are uploaded, not fetched.** §10 specified a
    guarded oEmbed fetch; the poster is an ordinary `image` field instead,
    which removes the SSRF surface rather than guarding it. The `image_list`
    alt question is decided the same way — see Phase 10.

### Shared-content amendment (v2.2)

31. **Content above the page now exists, as non-routable page files.** v2 had no
    place for a logo, a footer address or a row of social links: `layout.twig`
    hardcoded the menu and the copyright line, and §4 said only "shared values
    belong on the page". Phase 6.5 makes header and footer global blocks in
    `_header.yml` / `_footer.yml`, where the leading underscore means "not
    routable" and nothing else. A dedicated `content/globals.yml` with its own
    transaction, baseline, revision and admin-save code was rejected: it would
    have been a second copy of the page machinery, and therefore a second place
    for the save invariants to drift. Config keys were rejected too — a logo is
    an `image` field, which is the upload pipeline, not a string.
