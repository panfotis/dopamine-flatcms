<?php

declare(strict_types=1);

require __DIR__ . '/lib.php';

$cms = cms();

section('Components load from disk');
$types = array_keys($cms->components->all());
sort($types);
ok($types === ['contact_cta', 'hero', 'text_image'], 'three components discovered: ' . implode(', ', $types));
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
ok(substr_count($html, '<section') === 4, 'all four blocks rendered as sections');

section('Slug resolution');
ok($cms->content->findBySlug('/epikoinonia') !== null, 'second page resolves');
ok($cms->content->findBySlug('/does-not-exist') === null, 'unknown slug returns null');
ok(count($cms->content->list()) === 2, 'page list finds both pages');

section('Unknown component does not fatal a live page');
$page['blocks'][] = ['id' => 'ghost', 'type' => 'no_such_component', 'fields' => []];
$html2 = $cms->renderPage($page);
ok(substr_count($html2, '<section') === 4, 'unknown block skipped rather than crashing');

section('Image transformation URLs');
$off = $cms->imageUrl('/uploads/x.jpg', 800);
ok($off === '/uploads/x.jpg', 'transform disabled in config: source URL untouched');

$cfg = require dirname(__DIR__) . '/config.php';
$cfg['images']['transform'] = true;
$on = (new \Dopamine\FlatCms\Cms($cfg))->imageUrl('https://media.test.gr/uploads/x.jpg', 1200);
contains($on, '/cdn-cgi/image/width=1200,quality=82,format=auto,fit=cover/', 'cdn-cgi transform URL built');
contains($on, 'https://media.test.gr/uploads/x.jpg', 'absolute source preserved');

summary();
