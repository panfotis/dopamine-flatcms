<?php
/**
 * Per-site configuration. One install per site, so this file is the only thing
 * that changes between clients (plus components/ and content/).
 *
 * Secrets: keep them in environment variables in production. env() falls back
 * to the literal default so local dev works without a .env file.
 */

declare(strict_types=1);

if (!function_exists('env')) {
    function env(string $key, ?string $default = null): ?string
    {
        $v = getenv($key);
        return ($v === false || $v === '') ? $default : $v;
    }
}

if (!function_exists('env_bool')) {
    /**
     * Booleans from the environment are strings, and `(bool) "false"` is true —
     * which is exactly the kind of thing that silently leaves a security flag on.
     * Only these literals mean yes. Everything else, including "false", "off",
     * "no" and any typo, means no.
     */
    function env_bool(string $key, bool $default = false): bool
    {
        $v = getenv($key);
        if ($v === false || $v === '') {
            return $default;
        }

        return in_array(strtolower(trim($v)), ['1', 'true', 'yes', 'on'], true);
    }
}

/**
 * Production is an atomic-release layout (plan §3): `current` is a symlink to
 * releases/<timestamp>/, which holds code + vendor and is disposable. Client
 * state lives outside it and is pointed at by these two variables:
 *
 *   /var/www/pelatis/
 *     current -> releases/20260816-143000/   # code only; switching it is the deploy
 *     shared/content/                        # its own private git repository
 *     shared/var/                            # cache, locks, submissions; never deployed
 *     shared/.env                            # secrets; never deployed or committed
 *
 * See tests/fixtures/production.env for the concrete values, and the
 * atomic-release spike in tests/06_production.php for the proof that flipping
 * `current` cannot touch either shared directory.
 */
$contentPath = env('CONTENT_PATH', __DIR__ . '/content');
$varPath     = env('VAR_PATH', __DIR__ . '/var');

$config = [
    'site' => [
        'name'     => 'Demo Πελάτη',
        'locale'   => 'el',
        'base_url' => env('SITE_BASE_URL', 'http://localhost:8080'),
    ],

    // Single-locale storage is the permanent page-storage shape: pages live at
    // content/pages/*.yml today and Phase 9 adds content/pages/<locale>/ beside
    // it. Nothing before then may invent a second layout.
    'paths' => [
        'content'    => $contentPath,
        'components' => __DIR__ . '/components',
        'templates'  => __DIR__ . '/templates',
        'cache'      => $varPath . '/cache',
        // Production: nginx aliases /uploads/ to shared/content/uploads/.
        'uploads'    => env('UPLOADS_PATH', __DIR__ . '/public/uploads'),
    ],

    // Twig template cache. Set false while developing.
    'twig_cache' => false,

    'auth' => [
        // 'cf_access' = trust Cloudflare Access (recommended, zero auth code)
        // 'none'      = wide open, local dev only
        'mode' => env('AUTH_MODE', 'cf_access'),

        // From Zero Trust dashboard: your team domain and the application AUD tag.
        'team_domain' => env('CF_ACCESS_TEAM_DOMAIN', 'your-team.cloudflareaccess.com'),
        'aud'         => env('CF_ACCESS_AUD', ''),

        // Skips authentication entirely. Defaults to OFF and is never inferred
        // from the request — an earlier version gated this on REMOTE_ADDR being
        // loopback, which breaks in DDEV (requests arrive from the router
        // container) and, far worse, opens the panel to the internet the moment
        // the origin sits behind a local proxy such as cloudflared.
        //
        // Set AUTH_DEV_BYPASS=1 in .ddev/config.yaml. Never in production.
        'dev_bypass' => env_bool('AUTH_DEV_BYPASS', false),

        // Who may do what. The production boot guard below refuses to start
        // without it, so a prod box can never come up with "everyone is an
        // admin" as the implicit default — and Auth denies any authenticated
        // address the file does not list.
        'roles_file' => env('ROLES_FILE', __DIR__ . '/config/roles.yml'),
    ],

    'r2' => [
        // Leave 'enabled' false and uploads go to public/uploads instead.
        'enabled'     => env_bool('R2_ENABLED'),
        'account_id'  => env('R2_ACCOUNT_ID', ''),
        'access_key'  => env('R2_ACCESS_KEY_ID', ''),
        'secret_key'  => env('R2_SECRET_ACCESS_KEY', ''),
        'bucket'      => env('R2_BUCKET', ''),
        // Public hostname bound to the bucket, e.g. https://media.pelatis.gr
        'public_base' => env('R2_PUBLIC_BASE', ''),
        'prefix'      => 'uploads/',
    ],

    'images' => [
        // Cloudflare image transformations. Free plan: 5.000 unique
        // transformations/month. The zone serving /cdn-cgi/image must be
        // proxied through Cloudflare with Image Resizing enabled.
        'transform'   => env_bool('CF_IMAGES_ENABLED'),
        'quality'     => 82,
        'max_upload'  => 12 * 1024 * 1024, // 12 MB
        'allowed'     => ['image/jpeg', 'image/png', 'image/webp', 'image/avif'],
        // Longest edge kept on the original we store, to stop 8 MB phone photos
        // sitting in R2 forever. Set 0 to store untouched originals.
        'store_max_edge' => 2400,
        // Refuse images whose pixel count would blow up memory when decoded.
        // A 40 MP guard costs ~160 MB peak in GD; a crafted 30000×30000 PNG is
        // a few hundred KB on disk and ~3.6 GB decoded.
        'max_pixels'  => 40_000_000,

        /**
         * The local derivative contract, settled before any GD code exists
         * (Phase 4 writes the encoder against exactly this, and may not widen
         * it). This path is only used when CF_IMAGES_ENABLED is off — with
         * Cloudflare on, /cdn-cgi/image does the same job and none of this runs.
         *
         * Every knob here exists to bound work an anonymous request can cause.
         */
        'derivatives' => [
            // One route, GET only, query-string parameters. Long-cached and
            // fingerprint-free: the src path already changes when the file does.
            'route' => '/img.php',

            // Finite allowlist, not a range. A range lets one attacker fill the
            // derivative cache with 2.048 near-identical files; six widths mean
            // at most six derivatives per source per format, ever.
            'widths' => [320, 640, 960, 1280, 1600, 2048],

            // Format rules. `auto` picks webp when the client sends
            // `Accept: image/webp`, jpeg otherwise. AVIF is deliberately absent:
            // encoding it costs seconds of CPU per image in GD.
            'formats'      => ['auto', 'webp', 'jpeg'],
            'default_format' => 'auto',
            // Sources we will decode. A PNG source still leaves as webp/jpeg;
            // transparency loss is acceptable, an unbounded format matrix is not.
            'decodable'    => ['image/jpeg', 'image/png', 'image/webp'],

            // Source adapters — where a `src` may be read from, in order.
            // 'uploads' reads paths under config.paths.uploads from local disk.
            // 'r2' fetches config.r2.public_base over HTTPS. Anything not
            // matching a configured media base is rejected without a read; this
            // is the same open-proxy guard as Fields::mediaPath, applied at the
            // route instead of at save.
            'sources' => ['uploads', 'r2'],

            // Memory budget for one decode, in bytes. GD holds ~4 bytes per
            // pixel plus the output buffer, so this caps the source at roughly
            // 25 MP — under the 40 MP images.max_pixels store guard on purpose,
            // because that guard runs on *upload* and this runs on an anonymous
            // GET. Refused before decoding, from the header dimensions.
            'memory_budget' => 128 * 1024 * 1024,

            // Cache ceiling. Derivatives are immutable for a given src+w+format,
            // so a year at the edge and in the browser is right; the source path
            // changes when the image does.
            'cache_max_age' => 31536000,

            // Disk ceiling for the local derivative cache. Phase 4 evicts
            // least-recently-used past this; without it a long-lived site fills
            // its disk with thumbnails nobody requests any more.
            'cache_max_bytes' => 512 * 1024 * 1024,
        ],
    ],

    // Where an image `src` is allowed to point. Anything else is rejected on
    // save, so an editor cannot turn the zone's /cdn-cgi/image endpoint into an
    // open proxy that serves third-party content from the client's domain.
    // R2 public_base is appended automatically when configured.
    'media_bases' => ['/uploads/'],

    'cloudflare' => [
        // Purge the edge cache when the client saves.
        'purge_on_save' => env_bool('CF_PURGE_ENABLED'),
        'zone_id'       => env('CF_ZONE_ID', ''),
        'api_token'     => env('CF_API_TOKEN', ''),
        // 'tag' purges only the pages that changed (needs Cache-Tag response
        // header, free on all plans since 2025). 'everything' is fine for a
        // 5-page site and needs no header setup.
        'strategy'      => env('CF_PURGE_STRATEGY', 'tag'),
    ],

    // How long the edge may keep a page. The browser always revalidates;
    // the CDN holds it until we purge.
    'cache' => [
        'browser_max_age' => 0,
        'edge_max_age'    => 31536000,
    ],
];

/**
 * Production boot guard.
 *
 * Every one of these has a safe value for local development and a catastrophic
 * one in production, and each is a single environment variable away from being
 * wrong on a box nobody is watching. A misconfigured panel does not announce
 * itself — it just serves. So refuse to boot instead, loudly, at the only point
 * both entrypoints must pass through.
 *
 * This is a *fail-closed* check: it lists every problem it finds rather than
 * stopping at the first, so fixing a prod box is one round trip, not four.
 */
if (env('APP_ENV', 'dev') === 'prod') {
    $problems = [];

    if ($config['auth']['mode'] === 'none') {
        $problems[] = 'AUTH_MODE=none leaves the panel wide open';
    }
    if ($config['auth']['dev_bypass'] === true) {
        $problems[] = 'AUTH_DEV_BYPASS=1 skips authentication entirely';
    }
    if ($config['auth']['aud'] === '') {
        $problems[] = 'CF_ACCESS_AUD is empty — any Access token from the account would be accepted';
    }
    if (!is_file((string) $config['auth']['roles_file'])) {
        $problems[] = 'roles file missing: ' . $config['auth']['roles_file'];
    }

    if ($problems !== []) {
        throw new \RuntimeException(
            "APP_ENV=prod refuses to start:\n  - " . implode("\n  - ", $problems)
        );
    }
}

return $config;
