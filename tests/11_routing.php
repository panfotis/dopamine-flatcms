<?php
/**
 * The public front controller, as a composition rather than as ingredients.
 *
 * canonicalPath(), localeOf(), cacheHeaders() and robotsHeader() each had
 * coverage already. What had none was the order they run in and which branches
 * skipped the headers applied at the bottom of the request — and that gap was
 * hiding a real defect: with SITE_NOINDEX set, the trailing-slash 301 and the
 * post-submit 303 both `send(); exit;`-ed before X-Robots-Tag was ever set, so
 * a pre-launch domain's redirects were indexable.
 *
 * Site::handle() returns a Response, so every one of these is now an assertion
 * on a status code and a header rather than on a subprocess's stdout.
 */

declare(strict_types=1);

session_start();

require __DIR__ . '/lib.php';

use Dopamine\FlatCms\Cms;
use Dopamine\FlatCms\Site;
use Symfony\Component\HttpFoundation\Request;

putenv('AUTH_DEV_BYPASS=1');

$root = dirname(__DIR__);

/**
 * The rate limiter is the first step of the form pipeline and counts every POST
 * against a file under paths.cache. Left on the shared cache directory it would
 * carry over between runs and make this file pass once and fail after — so the
 * form sections get their own throwaway counter directory, exactly as
 * 08_form.php does.
 */
$sandbox = $root . '/var/cache/routing-' . bin2hex(random_bytes(4));
register_shutdown_function(static function () use ($sandbox): void {
    foreach (glob($sandbox . '/ratelimit/*.json') ?: [] as $f) {
        @unlink($f);
    }
    @rmdir($sandbox . '/ratelimit');
    @rmdir($sandbox);
});

/**
 * The test config, optionally with SITE_NOINDEX on, always pointed at this
 * process's content copy and its own rate-limit counters.
 *
 * @return array<string, mixed>
 */
$conf = static function (bool $noindex = false) use ($root, $sandbox): array {
    if ($noindex) {
        putenv('SITE_NOINDEX=1');
    }
    $config = require $root . '/config.php';
    putenv('SITE_NOINDEX');

    $config['paths']['content'] = content_root();
    $config['paths']['uploads'] = content_root() . '/uploads';
    $config['paths']['cache']   = $sandbox;
    unset($config['paths']['public_assets']);

    // This file asserts routing, not throttling. 08_form.php owns the limiter.
    $config['form']['rate_limit'] = 1000;

    return $config;
};

// ── One URL per page ────────────────────────────────────────────────────────

section('The trailing slash is a redirect, not a second page');

$r = site_get('/about/');
ok($r->getStatusCode() === 301, '/about/ is a 301, not a duplicate of /about');
ok($r->headers->get('Location') === '/about', 'pointing at the slashless canonical');

// Symfony's getQueryString() sorts the parameters, so the redirect reorders
// them. Harmless — every consumer of a query string reads it by name — and it
// is what the front controller has always done. Asserted by content, not by
// byte order, so the normalisation is not mistaken for a contract.
$r = site_get('/about/?utm_source=newsletter&page=2');
$to = (string) $r->headers->get('Location');
ok(str_starts_with($to, '/about?'), 'the redirect still lands on the canonical path');
ok(
    str_contains($to, 'utm_source=newsletter') && str_contains($to, 'page=2'),
    'and carries the whole query string — dropping it would lose the campaign'
);

// A 301 answering a POST is a GET, and the submission is gone. isMethodSafe()
// is the whole guard, and it is the kind of thing that is only ever noticed in
// production, by a client whose contact form silently stopped working.
$r = site(Request::create('/epikoinonia/', 'POST'));
ok($r->getStatusCode() !== 301, 'a POST to the slash variant is not redirected away');

$r = site_get('/about');
ok($r->getStatusCode() === 200, 'the canonical itself renders');

// ── Locale fallback ─────────────────────────────────────────────────────────

section('A language without the page falls back rather than 404ing');

// Untranslated: present in the default language (el) and nowhere else.
$onlyDefault = content_root() . '/pages/el/zz_only_el.yml';
file_put_contents($onlyDefault, "title: Μόνο Ελληνικά\nslug: /mono-el\nblocks: []\n");
register_shutdown_function(static function () use ($onlyDefault): void {
    @unlink($onlyDefault);
});

$cms = new Cms(test_config());
$r = (new Site($cms))->handle(Request::create('/en/mono-el'));
ok($r->getStatusCode() === 200, '/en/mono-el serves the Greek page rather than a dead end');
ok(
    $cms->locale() === 'el',
    'and renders *as* Greek — so its canonical is the Greek URL and the menu is not half-translated'
);

// `fallback: 404` is the other half of that decision and must still 404. The
// default language has nowhere to fall back to, so it is the natural case.
$r = site_get('/definitely-not-a-page');
ok($r->getStatusCode() === 404, 'a slug in no language at all is still a 404');

// ── Redirect map before the 404 ─────────────────────────────────────────────

section('The redirect map is checked before the 404, never after');

// Nearly every one of these sites replaces an existing one, and the old URLs
// are the client's search rankings.
$r = site_get('/contact.html');
ok($r->getStatusCode() === 301, 'a retired URL in redirects.yml is a 301');
ok($r->headers->get('Location') === '/epikoinonia', 'resolved through the page id, so a rename follows');

$r = site_get('/never-existed');
ok($r->getStatusCode() === 404, 'a slug in neither content nor the map is a 404');
ok(
    str_contains((string) $r->headers->get('Cache-Control'), 'no-store'),
    'and is never cached — a 404 cached at the edge outlives the fix'
);

// ── Cache policy ────────────────────────────────────────────────────────────

section('What may be cached at the edge, and what may never be');

$r = site_get('/');
$cc = (string) $r->headers->get('Cache-Control');
ok(str_contains($cc, 's-maxage='), 'an ordinary page is edge-cacheable');
ok($r->headers->get('Cache-Tag') !== null, 'and carries a Cache-Tag so a save can purge it');

// A page carrying a form has a per-visitor CSRF token in it. A shared cache
// that stores one visitor's token and serves it to the next turns every
// submission into a rejected one.
$r = site_get('/epikoinonia');
$cc = (string) $r->headers->get('Cache-Control');
ok(str_contains($cc, 'no-store') && str_contains($cc, 'private'), 'a form page is never edge-cached');
ok($r->headers->get('Cache-Tag') === null, 'and offers no Cache-Tag, because there is nothing to purge');

// ── POST / redirect / GET ───────────────────────────────────────────────────

section('A submission ends in a redirect, a refusal ends in a 422');

// Render once to mint the token and the clock the POST will carry back.
$formCfg = $conf();
site_get('/epikoinonia', $formCfg);
$token = (string) ($_SESSION['form_csrf'] ?? '');
ok($token !== '', 'rendering a form page mints a CSRF token');

// The honeypot path: accepted, stored nowhere, sent nowhere — the cheapest
// genuine `ok` there is, and it exercises the same 303 a real send does.
$r = site(Request::create('/epikoinonia', 'POST', ['csrf' => $token, 'website' => 'bot']), $formCfg);
ok($r->getStatusCode() === 303, 'an accepted submission is a 303, so a refresh cannot send twice');
ok(
    str_ends_with((string) $r->headers->get('Location'), '/epikoinonia?sent=1'),
    'back to the page it came from, with the flag the success message reads'
);

// A refused submission is not a successful page view: 422 lets a monitor tell
// "the form is rejecting everyone" from "the page is fine".
$before = (string) ($_SESSION['form_csrf'] ?? '');
$r = site(Request::create('/epikoinonia', 'POST', ['csrf' => 'wrong']), $formCfg);
ok($r->getStatusCode() === 422, 'a refused submission is a 422, not a 200');
ok(($_SESSION['form_csrf'] ?? '') === $before, 'the token survives the refusal, so the retry is submittable');
ok(
    (int) ($_SESSION['form_opened_at'] ?? 0) > 0,
    'and the clock is reset — minimum time-to-submit measures how long *this* form was on screen'
);

// ── SITE_NOINDEX ────────────────────────────────────────────────────────────

section('A pre-launch domain is not indexable by any route out of it');

$cfg = $conf(true);
ok($cfg['site']['noindex'] === true, 'the fixture config has SITE_NOINDEX on');

$expect = 'noindex, nofollow';

$r = site_get('/', $cfg);
ok($r->headers->get('X-Robots-Tag') === $expect, 'an ordinary page carries it');

$r = site_get('/never-existed', $cfg);
ok($r->headers->get('X-Robots-Tag') === $expect, 'so does the 404');

$r = site_get('/contact.html', $cfg);
ok($r->headers->get('X-Robots-Tag') === $expect, 'so does a redirect-map 301');

// This one failed before Site existed: the trailing-slash 301 called send()
// and exit() at the top of the file, so it never reached the X-Robots-Tag line
// at the bottom. Verified on the wire against the old front controller.
$r = site_get('/about/', $cfg);
ok($r->headers->get('X-Robots-Tag') === $expect, 'and so does the trailing-slash 301');

$r = site_get('/sitemap.xml', $cfg);
ok($r->headers->get('X-Robots-Tag') === $expect, 'and the sitemap');

// The 303 out of an accepted submission was the second exit with the same bug.
site_get('/epikoinonia', $cfg);
$r = site(
    Request::create('/epikoinonia', 'POST', ['csrf' => (string) $_SESSION['form_csrf'], 'website' => 'bot']),
    $cfg
);
ok($r->getStatusCode() === 303, 'the post-submit redirect is still a 303');
ok($r->headers->get('X-Robots-Tag') === $expect, 'and it carries the header too');

// ── The generated files ─────────────────────────────────────────────────────

section('The three generated files are routed, not stored');

foreach ([
    '/sitemap.xml' => 'application/xml; charset=utf-8',
    '/sitemap.xsl' => 'text/xsl; charset=utf-8',
    '/robots.txt'  => 'text/plain; charset=utf-8',
] as $path => $type) {
    $r = site_get($path);
    ok($r->getStatusCode() === 200, $path . ' is served');
    ok($r->headers->get('Content-Type') === $type, 'as ' . $type);
}

// Cross-page output: tagged `site` and never page:<id>, because the page that
// changed is never the sitemap, so a per-page tag would never purge it.
$r = site_get('/sitemap.xml');
ok($r->headers->get('Cache-Tag') === 'site', 'and tagged `site` alone, so a save actually purges it');

summary();
