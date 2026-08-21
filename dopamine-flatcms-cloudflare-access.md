# Cloudflare Access — setting up the login

This CMS has no login code. Cloudflare Access authenticates the person and signs a
JWT; `src/Auth.php` verifies its signature, audience and issuer, then looks the
address up in `users.yml`. No passwords are stored anywhere in this repository.

Setting it up is dashboard work, three environment variables and one line in a
YAML file. Below is the whole thing, in order, against the Zero Trust dashboard
as it stands in 2026.

## Before you start

- The domain is on Cloudflare — its nameservers, not just an A record somewhere.
- The site's DNS record is **proxied** (orange cloud). Access only sees traffic
  that passes through Cloudflare; a grey-cloud record bypasses it entirely.
- You have a Zero Trust team domain. The first visit to
  [one.dash.cloudflare.com](https://one.dash.cloudflare.com) asks you to pick one
  and to choose a plan — **Free** covers 50 users, which is every client site.

Zero Trust is account-level. It is not inside the domain where DNS, SSL and
Caching live, so nothing below is reachable from there.

## 1. Enable One-time PIN

**Integrations → Identity providers → Add new → One-time PIN → Save.**

Do this first. Until an identity provider exists, the login screen offers only
"Sign in with Cloudflare", which works for you and for nobody else — a client
without a Cloudflare account cannot get past it.

One-time PIN needs no configuration: the visitor types an email address and
receives a six-digit code, valid for ten minutes.

## 2. Create the application

**Access controls → Applications → Add an application → Self-hosted and private
→ Public DNS → Continue.**

The "private" wording is about Cloudflare Tunnel, which you do not need — the
origin is already public and already behind the proxy.

Fill in the destination:

| Field | Value |
|---|---|
| Subdomain | `www`, or blank for the apex |
| Domain | `example-domain.com` |
| **Path** | `admin.php` |

The path is what keeps the site public. Access protects the paths you name and
nothing else, so `/admin.php` requires a login while every page a visitor reads
is untouched. Leave the path empty and the whole site sits behind the login.

Leave browser rendering (RDP/SSH/VNC) off.

## 3. Add the policy

Policies are default-deny: without one, nobody gets in.

**Create new policy**, or attach an existing one from **Add current policies**:

- Name: `admins`
- Action: `Allow`
- Include → Selector `Emails` → the client's address, and yours

Save the policy, then save the application.

A policy is a reusable object. Later edits live under **Access controls →
Policies**, and reach every application using it.

## 4. Point the origin at the application

Open the application again and copy the **Application Audience (AUD) tag** from
**Additional settings**. It is a 64-character hex string.

Put it, and your team domain, in the site's `.env`:

```
AUTH_MODE=cf_access
CF_ACCESS_TEAM_DOMAIN=your-team.cloudflareaccess.com
CF_ACCESS_AUD=<the AUD tag>
```

`config.php` reads these. The AUD is the reason a token minted for a different
application in the same account is refused: `src/Auth.php` compares it against
the `aud` claim, and the team domain against the issuer. With `APP_ENV=prod`, an
empty `CF_ACCESS_AUD` stops the site from booting rather than accepting anything
your account ever signed.

## 5. Add the person to users.yml

```yaml
- { email: someone@example-domain.com, role: admin }
```

Two lists, deliberately: **Access decides who gets as far as the panel,
`users.yml` decides what they may do once there.** An address that authenticates
but is not listed is refused outright, and the refusal is written to the error
log with the address to add. Without that separation, whoever administers the
Access application — often the client's IT, not you — would silently hand out
edit rights to every page.

So adding a person is two steps in two places, and removing one is the reverse:
delete the Access policy entry first, because that is what invalidates a session
somebody is already holding.

Roles are `admin` (every field, plus revisions and restore) and `editor` (fields
marked `editable: true`). Anything else in that column is ignored rather than
guessed at.

The file the engine actually reads is whatever `USERS_FILE` names, defaulting to
`<project>/users.yml`. In an atomic-release layout it points outside the
release directory so it survives a deploy — check the site's `.env` before
editing, or ask the engine directly:

```bash
php -r 'require "vendor/autoload.php"; $c = require "config.php"; echo $c["auth"]["users_file"], PHP_EOL;'
```

It is read on every request. There is nothing to restart.

## 6. Verify

In a private window, in this order:

1. `https://example-domain.com/` — loads normally. If it now asks for a login,
   the path in step 2 is missing.
2. `https://example-domain.com/admin.php` — redirects to
   `your-team.cloudflareaccess.com`. If PHP answers instead, the DNS record is
   not proxied.
3. Enter the address from the policy — the code arrives by email.
4. After verifying, the panel opens. Being recognised and then refused means
   step 5 is missing or names a different address than the one you logged in
   with.
