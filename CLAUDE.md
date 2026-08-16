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
ddev exec bash tests/run.sh      # full suite — must be green before any phase is done
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
2. **`editable: false` is refused server-side**, not just disabled in the UI.
3. **Block `id`, `type`, order and count come from the file only.** Same for
   the page id (the filename) and `slug`. No request may change them.
4. **Richtext is allowlist-based.** Never blocklist-based.
5. **`AUTH_DEV_BYPASS` must default to `0`, and must never be inferred from the
   request.** It is only ever `1` in `.ddev/config.yaml`. Do not reintroduce a
   `REMOTE_ADDR`/loopback check — it fails in DDEV and opens the panel to the
   internet behind Cloudflare Tunnel.
6. **An image `src` may only point at `config.media_bases`.** Anything else
   turns the client's `/cdn-cgi/image` endpoint into an open proxy.
7. **Saves run inside `Content::transaction()`** — lock + baseline check. Never
   load-mutate-write directly. A `StaleContentException` must re-render the
   editor's values, never discard them.
8. **Restoring a revision re-runs sanitisation.** Never `copy()` a file back.
9. **Never weaken `tests/03_lockdown.php` or `tests/04_hardening.php` to make a
   feature pass.** If a change breaks them, the change is wrong. Assertions may
   be rewritten when an implementation legitimately changes output; a *case* may
   never be dropped.

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
- User-facing strings in the panel are Greek **today**, and hardcoded inline.
  Panel i18n lands with the repo split (plan §3.1): a `t()` helper over
  `lang/<locale>.php` arrays, **English as the source language and the default**,
  language picked per site by `ADMIN_LOCALE`. Until then keep writing Greek
  inline — do not start introducing keys piecemeal.

## Dependencies

Current: `twig/twig`, `symfony/yaml`, `firebase/php-jwt` (v7 — v6 carries
CVE-2025-45769), `symfony/html-sanitizer` (which pulls `league/uri` and two PSR
HTTP interface packages transitively). Extensions: `ext-curl`, `ext-dom`,
`ext-gd`, `ext-json`.

Test suite: 187 checks across six files. Run all of them, not just the new ones.

Planned, per the build plan: `symfony/mailer`, `symfony/dotenv`,
`symfony/http-foundation`.

**Do not add anything else without asking.** Explicitly rejected, with reasons:

- `symfony/form` — fights the schema-driven approach, which is the product
- `symfony/validator` — would create a second validation system beside `Fields`
- `symfony/routing` — routing here is slug → file
- `aws/aws-sdk-php` — 15 MB for one `PUT`; `R2::sign()` is 60 lines
- `symfony/translation` — a `t()` helper over an array covers ~40 UI strings

## Layout

```
src/          Cms Admin Auth Components Content Fields Locks Media R2
              Cloudflare StaleContentException
components/   <name>/schema.yml + <name>.twig — one folder per component
content/      pages/*.yml, .revisions/   (locale subdirs land in Phase 9;
              uploads move here in Phase 5; submissions live in var/)
templates/    layout.twig, 404.twig, admin/*.twig
public/       docroot: index.php, admin.php, img.php, router.php
tests/        01_render 02_admin 03_lockdown 04_hardening 05_concurrency
              06_production, fixtures/production.env, run.sh
```

Production runs an atomic-release layout: `CONTENT_PATH` and `VAR_PATH` point
the engine at `shared/` directories outside the release, so flipping `current`
never touches a client save. `tests/fixtures/production.env` is the concrete
shape; `APP_ENV=prod` refuses to boot on an unsafe auth config.

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
