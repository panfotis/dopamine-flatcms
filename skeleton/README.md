# Dopamine FlatCMS site

Create a site, start DDEV, and open the panel:

```bash
composer create-project dopamine/flatcms-skeleton my-site
cd my-site
cp .env.example .env
ddev start
ddev launch /admin.php
```

The engine lives in `vendor/dopamine/flatcms`. Site-owned files live here:

- `theme/` is the site: layouts, components, `theme.yml` (global CSS/JS,
  local or CDN) and `assets/`. Any file here overrides the engine's copy.
- `admin-theme/` brands the panel — `assets/css/admin.css` is the supported
  surface; overriding panel templates tracks engine internals.
- `content/` contains pages, globals, revisions, and uploads.
- `config.php` and `.env` configure this installation.

Styling ladder, cheapest first: add rules to `theme/assets/css/site.css`
(emitted last, wins the cascade) → copy a component's `.css` beside your own
copy of its folder → copy the whole component folder. Each step up costs you
a file that never receives an engine update again.

Never edit files in `vendor/`; Composer updates replace them.

## Production requirements

- **Cloudflare Access in front of `admin.php` — required.** The panel has no
  password of its own; Access *is* the login system, and a production boot
  refuses to start with auth off.
- **Cloudflare page caching — recommended.** The engine tags every response and
  purges the edge on each save, so pages are served from cache and never stale.
- **R2 — optional.** By default uploads live in `content/uploads/` and travel
  with the content git repo — that repo is the backup: one `git clone` restores
  pages and images together. A media-heavy site flips `R2_ENABLED=1` instead.

Setup steps for all three are in the engine README's "Cloudflare setup"
section (`vendor/dopamine/flatcms/README.md`).

`nginx.conf.example` and `apache.conf.example` in this directory are **web
server virtual hosts** — nothing here reads them. Copy one to the server's
config directory (`/etc/nginx/sites-available/`, `/etc/apache2/sites-available/`)
and edit the certificate path and the php-fpm socket; the domain and deploy
root are already filled in. DDEV writes its own vhost, so locally you need
neither file.

Run the health check from the site root with:

```bash
bin/doctor
```

The `bin/` wrappers also expose deploy, rollback, backup, restore drill, form
retry, and retention jobs while keeping their implementation in the versioned
engine package.

For a private VCS package, add the engine and skeleton repositories to your
global or project Composer configuration before running `create-project`.
