# Dopamine FlatCMS site

Create a site, start DDEV, and open the panel:

```bash
composer create-project dopamine/flatcms-skeleton my-site --no-install --ignore-platform-req=php
cd my-site
cp .env.example .env
ddev start
ddev launch /admin.php
```

`--no-install` and `--ignore-platform-req=php` keep the host PHP out of the
picture: DDEV's post-start hook runs `composer install` inside the container,
where PHP 8.4 and all required extensions exist.

The engine lives in `vendor/dopamine/flatcms`. Site-owned files live here:

- `theme/` is the site: layouts, components, `theme.yml` (global CSS/JS,
  local or CDN) and `assets/`. Everything in `theme/` is this site's own;
  the engine keeps only `@flatcms/…` (head, picture, video facade).
- `admin-theme/` brands the panel — `assets/css/admin.css` is the supported
  surface; overriding panel templates tracks engine internals.
- `content/` contains pages, globals, revisions, and uploads.
- `lang/` holds interface strings — see below. Absent until you need it.
- `config.php` and `.env` configure this installation.

A new site opens on its own **first-run page** — three placeholder components
this skeleton copied into `theme/components/`, a placeholder that
says where you are and points at the docs. Replace it: clone the
[demo theme](https://github.com/panfotis/flatcms-theme-demo) over `theme/`, or
write your own components here. Once you do, every component on the site is
yours outright and there is nothing in `vendor/` left to override.

CSS and JS are served as **content-hashed bundle files** under `/assets/` —
written on demand, cached forever by the browser, and replaced by a new URL
whenever a byte changes, so there is nothing to purge and no build step to run.
In the release layout they land in `shared/assets/` automatically (derived
from `VAR_PATH`; `PUBLIC_ASSETS_PATH` overrides); php-fpm must be able to
write there. If they cannot be written, every page falls back to
inlining its CSS/JS — slower, never broken.

Styling, cheapest first: add rules to `theme/assets/css/site.css` (emitted last,
wins the cascade) → edit the component's own `.css`, which sits beside its
template → change the template. Nothing here receives engine updates, because
nothing here comes from the engine.

The `<head>` is the one exception — it is engine-owned (`@flatcms/head.twig`)
so every site keeps receiving head improvements. Three rules:

- **To add tags** (analytics, verification metas, preloads): create
  `theme/head-extra.twig` and put them there. It renders at the end of the
  head with the full page context.
- **To replace or remove a section**: create `theme/head.twig` containing
  `{% extends '@flatcms/head.twig' %}` and override a named block —
  `generator`, `title`, `seo`, `social`, `icons`, `alternates`, `extra`.
  Everything you do not override keeps tracking the engine. A standalone
  copy fails `bin/doctor`, because a copy freezes your head forever.
- **Most sites need neither.**

Never edit files in `vendor/`; Composer updates replace them.

## Interface strings and languages

Every string the engine shows — the panel, the 404, the contact form's labels
and refusals — comes from a catalogue keyed by language. `ADMIN_LOCALE` picks
the panel's; a visitor gets the language of the page they are on.

**To reword one of them, or add a string of your own, create `lang/<locale>.php`
here with only the keys you care about:**

```php
<?php

return [
    'edit.save' => 'Καταχώρηση',            // reword one the engine ships
    'car.label' => 'Επιλέξτε αυτοκίνητο',   // add one for your own component
];
```

Your file is merged over the engine's, key by key. Everything you do not
mention still resolves from the engine — including keys a later release adds —
so there is nothing to keep in sync and no reason to copy the whole catalogue.

A component's own labels need no catalogue at all: an unknown key renders as
itself, so `label: Επιλέξτε αυτοκίνητο` straight in `schema.yml` just works.
Use a key only when the string has to exist in more than one language, and
prefix your keys (`car.label`, not `form.car`) so a future engine string cannot
collide with yours.

**A public language is two things**, not one: an entry in `config.php`'s
`locales`, *and* a `lang/<locale>.php`. With only the first, the pages are
translated but the engine's own strings — the send button, validation messages,
the 404 — stay English.

`bin/doctor` reports a catalogue that has fallen behind the source language.

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
