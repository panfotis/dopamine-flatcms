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

Run the health check from the site root with:

```bash
bin/doctor
```

The `bin/` wrappers also expose deploy, rollback, backup, restore drill, form
retry, and retention jobs while keeping their implementation in the versioned
engine package.

For a private VCS package, add the engine and skeleton repositories to your
global or project Composer configuration before running `create-project`.
