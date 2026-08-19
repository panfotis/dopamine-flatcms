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
ok(substr_count($none, '--wrap:1120px') === 1, 'while the engine theme globals are on every page');

// The same Cms just rendered a hero page; a leaked collector is exactly the
// bug this asserts against.
missing($none, '.video-facade{', 'a second render on the same Cms does not inherit the first render\'s components');

$bare = $cms->renderPage(['id' => 'a4', 'title' => 'A', 'slug' => '/a4', 'layout' => 'bare',
    'blocks' => [$hero('h1')]]);
contains($bare, '--wrap:1120px', 'layout: bare carries the theme globals too');
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
mkdir($siteDir . '/components/hero', 0775, true);
mkdir($siteDir . '/components/plain', 0775, true);
register_shutdown_function(static function () use ($siteDir): void {
    exec('rm -rf ' . escapeshellarg($siteDir));
});

file_put_contents($siteDir . '/assets/css/site.css', ".sitemark{color:red}\n");
file_put_contents($siteDir . '/theme.yml', Yaml::dump([
    'css' => [
        'assets/css/site.css',
        ['url' => 'https://cdn.example.com/frame.css', 'integrity' => 'sha384-AAA'],
    ],
    'js' => [
        ['url' => 'https://cdn.example.com/lib.js', 'integrity' => 'sha384-BBB'],
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
$cfg['paths']['theme'] = [$siteDir, $cfg['paths']['theme']];
$layered = new Cms($cfg);

$html = $layered->renderPage(['id' => 'b1', 'title' => 'B', 'slug' => '/b1', 'blocks' => [
    $hero('h1'),
    ['id' => 'p1', 'type' => 'plain', 'fields' => ['text' => 'γειά']],
]]);

contains($html, '<h1 class="site-hero">', 'the site layer\'s hero template renders');
contains($html, '.site-hero{font-size:9rem}', 'with the site layer\'s hero CSS');
missing($html, '.hero{', 'and none of the engine hero\'s CSS — the folder wins whole, no half-merge');
contains($html, '<p class="plain">γειά</p>', 'a component with no .css beside it renders fine');

section('Emission order: externals, engine globals, components, site globals');

$linkPos   = strpos($html, '<link rel="stylesheet"');
$enginePos = strpos($html, '--wrap:1120px');
$heroPos   = strpos($html, '.site-hero{');
$sitePos   = strpos($html, '.sitemark{');
ok($linkPos !== false && $enginePos !== false && $heroPos !== false && $sitePos !== false,
    'all four tiers are present');
ok($linkPos < $enginePos && $enginePos < $heroPos && $heroPos < $sitePos,
    'and in tier order — external < engine globals < component < site globals');
ok(substr_count($html, '<link rel="preconnect" href="https://cdn.example.com"') === 1,
    'one preconnect per external host, not one per asset');
contains($html, 'integrity="sha384-AAA" crossorigin="anonymous"',
    'an SRI entry gets crossorigin added — without it the browser cannot hash the response');
missing($html, 'sha384-AAA"></style>', 'external entries are tags, never inlined');
contains($html, '<script src="https://cdn.example.com/lib.js" defer integrity="sha384-BBB"',
    'external JS is deferred, which is what lets wrapped local code rely on it');
ok(substr_count($html, '<style>') >= 3, 'one <style> per contributing file, not one concatenated block');

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
$evilCfg['paths']['theme'] = [$evilDir, $evilCfg['paths']['theme']];
$evil = new Cms($evilCfg);
$html = $evil->renderPage(['id' => 'c1', 'title' => 'C', 'slug' => '/c1', 'blocks' => []]);
missing($html, 'TOPSECRET-DO-NOT-INLINE', 'a ../ entry is rejected, not inlined into a public page');

// ── Error pages ─────────────────────────────────────────────────────────────

section('The 404 is branded; the 500 depends on nothing');

$notFound = $cms->renderTemplate('404.twig', ['slug' => '/nope', 'locale' => 'el', 'home_url' => '/']);
contains($notFound, '--wrap:1120px', 'the 404 carries the theme globals — renderTemplate gives it the pipeline');

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
missing($fiveHundred, '--wrap:1120px', 'carrying no pipeline output — it is deliberately self-contained');

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

summary();
