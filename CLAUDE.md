# CLAUDE.md — Dopamine FlatCMS

Project instructions for Claude Code. Read `dopamine-flatcms-plan-v2.md` before
starting any phase — it holds the architecture, the phase order and the
acceptance criteria.

## What this project is

A flat-file CMS for small client sites. No database, no build step, no
framework. PHP 8.4 + Twig, YAML content files, Cloudflare for auth, media and
caching.

**The core rule:** structure is developer-owned and lives in files; content is
client-owned and edited in the panel. The client cannot add, remove, reorder or
retype a component. This is enforced on save, not merely hidden in the UI.

## Commands

```bash
ddev start
ddev composer install
ddev exec bash tests/run.sh      # full suite, including cold Composer install
php bin/doctor                   # health check; a deploy runs it before switching
ddev launch                      # site
ddev launch /admin.php           # panel
ddev mailpit                     # outbound mail during form work
ddev exec php -l src/Admin.php   # lint
```

## Non-negotiables

Violating any of these is a bug even if the tests pass. They are restated in
full in §10 of the build plan.

1. **Save is schema-driven, never input-driven.** Read blocks from disk, then
   for each block pull only fields declared in that component's `schema.yml`
   out of the request. Never iterate over `$request->request->all()` and write
   what you find.
2. **`editable` is refused server-side**, not just disabled in the UI. `false`
   is closed to everyone, `admin` is closed to editors. `Components::mayEdit()`
   is the only copy of that table, and the edit form asks it too.
3. **Block `id`, `type`, order and count come from the file only.** Same for
   the page id (the filename) and `slug`. No request may change them.
4. **Richtext is allowlist-based.** Never blocklist-based.
5. **`AUTH_DEV_BYPASS` must default to `0`, and must never be inferred from the
   request.** It is only ever `1` in `.ddev/config.yaml`. Do not reintroduce a
   `REMOTE_ADDR`/loopback check — it fails in DDEV and opens the panel to the
   internet behind Cloudflare Tunnel.
6. **Access authenticates; `config/roles.yml` authorizes.** An authenticated
   address the file does not list gets a 403, never an implicit editor role.
   Anything malformed in that file — unknown role, missing file, wrong shape —
   denies. Revision listing and restore are admin-only, and CSRF-protected.
7. **An image `src` may only point at `config.media_bases`.** Anything else
   turns the client's `/cdn-cgi/image` endpoint — or `/img.php` — into an open
   proxy. `Media::spec()` applies the identical guard to the anonymous GET, and
   the derivative width must be in the finite allowlist: both are checked before
   a byte of the source is read.
8. **Saves run inside `Content::transaction()`** — lock + baseline check. Never
   load-mutate-write directly. A `StaleContentException` must re-render the
   editor's values, never discard them.
9. **Restoring a revision re-runs sanitisation.** Never `copy()` a file back.
10. **Never weaken `tests/03_lockdown.php`, `tests/04_hardening.php` or
   `tests/06_production.php` to make a
    feature pass.** If a change breaks them, the change is wrong. Assertions may
    be rewritten when an implementation legitimately changes output; a *case* may
    never be dropped.
11. **An image's `width`/`height` are server-derived**, from the session record
    the upload wrote or from what is already on disk beside the same `src`.
    Never from the request body.
12. **Every content mutation holds the site-wide content lock** (shared;
    `Admin::MUTATIONS`). The hourly backup takes it exclusively, so a commit can
    never capture a half-applied save. Adding a writing action means adding it
    to that list.
13. **A form's visitor inputs are `form:` in the component schema, never
    `fields:`.** A client who could edit that list could add an input, and an
    input is where a value comes from. The recipient and the Turnstile toggle
    are `editable: admin` and refused server-side, for the same reason.
14. **`Fields::map()` is the only schema walk.** The top level, an image's
    src/alt pair and a list's rows all go through it, so "undeclared keys are
    dropped", "`editable` is enforced" and "`required` is checked" have one
    implementation rather than one per nesting depth. A second recursive walk
    is a bug, not an optimisation.

## Conventions

- PHP 8.4, `declare(strict_types=1)` in every file, PSR-12.
- Namespace `Dopamine\FlatCms\`, PSR-4 from `src/`.
- Constructor property promotion; `readonly` where it holds.
- No framework, no DI container, no service locator. `Cms` wires everything in
  its constructor and that is the whole container.
- Typed properties and return types everywhere. No `mixed` unless the value
  genuinely is.
- Prefer a small hand-rolled implementation over a large dependency — **except**
  for cryptography, HTML sanitising and SMTP, where the failure mode is silent
  and severe. Those use libraries. (`R2::sign()` is hand-rolled because a bug
  there fails closed; JWT verification uses a library because a bug there fails
  open.)
- Comments explain *why*, not *what*. Assume the reader can read PHP.
- User-facing strings go through `t()` (`Lang`), never inline. `lang/en.php` is
  the **source language and the default**; `lang/el.php` is a translation, and
  `ADMIN_LOCALE` picks per site. A missing key renders as itself, so a
  component's own `label:` in Greek passes through untouched while a built-in
  key resolves. `bin/doctor` reports a translation that has fallen behind.
  Two catalogues: `$cms->lang` is the panel's, `$cms->siteLang()` is the
  language of the page a visitor is reading — form refusals use the second.

## Dependencies

Current: `twig/twig`, `symfony/yaml`, `symfony/dotenv`, `firebase/php-jwt` (v7 — v6 carries
CVE-2025-45769), `symfony/html-sanitizer` (which pulls `league/uri` and two PSR
HTTP interface packages transitively), `symfony/http-foundation` (no transitive
dependencies), `symfony/mailer` (Phase 7; it pulls `symfony/mime` transitively —
uploads still sniff with `finfo` rather than `UploadedFile::getMimeType()`, which
is now a deliberate choice rather than a forced one). Extensions: `ext-curl`, `ext-dom`,
`ext-exif`, `ext-fileinfo`, `ext-gd`, `ext-json`, `ext-openssl` (tests mint Access tokens).
`ext-exif` is there because uploads are re-encoded to strip GPS, which discards
the orientation tag too — so the rotation has to be baked into the pixels before
that happens, or every portrait on the site is sideways. `bin/doctor` checks the
same list against the *running* interpreter, since Composer resolves under the
CLI php and the site runs under php-fpm.

Test suite: 1,029 checks across nine files. Run all of them, not just the new ones.

**Do not add anything else without asking.** Explicitly rejected, with reasons:

- `symfony/form` — fights the schema-driven approach, which is the product
- `symfony/validator` — would create a second validation system beside `Fields`
- `symfony/routing` — routing here is slug → file
- `aws/aws-sdk-php` — 15 MB for one `PUT`; `R2::sign()` is 60 lines
- `symfony/translation` — a `t()` helper over an array covers ~40 UI strings

## Layout

```
src/          Cms Admin Auth Components Content Fields Form Lang Locks Media
              R2 Submissions
              Cloudflare AccessDeniedException StaleContentException
              bootstrap.php — process-level error handlers, not a class
config/       roles.yml — email -> admin|editor, committed, no secrets
components/   <name>/schema.yml + <name>.twig — one folder per component
content/      pages/<locale>/*.yml, uploads/, .revisions/, redirects.yml
              A page id starting with `_` — `_header`, `_footer` — is a
              **global**: an ordinary page file that renders on every page
              instead of at a URL. `Content::isGlobal()` is the only copy of
              that rule and `Content::list()` the only place it is applied.
              One directory per configured locale; resolution is by URL prefix
              and `Cms::useLocale()` is the whole resolver, called once per
              request. Revisions live under `.revisions/<locale>/`.
              A page carries `seo:` beside `title`/`slug`/`nav`; `/sitemap.xml`
              and `/robots.txt` are generated from those files, never stored.
              All of it tracked in git — that is the backup. Submissions live
              in var/submissions/, gitignored: visitor PII must never reach the
              hourly content push. bin/mail-retry redelivers, bin/prune-
              submissions enforces retention, and neither is optional. The locale directory is the permanent shape; Phase 9
              resolves a second one beside it rather than migrating.
bin/          doctor deploy.sh rollback.sh release.sh backup restore-drill
              new-site mail-retry prune-submissions
lang/         en.php (source) + el.php — panel and engine strings
templates/    layout.twig, bare.twig (no header/footer; `layout: bare`),
              picture.twig, video_facade.twig, 404.twig, 500.twig, admin/*.twig
              Every image on the site renders through picture.twig. A component
              that writes its own <img> is caught by 01_render.php.
public/       docroot: index.php, admin.php, img.php, router.php
tests/        01_render 02_admin 03_lockdown 04_hardening 05_concurrency
              06_production 07_shipkit 08_form 09_package, lib.php, fixtures/,
              run.sh. 09 proves a mirrored Composer install and clean archive.
              Requests run in-process: build a Request, assert on the Response.
              Only _boot.php (needs a real environment) and _img_route.php
              (measures peak memory) still fork.
skeleton/     the dopamine/flatcms-skeleton create-project package. It owns
              public/, config, starter content and site-facing bin wrappers;
              engine code is installed under vendor/ and never copied here.
```

Production runs an atomic-release layout: `CONTENT_PATH` and `VAR_PATH` point
the engine at `shared/` directories outside the release, so flipping `current`
never touches a client save. `tests/fixtures/production.env` is the concrete
shape; `APP_ENV=prod` refuses to boot on an unsafe auth config.

`bin/deploy.sh` runs every check *before* the switch and reverses it if the
post-switch smoke test fails — that ordering is the safety property, and
`07_shipkit.php` asserts it. `bin/backup` and `bin/restore-drill` are the pair
that makes "we have backups" a checked claim rather than a belief.

Adding a component is two files and no registration step. Keep it that way.

## Working style

- Follow the phase order in the build plan. Do not start a phase before the
  previous one is green.
- Run the full suite before saying a phase is done — not just the new tests.
- Every new field type needs a hostile-input case in `03_lockdown.php`.
- When a phase's spec seems wrong, stop and say so rather than quietly choosing
  differently. The decisions in §2 of the plan were made deliberately, and §13 records what an earlier version got wrong.
- Prefer editing existing files over adding new ones. This codebase is small on
  purpose; ~1,300 lines is a feature.
- Do not add a feature from the "out of scope" list even if it seems easy. That
  list is what keeps this from becoming a general-purpose CMS to maintain.
