<?php
/**
 * The asset pipeline: component CSS/JS collected per render, deduped, emitted
 * once, in an order a site can rely on to override without forking.
 */

declare(strict_types=1);

use Dopamine\FlatCms\Cms;
use Symfony\Component\Yaml\Yaml;

require __DIR__ . '/lib.php';

$cms = cms();

// ── Collection and dedup ─────────────────────────────────────────────────────

section('Component CSS loads once per type, and only when the type renders');

$hero = static fn (string $id): array => [
    'id' => $id, 'type' => 'hero',
    'fields' => ['heading' => 'Δοκιμή ' . $id],
];

$one = $cms->renderPage(['id' => 'a1', 'title' => 'A', 'slug' => '/a1', 'blocks' => [$hero('h1')]]);
ok(substr_count($one, '.hero{') === 1, 'a page with one hero inlines hero.css exactly once');

$two = $cms->renderPage(['id' => 'a2', 'title' => 'A', 'slug' => '/a2', 'blocks' => [$hero('h1'), $hero('h2')]]);
ok(substr_count($two, '.hero{') === 1, 'a page with two heroes still inlines it exactly once');

$none = $cms->renderPage(['id' => 'a3', 'title' => 'A', 'slug' => '/a3', 'blocks' => []]);
missing($none, '.hero{', 'a page with no hero does not carry its CSS at all');
// The engine declares no globals: its placeholder styling rides with the
// welcome components, so a built site never ships a byte of it.
missing($none, '--wrap:1120px', 'the engine placeholder CSS is not on an ordinary page');
ok(substr_count($none, '.fixmark{color:green}') === 1, 'while a lower layer\'s manifest globals are on every page');

// The placeholder lives in the skeleton now, copied into a new site by
// create-project — never inherited from the engine. Its base styles still
// travel with the component, so only pages that render it carry a byte.
$skelCfg = test_config();
$skelCfg['paths']['theme'] = [dirname(__DIR__) . '/skeleton/theme'];
$welcome = (new Cms($skelCfg))->renderPage(['id' => 'a3w', 'title' => 'A', 'slug' => '/a3w', 'blocks' => [
    ['id' => 'w1', 'type' => 'demo_header', 'fields' => []],
]]);
ok(substr_count($welcome, '--wrap:1120px') === 1, 'the skeleton placeholder base styles arrive with demo_header, once');

// The same Cms just rendered a hero page; a leaked collector is exactly the
// bug this asserts against.
missing($none, '.video-facade{', 'a second render on the same Cms does not inherit the first render\'s components');

$bare = $cms->renderPage(['id' => 'a4', 'title' => 'A', 'slug' => '/a4', 'layout' => 'bare',
    'blocks' => [$hero('h1')]]);
contains($bare, '.fixmark{color:green}', 'layout: bare carries the theme globals too');
contains($bare, '.hero{', 'and its components\' CSS');

section('Local JS is wrapped, per file, after the DOM exists');

$video = $cms->renderPage(['id' => 'a5', 'title' => 'A', 'slug' => '/a5', 'blocks' => [
    ['id' => 'v1', 'type' => 'video', 'fields' => ['embed' => ['provider' => 'youtube', 'id' => 'dQw4w9WgXcQ']]],
]]);
ok(substr_count($video, "document.addEventListener('DOMContentLoaded'") === 1,
    'video.js is emitted once, wrapped so deferred externals have run first');
contains($video, 'querySelectorAll(\'.video-facade\')', 'and initialises every facade in one pass');

// ── A site theme layered over the engine ────────────────────────────────────

section('A site theme layer: globals cascade, component folders win whole');

$siteDir = dirname(__DIR__) . '/var/cache/test-site-theme-' . bin2hex(random_bytes(4));
mkdir($siteDir . '/assets/css', 0775, true);
mkdir($siteDir . '/assets/js', 0775, true);
mkdir($siteDir . '/components/hero', 0775, true);
mkdir($siteDir . '/components/plain', 0775, true);
register_shutdown_function(static function () use ($siteDir): void {
    exec('rm -rf ' . escapeshellarg($siteDir));
});

// Comment, loose whitespace, a two-space string and a calc(): what the
// minifier must strip and what it must not touch.
// The two comments carry one apostrophe each: read as string delimiters they
// would pair across the first comment's terminator and the rule between the
// comments would be swallowed with it. Regression for exactly that bug.
file_put_contents($siteDir . '/assets/css/site.css',
    "/* strip me — it's a comment */\n.sitemark{color:red}\n/* and it's another */\n"
    . ".q::before{ content: \"a  b\" ;\n  width: calc(100% - 2rem) }\n");
// fakelib stands in for a self-hosted GSAP: a UMD-style file that resolves its
// global via top-level `this`, which only works OUTSIDE the DOMContentLoaded
// wrapper — plus a "</script" in a string to prove escaping still applies.
file_put_contents($siteDir . '/assets/js/fakelib.js',
    "var FAKELIB_TOP = this;\nwindow.FakeLib = { closer: \"</script in a string\" };\n");
file_put_contents($siteDir . '/assets/js/site.js', "document.querySelectorAll('.sitemark'); // WRAPPED_MARKER\n");
file_put_contents($siteDir . '/theme.yml', Yaml::dump([
    'css' => [
        'assets/css/site.css',
        ['url' => 'https://cdn.example.com/frame.css', 'integrity' => 'sha384-AAA'],
    ],
    'js' => [
        ['url' => 'https://cdn.example.com/lib.js', 'integrity' => 'sha384-BBB'],
        ['file' => 'assets/js/fakelib.js', 'wrap' => false],
        // Both url and file: a manifest typo the pipeline must refuse whole.
        ['url' => 'https://cdn.example.com/broken.js', 'file' => 'assets/js/site.js'],
        'assets/js/site.js',
    ],
]));

// A complete override of the engine's hero — schema, template and CSS all from
// this folder, none from the engine's.
file_put_contents($siteDir . '/components/hero/schema.yml', "label: Site hero\nfields:\n  heading:\n    type: text\n");
file_put_contents($siteDir . '/components/hero/hero.twig', '<h1 class="site-hero">{{ fields.heading }}</h1>');
file_put_contents($siteDir . '/components/hero/hero.css', ".site-hero{font-size:9rem}\n");
// And a component with no CSS at all, which must simply render.
file_put_contents($siteDir . '/components/plain/schema.yml', "label: Plain\nfields:\n  text:\n    type: text\n");
file_put_contents($siteDir . '/components/plain/plain.twig', '<p class="plain">{{ fields.text }}</p>');

$cfg = require dirname(__DIR__) . '/config.php';
unset($cfg['paths']['public_assets']);   // this section asserts on INLINE emission
$cfg['paths']['theme'] = array_merge([$siteDir], (array) $cfg['paths']['theme']);
$layered = new Cms($cfg);

$html = $layered->renderPage(['id' => 'b1', 'title' => 'B', 'slug' => '/b1', 'blocks' => [
    $hero('h1'),
    ['id' => 'p1', 'type' => 'plain', 'fields' => ['text' => 'γειά']],
]]);

contains($html, '<h1 class="site-hero">', 'the site layer\'s hero template renders');
contains($html, '.site-hero{font-size:9rem}', 'with the site layer\'s hero CSS');
missing($html, '.hero{', 'and none of the engine hero\'s CSS — the folder wins whole, no half-merge');
contains($html, '<p class="plain">γειά</p>', 'a component with no .css beside it renders fine');

section('wrap: false — a local library executes at top level');

$libPos = strpos($html, 'var FAKELIB_TOP = this;');
ok($libPos !== false, 'a {file:, wrap: false} entry is emitted');
$libTagStart = strrpos(substr($html, 0, (int) $libPos), '<script');
$libTag = substr($html, (int) $libTagStart, strpos($html, '</script>', (int) $libPos) - (int) $libTagStart);
missing($libTag, 'DOMContentLoaded', 'in a plain <script> — top-level `this`, so a UMD global lands on window');
contains($libTag, '<\/script in a string', 'with the tag-breakout escape still applied to library code');

$wrapPos = strpos($html, 'WRAPPED_MARKER');
ok($wrapPos !== false && substr_count($html, 'WRAPPED_MARKER') === 1, 'a bare-string entry is emitted exactly once');
$wrapTagStart = strrpos(substr($html, 0, (int) $wrapPos), '<script');
$wrapTag = substr($html, (int) $wrapTagStart, strpos($html, '</script>', (int) $wrapPos) - (int) $wrapTagStart);
contains($wrapTag, 'DOMContentLoaded', 'and is still wrapped, exactly as before');
ok($libPos < $wrapPos, 'libraries are emitted before wrapped code');
missing($html, 'cdn.example.com/broken.js', 'a map entry carrying both url and file is refused whole');

section('Emission order: externals, lower-layer globals, components, site globals');

$linkPos   = strpos($html, '<link rel="stylesheet"');
$enginePos = strpos($html, '.fixmark{');
$heroPos   = strpos($html, '.site-hero{');
$sitePos   = strpos($html, '.sitemark{');
ok($linkPos !== false && $enginePos !== false && $heroPos !== false && $sitePos !== false,
    'all four tiers are present');
ok($linkPos < $enginePos && $enginePos < $heroPos && $heroPos < $sitePos,
    'and in tier order — external < lower-layer globals < component < site globals');
ok(substr_count($html, '<link rel="preconnect" href="https://cdn.example.com"') === 1,
    'one preconnect per external host, not one per asset');
contains($html, 'integrity="sha384-AAA" crossorigin="anonymous"',
    'an SRI entry gets crossorigin added — without it the browser cannot hash the response');
missing($html, 'sha384-AAA"></style>', 'external entries are tags, never inlined');
contains($html, '<script src="https://cdn.example.com/lib.js" defer integrity="sha384-BBB"',
    'external JS is deferred, which is what lets wrapped local code rely on it');
ok(substr_count($html, '<style>') >= 3, 'one <style> per contributing file, not one concatenated block');

section('Inlined CSS is minified; the file on disk stays the readable copy');

missing($html, 'strip me', 'comments are stripped');
contains($html, '.sitemark{color:red}.q::before{content: "a  b";width: calc(100% - 2rem)}',
    'whitespace collapses, while quoted strings and calc() operator spacing survive byte-for-byte');

section('A manifest cannot read outside its own theme root');

$secret = dirname($siteDir) . '/secret-' . bin2hex(random_bytes(4)) . '.txt';
file_put_contents($secret, 'TOPSECRET-DO-NOT-INLINE');
register_shutdown_function(static fn () => @unlink($secret));

$evilDir = dirname(__DIR__) . '/var/cache/test-evil-theme-' . bin2hex(random_bytes(4));
mkdir($evilDir, 0775, true);
register_shutdown_function(static function () use ($evilDir): void {
    exec('rm -rf ' . escapeshellarg($evilDir));
});
file_put_contents($evilDir . '/theme.yml', "css:\n  - ../" . basename($secret) . "\n");

$evilCfg = require dirname(__DIR__) . '/config.php';
unset($evilCfg['paths']['public_assets']);   // this section asserts on INLINE emission
$evilCfg['paths']['theme'] = array_merge([$evilDir], (array) $evilCfg['paths']['theme']);
$evil = new Cms($evilCfg);
$html = $evil->renderPage(['id' => 'c1', 'title' => 'C', 'slug' => '/c1', 'blocks' => []]);
missing($html, 'TOPSECRET-DO-NOT-INLINE', 'a ../ entry is rejected, not inlined into a public page');

// ── Error pages ─────────────────────────────────────────────────────────────

section('The 404 is branded; the 500 depends on nothing');

$notFound = $cms->renderTemplate('404.twig', ['slug' => '/nope', 'locale' => 'el', 'home_url' => '/']);
contains($notFound, '.fixmark{', 'the 404 carries the theme globals — renderTemplate gives it the pipeline');

file_put_contents($siteDir . '/404.twig', '<!DOCTYPE html><title>SITE-404</title>{{ theme_head() }}');
$layered2 = new Cms($cfg);
contains($layered2->renderTemplate('404.twig', []), 'SITE-404',
    'a site layer\'s 404.twig overrides the engine\'s like any other template');

// A corrupted manifest is one of the things that can *cause* a 500 — so the
// 500 page must render without the pipeline, and the page render must survive.
file_put_contents($evilDir . '/theme.yml', "css:\n  - [:::\n");
$broken = new Cms($evilCfg);
$html = $broken->renderPage(['id' => 'c2', 'title' => 'C', 'slug' => '/c2', 'blocks' => []]);
contains($html, '<main>', 'a page still renders with a corrupt theme.yml — fail soft, doctor is where it gets loud');
$fiveHundred = $broken->twig->render('500.twig', ['locale' => 'el', 'home_url' => '/']);
contains($fiveHundred, '500', 'and 500.twig renders with no Assets instance at all');
missing($fiveHundred, '.fixmark{', 'carrying no pipeline output — it is deliberately self-contained');

// ── The admin theme ─────────────────────────────────────────────────────────

section('The panel is a theme too');

$list = admin_get(['action' => 'list']);
contains((string) $list->getContent(), '.side', 'the panel styles arrive through the pipeline');
missing((string) $list->getContent(), 'const form = document.getElementById', 'but the list screen does not ship the editor');

$edit = admin_get(['action' => 'edit', 'page' => 'home']);
contains((string) $edit->getContent(), 'const form = document.getElementById',
    'the edit screen attaches editor.js via theme_attach');
contains((string) $edit->getContent(), "document.addEventListener('DOMContentLoaded'",
    'wrapped like every other local script');

// ── Bundle delivery ─────────────────────────────────────────────────────────
//
// Everything above this line runs with paths.public_assets unset, so it is all
// coverage of the inline path — which is production's fallback, not a legacy
// mode. From here on, bundles.

section('Bundles: content-addressed files, written on demand');

// Deliberately absent: on a fresh checkout the directory does not exist, and a
// pre-flight is_writable() gate here would pin the site to inline forever.
$bundleDir = dirname(__DIR__) . '/var/cache/test-bundles-' . bin2hex(random_bytes(4));
register_shutdown_function(static function () use ($bundleDir): void {
    exec('rm -rf ' . escapeshellarg($bundleDir));
});

$bundleCfg = test_config();
$bundleCfg['paths']['public_assets'] = $bundleDir;
$bundleCms = new Cms($bundleCfg);

$heroPage = static fn (string $id): array => [
    'id' => $id, 'title' => 'B', 'slug' => '/' . $id,
    'blocks' => [['id' => 'h', 'type' => 'hero', 'fields' => ['heading' => 'Δοκιμή']]],
];

$b1 = $bundleCms->renderPage($heroPage('b1'));
$urls = static function (string $html): array {
    preg_match_all('#/assets/(?:css|js)/[a-z]+-[0-9a-f]{12}\.(?:css|js)#', $html, $m);

    return $m[0];
};

ok(is_dir($bundleDir), 'the assets directory is created on first render, not pre-checked');
missing($b1, '<style>', 'a bundled page carries no inline <style> at all');
$b1Urls = $urls($b1);
ok($b1Urls !== [], 'it links bundles instead: ' . implode(' ', $b1Urls));

foreach ($b1Urls as $url) {
    ok(is_file($bundleDir . substr($url, strlen('/assets'))), 'the file behind ' . $url . ' exists on disk');
}

// Tier order is the cascade: components first, the site's globals last, which
// is what lets site.css override a component without forking it.
$pagePos = strpos($b1, '/assets/css/page-');
$sitePos = strpos($b1, '/assets/css/site-');
ok($pagePos !== false && $sitePos !== false && $pagePos < $sitePos,
    'the site CSS bundle is linked after the component bundle — site.css still wins');

$cssFile = $bundleDir . '/css/' . basename((string) preg_replace('#.*(site-[0-9a-f]{12}\.css).*#s', '$1', substr($b1, (int) $sitePos, 60)));
ok(is_file($cssFile) && !str_contains((string) file_get_contents($cssFile), "\n  "),
    'bundled CSS is minified, exactly as the inline path minifies it');

section('Bundles: the URL is the invalidation');

$b2 = $bundleCms->renderPage($heroPage('b2'));
ok($urls($b2) === $b1Urls, 'a second page with the same component sequence links the identical URLs');

$b3 = $bundleCms->renderPage(['id' => 'b3', 'title' => 'B', 'slug' => '/b3', 'blocks' => [
    ['id' => 'h', 'type' => 'hero', 'fields' => ['heading' => 'Δ']],
    ['id' => 'v', 'type' => 'video', 'fields' => ['embed' => ['provider' => 'youtube', 'id' => 'dQw4w9WgXcQ']]],
]]);
$pageCss = static fn (array $list): string => implode(' ', array_filter($list, static fn (string $u): bool => str_contains($u, '/css/page-')));
ok($pageCss($urls($b3)) !== $pageCss($b1Urls), 'a different component sequence gets a different page bundle');

// Change a byte of a global, and the URL moves — nothing is mutated in place,
// which is what makes immutable caching safe.
$siteCssPath = dirname(__DIR__) . '/tests/fixtures/theme/assets/css/fixture.css';
$originalCss = (string) file_get_contents($siteCssPath);
try {
    file_put_contents($siteCssPath, $originalCss . "\n.rehash{color:blue}\n");
    $after = $urls((new Cms($bundleCfg))->renderPage($heroPage('b4')));
    ok($pageCss($after) !== '' && $after !== $b1Urls, 'editing a stylesheet produces a new hash');
    foreach ($b1Urls as $old) {
        ok(is_file($bundleDir . substr($old, strlen('/assets'))),
            'and the previous bundle is left on disk for edge-cached HTML: ' . $old);
    }
} finally {
    file_put_contents($siteCssPath, $originalCss);
}

section('Bundles: script order and the ASI boundary');

$jsDir = dirname(__DIR__) . '/var/cache/test-bundle-js-' . bin2hex(random_bytes(4));
mkdir($jsDir . '/assets/js', 0775, true);
register_shutdown_function(static function () use ($jsDir): void {
    exec('rm -rf ' . escapeshellarg($jsDir));
});
// A library whose last statement omits its semicolon, followed by a file that
// opens with a paren: concatenated naively, ASI merges them into a call.
file_put_contents($jsDir . '/assets/js/lib-a.js', "var ASI_LEFT = 1\n");
file_put_contents($jsDir . '/assets/js/lib-b.js', "(function () { window.ASI_RIGHT = 1; })()\n");
file_put_contents($jsDir . '/theme.yml', Yaml::dump([
    'js' => [
        ['file' => 'assets/js/lib-a.js', 'wrap' => false],
        ['file' => 'assets/js/lib-b.js', 'wrap' => false],
        'assets/js/site.js',
    ],
]));
file_put_contents($jsDir . '/assets/js/site.js', "document.querySelector('body'); // WRAPPED\n");

$jsCfg = $bundleCfg;
$jsCfg['paths']['theme'] = array_merge([$jsDir], (array) $bundleCfg['paths']['theme']);
$jsHtml = (new Cms($jsCfg))->renderPage($heroPage('b5'));

preg_match_all('#<script src="(/assets/js/[a-z]+-[0-9a-f]{12}\.js)" defer></script>#', $jsHtml, $scripts, PREG_SET_ORDER);
ok(count($scripts) >= 2, 'local JS is emitted as bundle tags: ' . count($scripts));
ok(substr_count($jsHtml, '<script src="/assets/js/') === substr_count($jsHtml, '" defer></script>'),
    'every local bundle script carries defer — document order is execution order');
ok(str_contains($scripts[0][1] ?? '', '/lib-'), 'the library bundle is emitted first, before any wrapped code');

$libFile = $bundleDir . substr($scripts[0][1], strlen('/assets'));
$libBody = (string) file_get_contents($libFile);
contains($libBody, "1\n;\n(function", 'files are joined with an explicit ;\\n — ASI cannot merge two statements');
missing($libBody, 'DOMContentLoaded', 'and the library bundle is unwrapped, so its globals land on window');

$wrappedFile = $bundleDir . substr($scripts[count($scripts) - 1][1], strlen('/assets'));
contains((string) file_get_contents($wrappedFile), "document.addEventListener('DOMContentLoaded'",
    'while wrapped files keep their own listener inside the bundle');

section('Single root: the site globals still emit last — the cascade fix');

// One theme root is every real site now. Its theme.yml globals must land in
// the site tier (emitted after component CSS), or an equal-specificity
// override in site.css silently loses — the regression the $site = $i === 0
// fix repairs.
$single = cms()->renderPage($heroPage('cascade'));
$heroCssPos = strpos($single, '.hero{');
$globalsPos = strpos($single, '.fixmark{');
ok($heroCssPos !== false && $globalsPos !== false && $heroCssPos < $globalsPos,
    'a single-root site emits component CSS before its theme.yml globals — site.css wins the cascade');

section('Bundles: panel chrome inlines, the preview renders like the site');

$panelList = admin_get(['action' => 'list'], $bundleCfg);
contains((string) $panelList->getContent(), '.side', 'the panel styles are present with bundles configured');
missing((string) $panelList->getContent(), '/assets/css/', 'and still inline — the panel never depends on a writable public dir');

$_SESSION['csrf'] = 'the-real-token';
$preview = admin_post([
    'action' => 'preview',
    'csrf'   => 'the-real-token',
    'page'   => 'home',
    'blocks' => ['hero' => ['heading' => 'Προεπισκόπηση']],
], $bundleCfg);
ok($preview->getStatusCode() === 200, 'a preview renders with bundles configured');
ok($urls((string) $preview->getContent()) !== [],
    'and carries bundle links — the preview is a real site render, exercising what the visitor gets');

section('Bundles: an unwritable target falls back to inlining, never to nothing');

$blocked = dirname(__DIR__) . '/var/cache/test-bundle-blocked-' . bin2hex(random_bytes(4));
mkdir($blocked, 0555, true);
register_shutdown_function(static function () use ($blocked): void {
    @chmod($blocked, 0775);
    exec('rm -rf ' . escapeshellarg($blocked));
});
$blockedCfg = test_config();
$blockedCfg['paths']['public_assets'] = $blocked . '/nested';
$blockedHtml = (new Cms($blockedCfg))->renderPage($heroPage('b6'));
contains($blockedHtml, '<style>', 'an unwritable assets directory falls back to inline <style>');
contains($blockedHtml, '.hero{', 'with the real CSS on the page — styled, not broken');
ok($urls($blockedHtml) === [], 'and no bundle links are emitted');

summary();
