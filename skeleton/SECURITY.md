# Security policy

This document says what counts as a vulnerability here, what does not, and
where to report one. It exists so that a report can be judged against the
system's actual trust boundaries rather than argued case by case.

## The trust model

Three gates, in order. Each one's job is written next to it; crossing any of
them without passing it is a vulnerability.

1. **Cloudflare Access authenticates.** Nobody reaches `/admin.php` logic
   without a valid Access JWT for this application. PHP verifies the token's
   signature against the team's published keys and checks the audience and
   issuer itself (`src/Auth.php`) — the origin does not trust the network
   path, a header, or a source address.
2. **`config/roles.yml` authorizes.** An authenticated address the file does
   not list gets a 403, never an implicit role. Anything malformed in that
   file — unknown role, missing file, wrong shape — denies.
3. **The component schema bounds the write.** A save reads the page's blocks
   from disk and, for each block, pulls only the fields that component's
   `schema.yml` declares — and only those the role may edit — out of the
   request (`Fields::map()`, the only schema walk). Block `id`, `type`, order
   and count, the page id and the slug come from the file only. A forged
   request that posts anything else changes nothing.

Two further boundaries on the public side:

- **An image `src` may only point at `config.media_bases`**, and a derivative
  width must be in the finite allowlist — both checked before a byte of the
  source is read. Anything else would make `/img.php` or the site's
  `/cdn-cgi/image` endpoint an open proxy.
- **Richtext is allowlist-sanitised on save** (`symfony/html-sanitizer`,
  `defaultAction: block`), and there is deliberately no Twig filter that marks
  editor content safe. Escaping at output is the XSS boundary; there is no
  content heuristic to bypass, because there is no content heuristic.

## What is a vulnerability

- Reaching any panel action without a token that verifies against gates 1–2.
- A request an *editor* can send that changes anything `editable: admin` or
  `editable: false`, or that adds, removes, reorders or retypes a block.
- Persisting markup through the richtext sanitiser that executes script when
  rendered by the shipped templates.
- Reading a file outside `config.media_bases` through the image pipeline, or
  outside the content/upload roots through any request.
- A visitor input (`form:`) writing anywhere except `var/submissions/`, or
  altering the recipient / Turnstile configuration.
- CSRF on any mutating panel action.

Report these even if the preconditions seem unlikely.

## What is not a vulnerability

- **An editor changing content they are allowed to edit.** That is the
  product.
- **XSS in a developer-authored component template.** Structure — including
  every `.twig` file — is developer-owned and deployed from this repository.
  A developer who can commit a template can already run code; templates are
  not a trust boundary between them and the site.
- **Anything requiring `AUTH_DEV_BYPASS=1`.** The bypass is a local
  development flag; `APP_ENV=prod` refuses to boot with it on.
- **Anything requiring filesystem, shell or deploy access on the origin.**
  Whoever has that owns the site by definition.
- **Denial of service by resource exhaustion** beyond the caps the code
  already enforces (upload sizes, derivative memory budget, list maxima,
  submission rate limit). Capacity tuning is operations, not a boundary.
- **Reports against the `.ddev` development configuration.**

## Recovery

`content/` is tracked in git and pushed hourly — that is the backup, and the
hourly commit takes the content lock exclusively so it can never capture a
half-applied save. A bad save is `git revert`, and every save also leaves a
revision under `content/.revisions/` restorable from the panel (admin-only;
restore re-runs sanitisation). Visitor submissions live in
`var/submissions/`, outside git, so recovery never republishes PII.

## Reporting

Email **fotis@wearedope.com** with the request that crosses the boundary and
what it changed. Content edited through the panel is expected to appear on
the site — include why the write should have been refused, not only that it
occurred. There is no bug bounty; reports are answered and credited.
