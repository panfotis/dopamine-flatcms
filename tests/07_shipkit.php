<?php
/**
 * Phase 5 — everything a site needs underneath it before a client sees it.
 *
 * Navigation, redirects, the error page, the noindex flag, bin/doctor, and the
 * deploy and backup scripts. Separate from 06_production because that file is
 * the Phase 0 contracts; this is the kit built on top of them.
 */

declare(strict_types=1);

require __DIR__ . '/lib.php';

use Symfony\Component\Yaml\Yaml;

$root = dirname(__DIR__);
$cms = cms();
$liveHttp = getenv('TEST_LIVE_HTTP') !== '0';
$testBaseUrl = rtrim(getenv('TEST_BASE_URL') ?: 'https://dopamine-flatcms.ddev.site', '/');

/** Run a shell command in the project root and hand back status + output. */
function sh(string $command, array $env = []): array
{
    $prefix = '';
    foreach ($env as $k => $v) {
        $prefix .= escapeshellarg($k . '=' . $v) . ' ';
    }

    $out = [];
    $status = 0;
    exec('cd ' . escapeshellarg(dirname(__DIR__)) . ' && env ' . $prefix . $command . ' 2>&1', $out, $status);

    return [$status, implode("\n", $out)];
}

$curl = static fn (string $path, string $flags = '-sik'): string => (string) shell_exec(
    'curl ' . $flags . ' ' . escapeshellarg($testBaseUrl . $path) . ' 2>&1'
);

// ── Navigation ──────────────────────────────────────────────────────────────

section('The menu comes from nav: keys, not from slug order');

$nav = $cms->nav();
ok($nav !== [], 'pages declaring nav: appear in the menu');
ok(array_column($nav, 'order') === array_values(array_column($nav, 'order')), 'the menu is a list');

// Content::list() sorts by slug, so "/" precedes "/epikoinonia" by accident.
// Give the contact page a lower order and the menu must follow the key, not
// the accident — otherwise nothing here proves anything.
$contact = $root . '/content/pages/el/epikoinonia.yml';
copy($contact, $contact . '.nav.bak');
$page = Yaml::parseFile($contact);
$page['nav'] = ['label' => 'Πρώτο', 'order' => 1];
file_put_contents($contact, Yaml::dump($page, 6, 2));

$reordered = array_column(cms()->nav(), 'label');
ok($reordered[0] === 'Πρώτο', 'a lower order really does move a page to the front: ' . implode(' · ', $reordered));
ok($reordered !== array_column($nav, 'label'), 'and the order is not merely the slug sort under another name');

rename($contact . '.nav.bak', $contact);

$navless = $cms->content->list();
$page = Yaml::parseFile($contact);
copy($contact, $contact . '.nav.bak');
unset($page['nav']);
file_put_contents($contact, Yaml::dump($page, 6, 2));

ok(count(cms()->nav()) === count($navless) - 1, 'a page with no nav: key is not in the menu');
ok(cms()->content->findBySlug('/epikoinonia') !== null, 'but is still perfectly reachable');
rename($contact . '.nav.bak', $contact);

$html = cms()->renderPage(cms()->content->findBySlug('/'));
contains($html, '<nav class="site-nav"', 'the layout renders the menu');
contains($html, 'aria-current="page"', 'and marks the page you are on');

// ── Redirects ───────────────────────────────────────────────────────────────

section('Redirects are checked before the 404');

ok($cms->redirectFor('/index.html') === '/', 'a path target resolves');
ok($cms->redirectFor('/contact.html') === '/epikoinonia', 'a page-id target resolves to that page\'s current slug');
ok($cms->redirectFor('/no-such-old-url') === null, 'anything not listed still 404s');

// The point of an id target: the redirect follows a slug rename, exactly as an
// internal link does. A redirect frozen to an old slug is a 301 into a 404.
copy($contact, $contact . '.redir.bak');
$page = Yaml::parseFile($contact);
$page['slug'] = '/nea-epikoinonia';
file_put_contents($contact, Yaml::dump($page, 6, 2));
ok(cms()->redirectFor('/contact.html') === '/nea-epikoinonia', 'and follows that slug when it is renamed');
rename($contact . '.redir.bak', $contact);

if ($liveHttp) {
    $live = $curl('/contact.html');
    contains($live, '301', 'over HTTP the old path 301s');
    contains($live, 'location: /epikoinonia', 'to the current slug');
    missing($curl('/definitely-not-a-page'), '301', 'while an unlisted path is not redirected anywhere');
    contains($curl('/definitely-not-a-page'), '404', 'it is a 404');
}

// ── Error handling ──────────────────────────────────────────────────────────

section('A template error renders 500.twig, not a stack trace');

// A component whose template cannot compile — the schema-rename case, which is
// how this failed on a live site: a white page with a PHP fatal printed on it.
$broken = $root . '/components/_broken';
register_shutdown_function(static function () use ($broken): void {
    array_map('unlink', glob($broken . '/*') ?: []);
    @rmdir($broken);
});
mkdir($broken, 0775, true);
file_put_contents($broken . '/schema.yml', "label: Broken\nfields: {}\n");
file_put_contents($broken . '/_broken.twig', '{{ no_such_twig_function() }}');

$victim = $root . '/content/pages/el/_broken.yml';
file_put_contents($victim, Yaml::dump([
    'title'  => 'Broken',
    'slug'   => '/_broken',
    'blocks' => [['id' => 'b', 'type' => '_broken', 'fields' => []]],
], 6, 2));

if ($liveHttp) {
    $boom = $curl('/_broken');
    contains($boom, '500', 'the response is a 500');
    contains($boom, 'Κάτι πήγε στραβά', '500.twig is what the visitor sees');
    missing($boom, 'Unknown "no_such_twig_function"', 'the Twig message does not reach the page');
    missing($boom, '/var/www', 'nor any filesystem path');
    missing($boom, '#0 ', 'nor a stack trace');
    contains($boom, 'cache-control: no-store', 'and a broken page is never cached');
}

// Away immediately, not at shutdown: bin/doctor runs later in this file and a
// deliberately broken component would fail it for the wrong reason.
unlink($victim);
array_map('unlink', glob($broken . '/*') ?: []);
rmdir($broken);

// ── SITE_NOINDEX ────────────────────────────────────────────────────────────

section('SITE_NOINDEX keeps a pre-launch domain out of the index');

ok($cms->config['site']['noindex'] === false, 'off by default — a live site must not quietly deindex itself');
ok($cms->robotsHeader() === null, 'so no header is offered');
if ($liveHttp) {
    missing($curl('/'), 'x-robots-tag', 'and none reaches the wire');
}

putenv('SITE_NOINDEX=1');
$flagged = require $root . '/config.php';
putenv('SITE_NOINDEX');
ok($flagged['site']['noindex'] === true, 'SITE_NOINDEX=1 turns it on');
ok((new \Dopamine\FlatCms\Cms($flagged))->robotsHeader() === 'noindex, nofollow',
    'and the header the entrypoint sets is noindex, nofollow');

// env_bool, so the usual string-boolean trap does not quietly deindex a live
// site — this is a flag whose failure mode is invisible for weeks.
foreach (['0', 'false', 'off', ''] as $off) {
    putenv('SITE_NOINDEX=' . $off);
    $c = require $root . '/config.php';
    ok($c['site']['noindex'] === false, '"' . $off . '" does not turn it on');
}
putenv('SITE_NOINDEX');

// ── SEO routes over real HTTP ───────────────────────────────────────────────

section('/sitemap.xml and /robots.txt are served, and tagged for purging');

// Over nginx, because both are paths with no page file behind them: they only
// work if the server hands anything that is not a real file to index.php. The
// DDEV default config carried a `location = /robots.txt` that served it as a
// static file and 404'd — right in production, broken on every dev machine.
if ($liveHttp) {
    $sitemapLive = $curl('/sitemap.xml');
    contains($sitemapLive, '200', '/sitemap.xml serves 200 over HTTP');
    contains($sitemapLive, 'content-type: application/xml', 'as XML');
    contains($sitemapLive, '<urlset', 'with a urlset');
    contains($sitemapLive, '<loc>' . $cms->config['site']['base_url'] . '/</loc>', 'listing the home page at this host');

    $robotsLive = $curl('/robots.txt');
    contains($robotsLive, '200', '/robots.txt serves 200 over HTTP');
    contains($robotsLive, 'content-type: text/plain', 'as plain text');
    contains($robotsLive, 'Sitemap: ' . $cms->config['site']['base_url'] . '/sitemap.xml', 'and points at the sitemap');

    // The tag is the whole reason this is not stale a minute after a client adds a
    // page: `site` is what every save purges, and a per-page tag never would be.
    foreach (['/sitemap.xml' => $sitemapLive, '/robots.txt' => $robotsLive] as $path => $head) {
        contains($head, 'cache-tag: site', $path . ' carries the site cache tag');
        missing($head, 'cache-tag: page:', '...and no page tag (' . $path . ')');
        contains($head, 's-maxage=31536000', '...while still being held at the edge (' . $path . ')');
    }
}

// Nothing caches the sitemap on this side of the edge, so a page added right
// now is in it on the next request. Everything after that is Cloudflare's, and
// the `site` tag above is what makes the purge reach it.
$newPage = $root . '/content/pages/el/_sitemap.yml';
register_shutdown_function(static fn (): bool => @unlink($newPage));
file_put_contents($newPage, Yaml::dump([
    'title' => 'Νέα', 'slug' => '/nea-selida', 'blocks' => [],
], 6, 2));
if ($liveHttp) {
    contains($curl('/sitemap.xml'), '<loc>' . $cms->config['site']['base_url'] . '/nea-selida</loc>',
        'a page added a second ago is already in the sitemap — it is generated, not stored');
}
unlink($newPage);

// And every write path purges `site` alongside the page tag, which is the half
// Cloudflare needs. Mechanical, because "remember to add the tag" is not a
// mechanism: a third mutating flow that forgot it would leave a stale sitemap
// at the edge for a year, and nothing would say so.
$adminSrc = (string) file_get_contents($root . '/src/Admin.php');
ok(substr_count($adminSrc, "purge([Cloudflare::tagFor(\$id), 'site'])") === 2,
    'both save and restore purge page:<id> together with `site`');
ok(!preg_match("/purge\(\[Cloudflare::tagFor\(\\\$id\)\]\)/", $adminSrc),
    'and neither purges the page tag alone, which would leave the sitemap stale at the edge');

// ── bin/doctor ──────────────────────────────────────────────────────────────

section('bin/doctor passes a healthy site');

[$status, $out] = sh(escapeshellarg(PHP_BINARY) . ' bin/doctor');
ok($status === 0, 'the real site is healthy');
contains($out, 'doctor: ok', 'and says so');
contains($out, 'AUTH_DEV_BYPASS=1', 'while warning about the dev bypass rather than passing it in silence');

[$quietStatus, $quietOut] = sh(escapeshellarg(PHP_BINARY) . ' bin/doctor --quiet');
ok($quietStatus === 0 && trim($quietOut) === '', '--quiet says nothing, for cron');

// composer.json is checked at install time under the CLI php; doctor is checked
// at run time under whatever php-fpm actually is. They only both work if they
// name the same set, so the two lists must not drift.
$declared = array_values(array_filter(
    array_keys((array) json_decode((string) file_get_contents($root . '/composer.json'), true)['require']),
    static fn (string $p): bool => str_starts_with($p, 'ext-')
));
sort($declared);
preg_match("/foreach \(\[([^\]]+)\] as \\\$ext\)/", (string) file_get_contents($root . '/bin/doctor'), $m);
$checked = array_map(
    static fn (string $e): string => 'ext-' . trim($e, " '"),
    explode(',', $m[1] ?? '')
);
sort($checked);
ok($declared === $checked,
    'doctor checks exactly the extensions composer.json requires: ' . implode(' ', $declared));
ok(in_array('ext-exif', $declared, true),
    'ext-exif among them — normalize() strips EXIF to remove GPS, so orientation must be baked in first');

section('bin/doctor refuses every way a site can be quietly broken');

// A throwaway content tree per case, so nothing here can touch real content.
$sandbox = $root . '/var/cache/doctor-' . bin2hex(random_bytes(4));
register_shutdown_function(static function () use ($sandbox): void {
    exec('rm -rf ' . escapeshellarg($sandbox));
});

$roles = $sandbox . '/roles.yml';

/**
 * Write a content tree, run doctor against it, hand back its output.
 *
 * @param array<string, string> $files path under content/ => contents
 */
$doctor = static function (array $files, array $env = []) use ($sandbox, $roles): array {
    exec('rm -rf ' . escapeshellarg($sandbox));
    mkdir($sandbox . '/content/pages/el', 0775, true);
    mkdir($sandbox . '/content/uploads', 0775, true);
    file_put_contents($roles, "- { email: a@b.gr, role: admin }\n");

    foreach ($files as $path => $body) {
        @mkdir(dirname($sandbox . '/content/' . $path), 0775, true);
        file_put_contents($sandbox . '/content/' . $path, $body);
    }

    // $env first: `+` keeps the left operand, so an override has to be there.
    return sh(escapeshellarg(PHP_BINARY) . ' bin/doctor', $env + [
        'CONTENT_PATH' => $sandbox . '/content',
        'ROLES_FILE'   => $roles,
    ]);
};

$goodPage = static fn (string $slug, string $title = 'T'): string => Yaml::dump([
    'title'  => $title,
    'slug'   => $slug,
    'blocks' => [['id' => 'hero', 'type' => 'hero', 'fields' => []]],
], 6, 2);

// The sandbox itself must be healthy, or every case below proves nothing.
[$s, $o] = $doctor(['pages/el/home.yml' => $goodPage('/')]);
ok($s === 0, 'the sandbox baseline is healthy: ' . trim($o));

foreach ([
    'malformed YAML' => [
        ['pages/el/home.yml' => "title: [unclosed\n"],
        'not valid YAML',
    ],
    'a page that is not a mapping' => [
        ['pages/el/home.yml' => "- just\n- a list\n"],
        'is not a mapping',
    ],
    'a missing slug' => [
        ['pages/el/home.yml' => "title: T\nblocks: []\n"],
        'slug must start with',
    ],
    'a slug without a leading slash' => [
        ['pages/el/home.yml' => "title: T\nslug: home\nblocks: []\n"],
        'slug must start with',
    ],
    'two pages sharing a slug' => [
        ['pages/el/home.yml' => $goodPage('/'), 'pages/el/other.yml' => $goodPage('/')],
        'share the slug',
    ],
    'a duplicate block id' => [
        ['pages/el/home.yml' => Yaml::dump(['title' => 'T', 'slug' => '/', 'blocks' => [
            ['id' => 'a', 'type' => 'hero', 'fields' => []],
            ['id' => 'a', 'type' => 'hero', 'fields' => []],
        ]], 6, 2)],
        'duplicate block id',
    ],
    'a block with no id' => [
        ['pages/el/home.yml' => Yaml::dump(['title' => 'T', 'slug' => '/', 'blocks' => [
            ['type' => 'hero', 'fields' => []],
        ]], 6, 2)],
        'has no id',
    ],
    'a block whose component does not exist' => [
        ['pages/el/home.yml' => Yaml::dump(['title' => 'T', 'slug' => '/', 'blocks' => [
            ['id' => 'a', 'type' => 'no_such_component', 'fields' => []],
        ]], 6, 2)],
        'which has no component',
    ],
    'a page left outside the locale directory' => [
        ['pages/el/home.yml' => $goodPage('/'), 'pages/orphan.yml' => $goodPage('/orphan')],
        'outside a locale directory',
    ],
    'a nav: that is not a mapping' => [
        ['pages/el/home.yml' => "title: T\nslug: /\nnav: yes\nblocks: []\n"],
        'nav: must be a mapping',
    ],
    // Read as $data['seo']['noindex'] on the sitemap path and as a value map by
    // the panel; the wrong shape quietly gives both of them nothing.
    'a seo: that is not a mapping' => [
        ['pages/el/home.yml' => "title: T\nslug: /\nseo: yes\nblocks: []\n"],
        'seo: must be a mapping',
    ],
    'a seo: that is a list' => [
        ['pages/el/home.yml' => "title: T\nslug: /\nseo:\n  - noindex\nblocks: []\n"],
        'seo: must be a mapping',
    ],
    'redirects.yml that is not a mapping' => [
        ['pages/el/home.yml' => $goodPage('/'), 'redirects.yml' => "- /a\n- /b\n"],
        'must be a mapping',
    ],
    'a redirect to a page id that does not exist' => [
        ['pages/el/home.yml' => $goodPage('/'), 'redirects.yml' => "/old: no-such-page\n"],
        'which does not exist',
    ],
    'a redirect that lands on nothing' => [
        ['pages/el/home.yml' => $goodPage('/'), 'redirects.yml' => "/old: /nowhere\n"],
        'lands on nothing',
    ],
    'a redirect pointing at itself' => [
        ['pages/el/home.yml' => $goodPage('/'), 'redirects.yml' => "/loop: /loop\n"],
        'points at itself',
    ],
    'a redirect chain' => [
        [
            'pages/el/home.yml' => $goodPage('/'),
            'redirects.yml' => "/a: /b\n/b: /\n",
        ],
        'chains into another redirect',
    ],
    'a redirect shadowing a live page' => [
        ['pages/el/home.yml' => $goodPage('/'), 'pages/el/x.yml' => $goodPage('/live'),
            'redirects.yml' => "/live: /\n"],
        'shadows a live page',
    ],
] as $why => [$files, $expected]) {
    [$s, $o] = $doctor($files);
    ok($s === 1, 'refuses ' . $why);
    contains($o, $expected, '...and says why (' . $why . ')');
}

section('bin/doctor refuses a broken component, and an unsafe production box');

// The component cases need a real component directory, so build one and take
// it away again whatever happens.
$tmpType = '_doctor';
$tmpDir = $root . '/components/' . $tmpType;
register_shutdown_function(static function () use ($tmpDir): void {
    array_map('unlink', glob($tmpDir . '/*') ?: []);
    @rmdir($tmpDir);
});

$component = static function (string $schema, ?string $template = '{# ok #}') use ($tmpDir, $tmpType, $doctor, $goodPage): array {
    array_map('unlink', glob($tmpDir . '/*') ?: []);
    @mkdir($tmpDir, 0775, true);
    file_put_contents($tmpDir . '/schema.yml', $schema);
    if ($template !== null) {
        file_put_contents($tmpDir . '/' . $tmpType . '.twig', $template);
    }

    return $doctor(['pages/el/home.yml' => $goodPage('/')]);
};

foreach ([
    'a component with no template' => [
        "label: X\nfields: {}\n", null, 'has no ' . $tmpType . '.twig',
    ],
    'a template that does not compile' => [
        "label: X\nfields: {}\n", '{{ no_such_twig_function() }}', 'does not compile',
    ],
    'a schema that is not valid YAML' => [
        "label: [unclosed\n", '{# ok #}', 'not valid YAML',
    ],
    'an unknown field type' => [
        "label: X\nfields:\n  a:\n    type: telepathy\n", '{# ok #}', 'unknown type "telepathy"',
    ],
    'a misspelled editable value' => [
        "label: X\nfields:\n  a:\n    type: text\n    editable: yes-please\n", '{# ok #}', 'is not one of true/false/admin',
    ],
    'a select with no options' => [
        "label: X\nfields:\n  a:\n    type: select\n", '{# ok #}', 'select with no options',
    ],
    'a list with no sub-schema' => [
        "label: X\nfields:\n  a:\n    type: list\n    max: 5\n", '{# ok #}', 'needs a `fields` sub-schema',
    ],
] as $why => [$schema, $template, $expected]) {
    [$s, $o] = $component($schema, $template);
    ok($s === 1, 'refuses ' . $why);
    contains($o, $expected, '...and says why (' . $why . ')');
}

array_map('unlink', glob($tmpDir . '/*') ?: []);
@rmdir($tmpDir);

// Every absolute URL the site publishes — sitemap <loc>, og:image, the
// Sitemap: line — is built from site.base_url. A production box still carrying
// the development default submits a sitemap full of localhost, and the only
// symptom is months of nothing being indexed.
[$s, $o] = $doctor(['pages/el/home.yml' => $goodPage('/')], ['SITE_BASE_URL' => 'http://localhost:8080']);
ok($s === 0, 'a localhost base_url is only a warning off production');
contains($o, 'sitemap.xml and og:image URLs are built from it', '...but it does say so');

[$s, $o] = $doctor(['pages/el/home.yml' => $goodPage('/')], [
    'SITE_BASE_URL'   => 'http://localhost:8080',
    'APP_ENV'         => 'prod',
    'AUTH_MODE'       => 'cf_access',
    'AUTH_DEV_BYPASS' => '0',
    'CF_ACCESS_AUD'   => str_repeat('a', 64),
]);
ok($s === 1, 'while in production it is a refusal');
contains($o, 'site.base_url is http://localhost:8080', '...naming the value that would ship');

[$s, $o] = $doctor(['pages/el/home.yml' => $goodPage('/')], ['SITE_BASE_URL' => 'https://pelatis.gr']);
missing($o, 'base_url', 'a real domain says nothing at all');

// A missing roles file denies every address, so a box in that state serves a
// panel nobody can get into — and says nothing about why.
[$s, $o] = $doctor(['pages/el/home.yml' => $goodPage('/')], ['ROLES_FILE' => $sandbox . '/nope.yml']);
ok($s === 1, 'refuses a missing roles file');
contains($o, 'every address would be refused', '...naming the consequence rather than the filename alone');

// APP_ENV=prod with an unsafe auth config: config.php itself refuses to load,
// and doctor must fail with it rather than passing a box that cannot serve.
[$s, $o] = $doctor(['pages/el/home.yml' => $goodPage('/')], [
    'APP_ENV'   => 'prod',
    'AUTH_MODE' => 'none',
]);
ok($s === 1, 'refuses a production box whose own boot guard would refuse to start');
contains($o, 'refuses to load', '...and reports it as a config failure, not a page problem');

// ── Release switching and rollback ──────────────────────────────────────────

section('Every script is at least syntactically a script');

foreach (['deploy.sh', 'rollback.sh', 'release.sh', 'site-env.sh', 'backup', 'restore-drill', 'new-site'] as $script) {
    ok(is_executable($root . '/bin/' . $script), 'bin/' . $script . ' is executable');
    [$s] = sh('bash -n ' . escapeshellarg('bin/' . $script));
    ok($s === 0, '...and parses');
}
ok(is_executable($root . '/bin/doctor'), 'bin/doctor is executable');

$siteEnvFixture = $root . '/var/cache/site-env-' . bin2hex(random_bytes(4));
file_put_contents($siteEnvFixture, "CONTENT_PATH=/from/shared/env\nCF_ZONE_ID=zone-from-env\n");
[$s, $out] = sh('bash -c ' . escapeshellarg(
    '. bin/site-env.sh; site_env_load ' . escapeshellarg($siteEnvFixture)
    . ' 1; printf "%s|%s" "$CONTENT_PATH" "$CF_ZONE_ID"'
));
ok($s === 0 && $out === '/from/shared/env|zone-from-env',
    'the operational scripts load paths and secrets from the same shared environment file as PHP');
unlink($siteEnvFixture);

section('current moves atomically, and a rollback moves it back');

$deployRoot = $root . '/var/cache/release-' . bin2hex(random_bytes(4));
register_shutdown_function(static function () use ($deployRoot): void {
    exec('rm -rf ' . escapeshellarg($deployRoot));
});
mkdir($deployRoot . '/releases/r1', 0775, true);
mkdir($deployRoot . '/releases/r2', 0775, true);
mkdir($deployRoot . '/shared/content', 0775, true);
file_put_contents($deployRoot . '/releases/r1/VERSION', "r1\n");
file_put_contents($deployRoot . '/releases/r2/VERSION', "r2\n");
file_put_contents($deployRoot . '/shared/content/client.txt', "written by the client\n");

/** Call one function from bin/release.sh against the sandbox. */
$release = static fn (string $call): array
    => sh('bash -c ' . escapeshellarg('. bin/release.sh; ' . $call . ' ' . escapeshellarg($deployRoot)));

[$s] = sh('bash -c ' . escapeshellarg(
    '. bin/release.sh; release_switch ' . escapeshellarg($deployRoot) . ' ' . escapeshellarg($deployRoot . '/releases/r1')
));
ok($s === 0, 'switching to r1 succeeds');
ok(readlink($deployRoot . '/current') === $deployRoot . '/releases/r1', 'and current points at it');

[$s] = sh('bash -c ' . escapeshellarg(
    '. bin/release.sh; release_switch ' . escapeshellarg($deployRoot) . ' ' . escapeshellarg($deployRoot . '/releases/r2')
));
ok(readlink($deployRoot . '/current') === $deployRoot . '/releases/r2', 'switching again replaces the symlink rather than stacking');
clearstatcache(true);
ok(trim((string) file_get_contents($deployRoot . '/current/VERSION')) === 'r2', 'and the new code is what is served');

// The failure this shape exists to prevent: rm-then-symlink leaves a window in
// which `current` does not exist, which on a live site is a 500 for real
// visitors. mv -T over the top has no such window.
contains((string) file_get_contents($root . '/bin/release.sh'), 'mv -Tf',
    'the switch is a rename over the top, never an unlink followed by a symlink');
missing((string) file_get_contents($root . '/bin/release.sh'), 'rm -f "$root/current"',
    'so there is never a moment with no current release');

[$s, $out] = $release('release_previous');
ok(trim($out) === $deployRoot . '/releases/r1', 'the rollback target is the previous release: ' . trim($out));

[$s] = sh('bash -c ' . escapeshellarg(
    '. bin/release.sh; release_switch ' . escapeshellarg($deployRoot) . ' ' . escapeshellarg(trim($out))
));
// PHP caches resolved symlinks for 120s. A real request is a fresh process and
// never sees a stale `current`; this one process reads it repeatedly.
$version = static function () use ($deployRoot): string {
    clearstatcache(true);

    return trim((string) file_get_contents($deployRoot . '/current/VERSION'));
};
ok($version() === 'r1', 'a rollback puts r1 back');
ok(is_file($deployRoot . '/shared/content/client.txt'), 'and content is untouched by any of it');

// A release that is switched away from must survive, or the next rollback has
// nowhere to go.
[$s] = $release('release_prune');
ok(is_dir($deployRoot . '/releases/r1') && is_dir($deployRoot . '/releases/r2'),
    'pruning keeps the last few releases, current included');

$fail = sh('bash -c ' . escapeshellarg('. bin/release.sh; release_switch ' . escapeshellarg($deployRoot) . ' /no/such/release'));
ok($fail[0] !== 0, 'switching to a release that does not exist fails');
ok($version() === 'r1', 'and leaves current where it was');

section('A failed deploy step never reaches the switch');

// Cheapest honest check of the ordering: the switch must appear after every
// step that can fail, in the file. An out-of-order edit here is a deploy that
// points a live site at a release its own tests rejected.
$deployText = (string) file_get_contents($root . '/bin/deploy.sh');
$switchAt = strpos($deployText, 'release_switch "$root" "$release"');
foreach ([
    'composer install' => strpos($deployText, '"$composer" install'),
    'composer audit'   => strpos($deployText, '"$composer" audit'),
    'the test suite'   => strpos($deployText, 'tests/run.sh'),
    'bin/doctor'       => strpos($deployText, 'bin/doctor'),
    'the pre-switch smoke test' => strpos($deployText, 'smoke_pid'),
] as $step => $at) {
    ok($at !== false && $at < $switchAt, $step . ' runs before current moves');
}
contains($deployText, 'release_switch "$root" "$previous"', 'and a failed post-switch smoke test switches back');
contains($deployText, 'tests/run.sh --portable',
    'deploy runs all test files without assuming a DDEV router exists on the VPS');
ok(strpos($deployText, 'site_env_load "$env_file" 1') > strpos($deployText, 'tests/run.sh --portable'),
    'the live environment is loaded only after isolated tests, so tests cannot mutate shared content');
contains($deployText, 'ENV_FILE="$env_file"', 'doctor and the release smoke test receive the shared environment');

$nginx = (string) file_get_contents($root . '/nginx.conf.example');
contains($nginx, 'root /var/www/pelatis/current/public;',
    'the production vhost serves the atomic current release rather than a stale public directory');

// Everything, not by tag: a template or CSS change has no page id, so tag
// purging would leave a year of stale HTML on every page it touched.
contains($deployText, 'purge_everything', 'a deploy purges the whole edge cache');
ok(strpos($deployText, 'purge_everything') > $switchAt, 'after the switch, so the edge refills from the new release');
contains((string) file_get_contents($root . '/bin/rollback.sh'), 'purge_everything', 'and so does a rollback');

// ── Content backup ──────────────────────────────────────────────────────────

section('The backup commits and pushes content, images included');

$backupRoot = $root . '/var/cache/backup-' . bin2hex(random_bytes(4));
register_shutdown_function(static function () use ($backupRoot): void {
    exec('rm -rf ' . escapeshellarg($backupRoot));
});
mkdir($backupRoot . '/var/locks', 0775, true);

$git = static fn (string $dir, string $args): array
    => sh('git -C ' . escapeshellarg($dir) . ' ' . $args);

// A bare repository standing in for the private content remote.
sh('git init --quiet --bare ' . escapeshellarg($backupRoot . '/remote.git'));
sh('git clone --quiet ' . escapeshellarg($backupRoot . '/remote.git') . ' ' . escapeshellarg($backupRoot . '/content'));

mkdir($backupRoot . '/content/pages/el', 0775, true);
mkdir($backupRoot . '/content/uploads/2026/08', 0775, true);
file_put_contents($backupRoot . '/content/pages/el/home.yml', Yaml::dump([
    'title'  => 'Αρχική',
    'slug'   => '/',
    'blocks' => [['id' => 'hero', 'type' => 'hero', 'fields' => [
        'image' => ['src' => '/uploads/2026/08/photo.png', 'alt' => 'Μια εικόνα', 'width' => 4, 'height' => 4],
    ]]],
], 6, 2));

$im = imagecreatetruecolor(4, 4);
imagepng($im, $backupRoot . '/content/uploads/2026/08/photo.png');
imagedestroy($im);

$backupEnv = [
    'CONTENT_PATH' => $backupRoot . '/content',
    'VAR_PATH'     => $backupRoot . '/var',
];

[$s, $out] = sh('bin/backup', $backupEnv);
ok($s === 0, 'the backup runs clean: ' . trim($out));
contains($out, 'pushed', 'and pushes');

[, $log] = $git($backupRoot . '/remote.git', 'log --oneline');
ok(trim($log) !== '', 'the remote has the commit');

[, $tracked] = $git($backupRoot . '/content', 'ls-files');
contains($tracked, 'pages/el/home.yml', 'pages are tracked');
contains($tracked, 'uploads/2026/08/photo.png', 'and so are uploads — this is the half that used to go missing');

// Nothing to do is not a failure, and must not spam an alert every hour.
[$s, $out] = sh('bin/backup', $backupEnv);
ok($s === 0, 'a second run with no changes still exits 0');
contains($out, 'nothing to commit', 'and says so rather than committing an empty change');

// A newly uploaded, previously untracked image must reach the clone.
$im = imagecreatetruecolor(8, 8);
imagepng($im, $backupRoot . '/content/uploads/2026/08/brand-new.png');
imagedestroy($im);

[$s] = sh('bin/backup', $backupEnv);
ok($s === 0, 'a run after a new upload succeeds');

// A push that fails is the whole failure mode: the commit is local, so the
// off-box recovery point has silently stopped moving. It must be loud.
sh('git -C ' . escapeshellarg($backupRoot . '/content') . ' remote set-url origin /no/such/remote.git');
file_put_contents($backupRoot . '/content/pages/el/home.yml',
    file_get_contents($backupRoot . '/content/pages/el/home.yml') . "\n# touched\n");

[$s, $out] = sh('bin/backup', ['BACKUP_ALERT' => 'echo ALERTED:'] + $backupEnv);
ok($s === 1, 'a failed push fails the job');
contains($out, 'ALERTED:', 'and calls the monitored alert hook');
contains($out, 'off-box recovery point has not moved', '...saying what it actually means');

// That failed run already committed locally. With no new file change, the next
// run takes the clean-index branch — it must keep failing until the pending
// commit actually reaches the remote, not turn green just because git diff is clean.
[$s, $out] = sh('bin/backup', ['BACKUP_ALERT' => 'echo ALERTED:'] + $backupEnv);
ok($s === 1, 'a clean-index retry still fails while its pending commit cannot be pushed');
contains($out, 'pending local commit', 'and names the recovery point that is still only local');
contains($out, 'ALERTED:', 'the retry remains monitored rather than silently recovering');

[$s, $out] = sh('bin/backup', ['CONTENT_PATH' => $backupRoot . '/var', 'VAR_PATH' => $backupRoot . '/var']);
ok($s === 1, 'and a content directory that is not a git repository at all is refused');
contains($out, 'not a git repository', '...rather than silently backing up nothing');

// Put the remote back: the restore drill below needs a working one, and the
// commit made above is still sitting local until it can be pushed.
sh('git -C ' . escapeshellarg($backupRoot . '/content')
    . ' remote set-url origin ' . escapeshellarg($backupRoot . '/remote.git'));
[$s] = sh('bin/backup', $backupEnv);
ok($s === 0, 'and once the remote is reachable again the pending commit is pushed');

section('And the restore drill turns that remote back into a site');

[$s, $out] = sh('bin/restore-drill', [
    'BACKUP_REMOTE' => $backupRoot . '/remote.git',
    'RELEASE_PATH'  => $root,
]);
ok($s === 0, 'the drill passes: ' . trim($out));
contains($out, '2 uploads', 'the new image is in the clone, not just the old one');
contains($out, 'doctor clean', 'and the restored content passes the same health check a deploy runs');

// The drill has to actually fail when the backup is incomplete, or it is
// theatre. Remove an image the pages reference and it must notice.
sh('git -C ' . escapeshellarg($backupRoot . '/content') . ' rm --quiet uploads/2026/08/photo.png');
sh('git -C ' . escapeshellarg($backupRoot . '/content')
    . ' -c user.name=t -c user.email=t@t commit --quiet -m "lose an image"');
sh('git -C ' . escapeshellarg($backupRoot . '/content') . ' push --quiet');

[$s, $out] = sh('bin/restore-drill', [
    'BACKUP_REMOTE' => $backupRoot . '/remote.git',
    'RELEASE_PATH'  => $root,
]);
ok($s === 1, 'a page whose image is missing from the backup fails the drill');
contains($out, 'not in the backup', '...naming the image rather than passing quietly');

// And the alert hook fires, because a failure nobody is told about is a
// failure that shows up as a client phoning six months later.
[$s, $out] = sh('bin/restore-drill', [
    'BACKUP_REMOTE' => $backupRoot . '/remote.git',
    'RELEASE_PATH'  => $root,
    'DRILL_ALERT'   => 'echo ALERTED:',
]);
contains($out, 'ALERTED:', 'and the monitored alert hook is called');

section('bin/new-site scaffolds a site that passes its own doctor');

$scaffold = $root . '/var/cache/newsite-' . bin2hex(random_bytes(4));
register_shutdown_function(static function () use ($scaffold): void {
    exec('rm -rf ' . escapeshellarg($scaffold));
});

[$s, $out] = sh('bin/new-site ' . escapeshellarg($scaffold) . ' ' . escapeshellarg('Πελάτης ΑΕ') . ' pelatis.gr');
ok($s === 0, 'the scaffold runs: ' . trim(explode("\n", $out)[0]));
ok(is_file($scaffold . '/content/pages/el/home.yml'), 'with a page in the locale directory');
ok(is_dir($scaffold . '/content/uploads'), 'and an uploads directory inside content/');
ok(!is_dir($scaffold . '/content/.revisions') || glob($scaffold . '/content/.revisions/*') === [],
    'and no revision history from the site it was copied from');
ok(glob($scaffold . '/content/uploads/*') === [], 'nor any of its images');
contains((string) file_get_contents($scaffold . '/config.php'), 'Πελάτης ΑΕ', 'the site name is substituted');
contains((string) file_get_contents($scaffold . '/.env.example'), 'SITE_NOINDEX=1',
    'and a new site starts noindexed, because nobody has approved the copy yet');
contains((string) file_get_contents($scaffold . '/nginx.conf.example'), 'pelatis.gr', 'the nginx example names the domain');
contains((string) file_get_contents($scaffold . '/nginx.conf.example'), '/var/www/pelatis.gr/current/public',
    'and its docroot uses the same deploy root as the generated environment');
contains($out, 'composer install', 'and the next steps say so, since there is no vendor/ yet');

// A real new site runs `composer install` here. Borrowing this one's vendor/ is
// the same thing minus the download, and keeps the suite off the network.
symlink($root . '/vendor', $scaffold . '/vendor');

[$s, $out] = sh(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($scaffold . '/bin/doctor'), [
    'CONTENT_PATH' => $scaffold . '/content',
    'VAR_PATH'     => $scaffold . '/var',
    'ROLES_FILE'   => $scaffold . '/config/roles.yml',
]);
ok($s === 0, 'and the scaffolded site is healthy from the first minute: ' . trim($out));

summary();
