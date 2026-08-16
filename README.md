# Dopamine FlatCMS

A flat-file CMS for small client sites (3–10 pages). Components are defined in
files. The client edits text and images — nothing else.

No database. No build step. No admin UI for structure.

```
components/hero/schema.yml   →  the fields the client sees
components/hero/hero.twig    →  how it renders
content/pages/home.yml       →  which components this page has, and their values
```

---

## The design rule

**Structure lives in files. Content lives in the panel.**

You control which components a page has, in what order, with what fields, by
editing `content/pages/*.yml` and `components/*/schema.yml`. The client opens
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

- PHP 8.2+ with `curl`, `json`, `mbstring`, and `gd` (gd only for upload downscaling)
- A web server pointing at `public/`
- Optional: a Cloudflare zone, an R2 bucket

## Quick start (DDEV)

Copy `ddev/config.yaml` to `.ddev/config.yaml`, then:

```bash
ddev start                      # boots and runs composer install
ddev launch                     # the site
ddev launch /admin.php          # the panel
ddev exec bash tests/run.sh     # 111 assertions
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
in `public/uploads/` and saves are instant. The whole thing runs with zero
Cloudflare setup.

Once the core moves to a private Composer package, run `ddev auth ssh` so the
container can reach the repo.

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

Then put it on a page — `content/pages/home.yml`:

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
| `image` | thumbnail + upload button | path or URL |
| `link` | single-line input | `http(s)`, `/path`, `#anchor`, `mailto:`, `tel:` only |
| `select` | dropdown | must match a declared option |

Per-field options: `label`, `hint`, `max`, `required`, `default`,
`placeholder`, `options`, and `editable`, which takes three values:

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

Point the vhost at `public/`. Nothing above it should be reachable — see
`nginx.conf.example`.

```
/var/www/pelatis/
├── public/          ← docroot
├── content/         ← must be writable by php-fpm
├── components/
├── src/
└── vendor/
```

```bash
chown -R www-data:www-data content public/uploads var
```

Set secrets as environment variables rather than editing `config.php`
(in `/etc/php/8.4/fpm/pool.d/pelatis.conf`):

```ini
env[AUTH_MODE]              = cf_access
env[AUTH_DEV_BYPASS]        = 0
env[CF_ACCESS_TEAM_DOMAIN]  = dope.cloudflareaccess.com
env[CF_ACCESS_AUD]          = 4f2c…
env[R2_ENABLED]             = 1
env[R2_ACCOUNT_ID]          = …
env[R2_ACCESS_KEY_ID]       = …
env[R2_SECRET_ACCESS_KEY]   = …
env[R2_BUCKET]              = pelatis-media
env[R2_PUBLIC_BASE]         = https://media.pelatis.gr
env[CF_IMAGES_ENABLED]      = 1
env[CF_PURGE_ENABLED]       = 1
env[CF_ZONE_ID]             = …
env[CF_API_TOKEN]           = …
```

**`AUTH_DEV_BYPASS=0` in production.** With it on, anything arriving with a
loopback address is treated as logged in.

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
`R2_ENABLED=0` everything falls back to `public/uploads/` and the CMS behaves
identically — handy for local work.

### 3. Image transformations

Speed → Optimization → Image Resizing → enable for the zone, then set
`CF_IMAGES_ENABLED=1`.

The `img()` Twig helper builds `/cdn-cgi/image/width=…,format=auto/…` URLs, so
Cloudflare resizes and re-encodes on the fly. Nothing is generated or stored on
your side.

**Free plan: 5.000 unique transformations per month.** A brochure site uses
maybe 120. Past the limit, new transformations return an error rather than
being billed — so the failure mode is broken images, not a surprise invoice.
Worth an alert once you are running a lot of sites.

### 4. Cache

The site sends `Cache-Control: s-maxage=31536000` and `Cache-Tag: page:<id>`,
so Cloudflare holds pages at the edge and PHP is barely touched. On save, the
CMS purges just that page's tag — landing in roughly 150ms.

Create an API token with **Zone → Cache Purge → Purge** on the zone, set
`CF_ZONE_ID` and `CF_API_TOKEN`. Set `CF_PURGE_STRATEGY=everything` if you
would rather not bother with tags on a 5-page site.

A failed purge never fails the save: the content is already on disk, the panel
shows a warning, and the page updates when the TTL expires.

---

## What is deliberately not here

- Adding, removing or reordering components from the panel
- Multi-user accounts and roles (Cloudflare Access handles who gets in)
- Draft / publish workflow — saving publishes
- Multilingual content
- Forms and form submissions (use a Worker, or Formspree, plus Turnstile)
- A media library — `image` fields upload and replace in place

Each of those is a real chunk of work. Leave them out until a client actually
pays for one.

## Safety net

Every save writes a timestamped copy to `content/.revisions/` first and keeps
the last 10. To roll back a bad client edit:

```bash
cp content/.revisions/home.20260816-143012.yml content/pages/home.yml
```

Page writes are atomic (temp file + rename), so an interrupted save cannot
leave a half-written page live.

Put `content/` under git and you get real history and a remote backup for free.

## Tests

```
tests/01_render.php     component discovery, page rendering, image URLs
tests/02_admin.php      form generation, CSRF, auth, absence of structural controls
tests/03_lockdown.php   hostile save: XSS, javascript: URLs, locked fields,
                        injected fields, retyping components, Word paste
```

61 checks. Run them after touching `Fields`, `Admin` or `Components`.
