<?php

declare(strict_types=1);

require __DIR__ . '/lib.php';

$cms = cms();

section('Components load from disk');
$types = array_keys($cms->components->all());
sort($types);
ok($types === ['contact_cta', 'faq', 'hero', 'text_image'], 'four components discovered: ' . implode(', ', $types));
ok($cms->components->get('hero')['fields']['heading']['max'] === 70, 'hero.heading max parsed from schema.yml');
ok($cms->components->get('hero')['fields']['align']['editable'] === false, 'hero.align is marked non-editable');
ok(!array_key_exists('align', $cms->components->editableFields('hero')), 'non-editable field excluded from editable set');
ok($cms->components->get('hero')['fields']['align']['options'] === ['start' => 'Αριστερά', 'center' => 'Κέντρο'], 'select options normalised to a map');

section('Page rendering');
$page = $cms->content->findBySlug('/');
ok($page !== null, 'home page resolved from slug /');
$html = $cms->renderPage($page);
contains($html, '<title>Αρχική — Demo Πελάτη</title>', 'title rendered');
contains($html, 'Μικρά site, χωρίς βαρύ CMS', 'hero heading rendered');
contains($html, '<strong>schema.yml</strong>', 'richtext HTML survives rendering');
contains($html, 'hello@example.gr', 'contact component rendered');
ok(substr_count($html, '<section') === 5, 'all five blocks rendered as sections');

section('Every image renders through picture.twig');
// Mechanical, because "remember to use the partial" is not a mechanism. A
// component that hand-rolls an <img> is one that will ship without a
// width/height pair, without a WebP source, or with alt on the wrong element.
$bare = array_filter(
    glob(dirname(__DIR__) . '/components/*/*.twig') ?: [],
    static fn (string $f): bool => str_contains((string) file_get_contents($f), '<img')
);
ok($bare === [], 'no component template writes its own <img>: ' . implode(', ', array_map('basename', $bare)));

$contactPage = $cms->content->findBySlug('/epikoinonia');
$heroImage = $contactPage['blocks'][0]['fields']['image'];
$hero = $cms->renderPage($contactPage);
contains($hero, '<picture>', 'the rendered page uses <picture>');
contains($hero, '<source type="image/webp"', 'with a WebP source, since there is no format=auto to negotiate for us');
// Read off the page rather than hardcoded: this is demo content, and replacing
// the picture from the panel must not fail the suite.
contains($hero, sprintf('width="%d" height="%d"', $heroImage['width'], $heroImage['height']),
    "and the original's intrinsic dimensions, so the box is reserved before the bytes arrive");
contains($hero, 'loading="eager"', 'the hero is not lazy — lazy-loading the LCP image is the classic mistake');
contains($hero, 'fetchpriority="high"', 'and is fetched at high priority');
ok(substr_count($hero, 'alt=') === substr_count($hero, '<img'), 'alt appears once per <img>, and never on a <source>');
contains($hero, 'alt=""', 'the decorative hero renders alt="" — by declaration now, not by a hardcoded attribute');
missing($hero, 'w=1800', 'a width outside the allowlist never reaches the markup');

section('Slug resolution');
ok($cms->content->findBySlug('/epikoinonia') !== null, 'second page resolves');
ok($cms->content->findBySlug('/does-not-exist') === null, 'unknown slug returns null');
ok(count($cms->content->list()) === 2, 'page list finds both pages');

section('Unknown component does not fatal a live page');
$page['blocks'][] = ['id' => 'ghost', 'type' => 'no_such_component', 'fields' => []];
$html2 = $cms->renderPage($page);
ok(substr_count($html2, '<section') === 5, 'unknown block skipped rather than crashing');

section('Image transformation URLs');
// Local dev no longer diverges from production: with transform off, img()
// points at the local derivative route instead of handing back a bare path, so
// the responsive markup is exercised before it is deployed.
$off = $cms->imageUrl('/uploads/x.jpg', 960);
ok($off === '/img.php?src=%2Fuploads%2Fx.jpg&w=960', 'transform disabled: the local derivative route is used — ' . $off);
contains($cms->imageUrl('/uploads/x.jpg', 960, 'webp'), '&f=webp', 'an explicit format reaches the route');
ok($cms->imageUrl('/uploads/x.jpg', 800) === '', 'a width outside the allowlist yields no URL at all, rather than one that would 404');
ok($cms->imageUrl('/uploads/x.jpg', 960, 'avif') === '', 'and neither does a format outside it');
ok($cms->imageUrl('/uploads/x.jpg') === '/uploads/x.jpg', 'asking for no width still means the stored original');

$cfg = require dirname(__DIR__) . '/config.php';
$cfg['images']['transform'] = true;
$on = (new \Dopamine\FlatCms\Cms($cfg))->imageUrl('https://media.test.gr/uploads/x.jpg', 1280);
contains($on, '/cdn-cgi/image/width=1280,quality=82,format=auto,fit=cover/', 'cdn-cgi transform URL built');
contains($on, 'https://media.test.gr/uploads/x.jpg', 'absolute source preserved');
ok((new \Dopamine\FlatCms\Cms($cfg))->imageUrl('https://media.test.gr/uploads/x.jpg', 1200) === '',
    'the width allowlist applies to the Cloudflare path too — one set of variants, both backends');

summary();
