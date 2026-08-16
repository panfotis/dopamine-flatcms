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

- `components/` overrides or adds components.
- `templates/` overrides package templates.
- `content/` contains pages, globals, revisions, and uploads.
- `config.php` and `.env` configure this installation.

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
