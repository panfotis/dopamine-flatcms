# Dopamine FlatCMS

A flat-file CMS for small client sites (3–10 pages). Components are defined in
files. The client edits text and images — nothing else.

No database. No build step. No admin UI for structure.

This repository is the Composer engine package (`dopamine/flatcms`). The
[`skeleton/`](skeleton/) directory is the separate
`dopamine/flatcms-skeleton` project template: publish it as its own VCS package,
then create each site with `composer create-project`. Site code overrides
package components and templates without editing `vendor/`.

```
components/hero/schema.yml   →  the fields the client sees
components/hero/hero.twig    →  how it renders
content/pages/el/home.yml    →  which components this page has, and their values
```

---

## The design rule

**Structure lives in files. Content lives in the panel.**

You control which components a page has, in what order, with what fields, by
editing `content/pages/<locale>/*.yml` and `components/*/schema.yml`. The client opens
`/admin.php` and sees a form. There is no "add section" button, no drag and
drop, no component picker — those UIs do not exist.

This is enforced on save, not just hidden in the UI. `Admin::save()` reads the
blocks from disk and, for each one, walks the component's schema pulling only
the fields that role may edit out of the request. A forged request that posts
`blocks[hero][type]`, an undeclared field, a field you marked
`editable: false`, or — as an editor — one marked `editable: admin`, changes
nothing. See `tests/03_lockdown.php`: it fires exactly those requests, with a
real Cloudflare Access token for each role, and asserts the file on disk is
unaffected.

---

## Requirements

- PHP 8.4 with `curl`, `dom`, `exif`, `gd`, `json`, `openssl`
- A web server pointing at `public/`
- Optional: a Cloudflare zone, an R2 bucket

## Engine development (DDEV)

Copy `ddev/config.yaml` to `.ddev/config.yaml`, then:

```bash
ddev start                      # boots and runs composer install
ddev launch                     # the site
ddev launch /admin.php          # the panel
ddev exec bash tests/run.sh     # the whole suite
```

Everything else you need day to day:

```bash
ddev composer require vendor/pkg    # composer inside the container
ddev exec php -l src/Admin.php      # lint
ddev xdebug on                      # step debugging when something is odd
ddev mailpit                        # outbound mail (from the contact-form phase)
ddev ssh                            # shell in the web container
ddev restart                        # after editing .ddev/config.yaml
ddev logs -f                        # PHP errors and nginx
```

Three things specific to this project:

- **`type: php`, not `drupal`.** Nothing here knows about Drupal, and DDEV's
  Drupal hooks would just get in the way.
- **`omit_containers: [db]`.** There is no database anywhere in this codebase.
  Dropping the container saves roughly 300 MB of RAM per project, which matters
  when several are running.
- **`AUTH_DEV_BYPASS=1` lives in `web_environment`** and nowhere else. It is an
  explicit flag — the code never infers "local" from `REMOTE_ADDR`, because in
  DDEV every request reaches PHP from the router container and would look
  remote anyway. It must be `0` in production.

`SITE_BASE_URL` must match the DDEV URL: `img()` builds absolute URLs from it
once Cloudflare transformations are switched on.

R2, image transformations and cache purge are all off locally, so uploads land
in `content/uploads/` and saves are instant. The whole thing runs with zero
Cloudflare setup.

For private VCS distribution, run `ddev auth ssh` so the container can reach
the package repositories.

## Create a site

```bash
composer create-project dopamine/flatcms-skeleton my-site
cd my-site
cp .env.example .env
ddev start
ddev launch /admin.php
```

The engine is installed at `vendor/dopamine/flatcms`; the site owns only its
config, content, public entrypoints, layout, and optional overrides. See
[`skeleton/README.md`](skeleton/README.md).

### Without DDEV

```bash
composer install
AUTH_DEV_BYPASS=1 php -S localhost:8080 -t public public/router.php
bash tests/run.sh
```

---

## Adding a component

Two files. That is the entire workflow.

```bash
mkdir components/testimonial
```

`components/testimonial/schema.yml`:

```yaml
label: Μαρτυρία πελάτη
description: Ένα απόσπασμα με όνομα και εταιρεία.

fields:
  quote:
    type: textarea
    label: Κείμενο
    max: 240
    required: true
  author:
    type: text
    label: Όνομα
    max: 60
  company:
    type: text
    label: Εταιρεία
    max: 60
```

`components/testimonial/testimonial.twig`:

```twig
<section class="testimonial">
  <div class="wrap">
    <blockquote>{{ fields.quote|nl2br }}</blockquote>
    <cite>{{ fields.author }}{% if fields.company %}, {{ fields.company }}{% endif %}</cite>
  </div>
</section>
```

Then put it on a page — `content/pages/el/home.yml`:

```yaml
blocks:
  - id: testimonial-1
    type: testimonial
    fields:
      quote: '…'
      author: '…'
      company: '…'
```

Reload `/admin.php` and the form is there. No registration step, no cache to
clear, no database migration.

Adding a field to a schema later is safe: pages that do not have a value for it
fall back to `default` (or an empty string) rather than erroring.

## Field types

| Type | Renders as | Sanitised to |
|---|---|---|
| `text` | single-line input | plain text, whitespace collapsed, truncated at `max` |
| `textarea` | multi-line input | plain text, blank lines collapsed |
| `richtext` | small contenteditable with B / I / list / link | only `p br strong b em i u a ul ol li`, all attributes stripped except `href` |
| `image` | thumbnail + upload button + alt input | a map: `src` (must be under `media_bases`), `alt`, and server-derived `width`/`height` |
| `link` | page picker | a page id — the filename. The slug is resolved at render time |
| `url` | single-line input | an absolute `http(s)` URL, a site-relative path, a fragment, `mailto:` or `tel:`. Everything else becomes empty — the same rule richtext hrefs use |
| `select` | dropdown | must match a declared option |
| `boolean` | checkbox | a real YAML `true`/`false` |
| `list` | repeater over a fixed sub-schema | `array_values()`, cut to `max`, then each row walked against `fields` |

An `image` field carries its own alt: declare the field and the alt input comes
with it. Alt is **required whenever `src` is set**, unless the field declares
`decorative: true` — which renders `alt=""` and shows no input, and is the right
answer for a background image that carries no information. That flag is the
developer's, not the client's: a `decorative` arriving in a request is dropped
like any other undeclared key.

A `list` declares `fields` (its sub-schema), `max` (the ceiling, applied before
anything is sanitised) and `item_label` (which sub-field titles each row in the
panel). One level only.

Per-field options: `label`, `hint`, `max`, `required`, `default`,
`placeholder`, `options`, `decorative`, `fields`, `item_label`, and `editable`,
which takes three values:

| `editable` | admin | editor |
|---|---|---|
| `true` (default) | edits | edits |
| `admin` | edits | sees, locked |
| `false` | sees, locked | sees, locked |

Anything else is treated as `false` — a typo in a schema must cost a field, not
hand one over. Who is an admin is `config/roles.yml`; Cloudflare Access decides
who gets as far as the panel, that file decides what they may do once there, and
an authenticated address it does not list is refused outright.

Paste handling in `richtext` is forced to plain text, so a paste from Word
cannot carry styling into the page.

---

## Deploying on a server

Production is an **atomic-release layout**. `current` is a symlink to a release
directory holding code and vendor only; everything a client owns lives outside
it and survives every deploy and every rollback.

```
/var/www/pelatis/
├── current -> releases/20260816-143000/   ← flipping this is the deploy
├── releases/
│   └── 20260816-143000/    public/ src/ components/ templates/ vendor/ bin/
└── shared/
    ├── content/            ← its own private git repository
    │   ├── pages/el/       pages, one YAML file each
    │   ├── uploads/        images, tracked in git with everything else
    │   ├── .revisions/     per-save snapshots, also tracked
    │   └── redirects.yml
    ├── var/                cache, locks, submissions — never deployed
    ├── roles.yml
    └── .env                secrets — never deployed, never committed
```

The vhost docroot is `current/public/`, and `/uploads/` is **aliased** to
`shared/content/uploads/` — see `nginx.conf.example`. No symlink out of the
docroot, and stored `src` values stay `/uploads/...` either way.

```bash
chown -R www-data:www-data shared/content shared/var
```

### One deploy

```bash
DEPLOY_ROOT=/var/www/pelatis DEPLOY_REPO=git@github.com:dope/pelatis.git \
  bin/deploy.sh v1.4.0
```

`shared/.env` must exist before the first deploy. The deploy, doctor, smoke
server and PHP-FPM all read that same file; it is not copied into a release.

Fetch the exact revision → `composer install --no-dev -o` → `composer audit` →
the test suite → `bin/doctor` **against the shared content this release is
about to serve** → a smoke test of the release serving itself → *then* flip
`current` → smoke-test the live site → purge the edge.

Everything before the flip is reversible by doing nothing: a failure there
leaves the running site untouched, same code, same content, no downtime. If the
post-switch smoke test fails, the switch is reversed before the deploy reports
success.

```bash
bin/rollback.sh          # back to the previous release, one command
```

Content is never rolled back — no client-owned state lives inside a release.

The private Composer repository credential lives in `shared/auth.json`, read via
`COMPOSER_HOME`. It is deploy-key scoped to the package repository, never enters
a release directory, and is rotated by replacing that one file and re-running
`bin/deploy.sh` — there is no second place it is cached. Rotate whenever an
operator leaves, and at least annually.

### Health check

```bash
bin/doctor            # report
bin/doctor --quiet    # exit code only, for cron
```

Validates YAML shape, unique slugs and block ids, duplicate block ids, component
schemas and templates (including whether they compile), the layouts, redirect
targets and loops, internal page ids, configured paths and their permissions,
required PHP extensions, safe production auth settings, and disk headroom. A
deploy runs it before switching, so a schema rename that would break a live page
stops the deploy instead of the site.

### Cron

```cron
# Content backup. The recovery point is at most one hour, not "whenever
# someone remembered". Holds the site-wide content lock, so it can never
# commit a half-applied save.
17 * * * *  cd /var/www/pelatis/current && ENV_FILE=/var/www/pelatis/shared/.env \
              BACKUP_ALERT='/usr/local/bin/alert' bin/backup

# The drill that makes the backup a backup: clone the remote somewhere else,
# check every referenced image is in it, run doctor against the result.
# A green backup cron without this proves a push happened, nothing more.
40 4 * * 1  cd /var/www/pelatis/current && ENV_FILE=/var/www/pelatis/shared/.env \
              BACKUP_REMOTE=git@github.com:dope/pelatis-content.git \
              DRILL_ALERT='/usr/local/bin/alert' bin/restore-drill

# Disk, derivative-cache growth, permissions, content health.
*/30 * * * * cd /var/www/pelatis/current && ENV_FILE=/var/www/pelatis/shared/.env \
              bin/doctor --quiet || /usr/local/bin/alert 'doctor failed'

# Public smoke check.
*/5 * * * *  curl -fsS -o /dev/null https://pelatis.gr/ || /usr/local/bin/alert 'site down'
```

Log rotation, `/etc/logrotate.d/pelatis`:

```
/var/www/pelatis/shared/var/*.log {
    weekly
    rotate 8
    compress
    missingok
    notifempty
    copytruncate
}
```

### A new site

```bash
bin/new-site ../pelatis-gr "Πελάτης ΑΕ" pelatis.gr
```

Copies code, components and templates — never content, uploads, revisions or
secrets. The scaffold starts with `SITE_NOINDEX=1`, because nobody has approved
the copy yet.

Copy `.env.example` to `/var/www/pelatis/shared/.env`, fill it, make it readable
only by the deploy/PHP-FPM users, and point the FPM pool at that one file
(`/etc/php/8.4/fpm/pool.d/pelatis.conf`):

```ini
env[ENV_FILE] = /var/www/pelatis/shared/.env
```

The standard atomic layout also discovers `shared/.env` automatically; the
explicit FPM value makes the contract visible in server configuration and keeps
working if releases are mounted elsewhere.

**`AUTH_DEV_BYPASS=0` in production.** With it on, authentication is skipped
for every panel request, regardless of its source address.

---

## Cloudflare setup

### 1. Access — this is your entire login system

Zero Trust → Access → Applications → **Add a self-hosted application**

- Domain: `pelatis.gr`, path `admin.php`
- Policy: Allow → Emails → the client's address, plus yours
- Login method: One-time PIN

Copy the **Application Audience (AUD) tag** into `CF_ACCESS_AUD` and your team
domain into `CF_ACCESS_TEAM_DOMAIN`.

Free for up to 50 users. That is the whole auth layer — `src/Auth.php` only
verifies the JWT signature so nobody can reach the origin directly and bypass
Access. There are no passwords stored anywhere in this codebase.

### 2. R2 — media storage

Create a bucket, connect a custom domain (`media.pelatis.gr`), then R2 → Manage
API tokens → create a token with **Object Read & Write** scoped to that bucket.

Zero egress fees, so image-heavy client sites cost nothing to serve. With
`R2_ENABLED=0` everything falls back to `content/uploads/` and the CMS behaves
identically — handy for local work.

### 3. Image transformations

**Optional, and off by default.** Derivatives are generated locally with GD:
`/img.php?src=…&w=…` resizes on first request, writes to `var/cache/images/`
keyed by source content hash + width + format, and serves it immutable for a
year. Every image renders through `templates/picture.twig` as a `<picture>`
with a WebP source and a JPEG (or PNG, where there is transparency) fallback.

The width allowlist is finite — `320, 640, 960, 1280, 1600, 2048` — and a width
outside it 404s before a byte of the source is read, so an anonymous GET cannot
fill the disk with attacker-chosen variants. `img()` returns an empty string for
one, so it never reaches the markup either.

Cloudflare's own transformations remain available: Speed → Optimization → Image
Resizing → enable for the zone, then `CF_IMAGES_ENABLED=1`, and `img()` builds
`/cdn-cgi/image/…` URLs instead. The width allowlist applies to that path too,
so both backends serve the same finite set of variants.

It is off by default because the free plan's **5.000 unique transformations per
month are per account, shared across every zone** — twenty sites at ~200 uniques
each is 4.000 against that cap with no headroom, and no way to attribute or
rebill it per client.

### 4. Cache

The site sends `Cache-Control: s-maxage=31536000` and `Cache-Tag: page:<id>,site`,
so Cloudflare holds pages at the edge and PHP is barely touched. On save, the
CMS purges that page's tag and `site` — landing in roughly 150ms.

`site` is the tag on everything that renders **cross-page** data: the sitemap,
the navigation, hreflang alternates, resolved links. `/sitemap.xml` and
`/robots.txt` carry `site` and *only* `site` — a sitemap tagged `page:<id>`
would never be purged, because the page that changed is never the sitemap.

Create an API token with **Zone → Cache Purge → Purge** on the zone, set
`CF_ZONE_ID` and `CF_API_TOKEN`. Set `CF_PURGE_STRATEGY=everything` if you
would rather not bother with tags on a 5-page site.

A failed purge never fails the save: the content is already on disk, the panel
shows a warning, and the page updates when the TTL expires.

**Headers are not proof that anything is cached.** Cloudflare does not cache
HTML by default whatever you send, so the zone needs explicit rules:

1. Cache Rules → *Cache public HTML*: `http.host eq "pelatis.gr"` → **Eligible
   for cache**, Edge TTL *Use cache-control header*.
2. Cache Rules → *Bypass panel and forms*, **above** the rule above:
   `http.request.uri.path eq "/admin.php" or http.response.headers["cache-control"][0]
   contains "no-store"` → **Bypass cache**.

Then verify, twice, rather than trusting the config screen:

```bash
curl -sI https://pelatis.gr/          | grep -i cf-cache-status   # want HIT (second request)
curl -sI https://pelatis.gr/admin.php | grep -i cf-cache-status   # want BYPASS
curl -sI https://pelatis.gr/epikoinonia | grep -i cf-cache-status # want BYPASS — form page
```

A contact page served from a shared cache hands one visitor's CSRF token to the
next, which is why form pages are `private: true` and send `no-store`. The
bypass rule is what makes that survive a cache rule somebody adds later.

---

## SEO

Every page carries a `seo:` map beside its `title` and `slug`. It renders in the
panel as a **collapsed** card at the top of the edit form, and every field in it
is optional: an editor who never opens it can still save.

```yaml
seo:
  title: ''                        # max 60
  description: ''                  # max 155
  og_image: { src: '', alt: '', width: 0, height: 0 }
  noindex: false                   # keeps the page out of the sitemap too
  canonical: ''                    # editable: admin
```

**Nothing here has to be filled in.** Each empty field falls back, at render
time, to something the page already has:

| Empty field | Falls back to |
|---|---|
| `title` | the page title |
| `description` | the page's first `textarea` or `richtext` value, tags stripped, cut to 155 on a word boundary |
| `og_image` | the page's first `image`, then `site.og_default` |

Both derived answers come off the **schema** — the first field of that *type* in
block order — not off a "first long text field" heuristic with a length to tune.
And both are resolved on the way out, never written back: a derived description
sitting in the page file would look filled in from the panel, so nobody would
ever replace it with a real one, and it would go stale the moment the copy above
it changed. The panel shows it as the description input's **placeholder**, so an
empty box does not read as "this page has no description".

It lives in the per-locale page file, so per-language SEO comes free when Phase
9 resolves a second locale directory — there is no separate SEO store to keep in
step. `og_image` is an ordinary `image` field, which means its `src` is
restricted to `media_bases` and its dimensions are server-derived. It is
declared `decorative: true`, so it asks for no alt and emits no `og:image:alt`:
the share card already carries the title and the description as text beside it,
and asking a client to describe the same banner on every page buys "εικόνα"
typed to clear a field.

`canonical` is admin-only on purpose. Every other field here costs a client a
worse search result if they get it wrong; a canonical pointing at a URL they do
not own hands that URL the page's ranking, silently, for weeks.

`/sitemap.xml` is generated from the content files on request — `lastmod` from
each page's mtime, `xhtml:link` alternates for the resolved locale, no
`changefreq` or `priority`, and `noindex` pages left out. `/robots.txt` points
at it, and honours `SITE_NOINDEX` with `Disallow: /` so a pre-launch domain says
the same thing in both places a crawler looks.

Both are ordinary routes through `index.php`. Any server config that intercepts
`/robots.txt` as a static file breaks them — `.ddev/nginx_full/nginx-site.conf`
is taken over from the DDEV default for exactly that reason.

`bin/doctor` refuses a production box whose `site.base_url` is still
`localhost`: every absolute URL the site publishes is built from it, and the
only symptom of getting it wrong is months of nothing being indexed.

---

## What is deliberately not here

- Adding, removing or reordering components from the panel
- Multi-user accounts and roles (Cloudflare Access handles who gets in)
- Draft / publish workflow — saving publishes
- Forms and form submissions (use a Worker, or Formspree, plus Turnstile)
- A media library — `image` fields upload and replace in place

Each of those is a real chunk of work. Leave them out until a client actually
pays for one.

## Safety net

Every save writes a timestamped copy to `content/.revisions/` first and keeps
the last 10, and the panel restores one for you (admin only, CSRF-protected,
re-sanitised on the way back in — never a `cp`).

Page writes are atomic (temp file + rename), so an interrupted save cannot
leave a half-written page live.

`shared/content/` **is** a git repository — pages, revisions and uploads
together — pushed hourly by `bin/backup` under the site-wide content lock, so a
commit can never catch a half-applied save. `bin/restore-drill` clones that
remote weekly, checks every image a page references is actually in it, and runs
`bin/doctor` against the result. A green backup cron proves a push happened; the
drill is what proves the push is a site.

That is one mechanism and one restore: `git clone`, and the site is whole,
pictures included. It stops being right at a gallery site or past a few hundred
MB — that site sets `R2_ENABLED=1` and the media moves out, which is a config
change rather than a rewrite.

## Tests

```
tests/01_render.php      component discovery, page rendering, <picture> markup, image URLs
tests/02_admin.php       form generation, CSRF, auth, uploads and normalization,
                         absence of structural controls
tests/03_lockdown.php    hostile save: XSS, hostile URLs, locked fields, injected
                         fields, retyping components, Word paste, forged image
                         dimensions, oversized lists
tests/04_hardening.php   regressions from the security review, decompression bombs
tests/05_concurrency.php stale saves, conflict re-render, presence markers
tests/06_production.php  production contracts: paths, atomic release, derivative
                         route and encoder, cache policy, boot guard
tests/07_shipkit.php     nav, redirects, 500.twig, SITE_NOINDEX, sitemap and
                         robots routes, bin/doctor, release switching and
                         rollback, backup and restore drill
tests/08_form.php        contact validation, spam controls, durable delivery,
                         retry state, permissions and retention
tests/09_package.php     clean Composer install, archive boundary, package
                         fallbacks, site overrides and installed CLI tools
```

973 checks: `ddev exec bash tests/run.sh`. Run all of them after touching
`Fields`, `Admin`, `Components`, `Media` or anything in `bin/`.

CI (`.github/workflows/ci.yml`) runs lint, `composer audit`, `bin/doctor`, and
the portable suite on PHP 8.4. Real HTTPS probes additionally run in DDEV and
at deploy time.
