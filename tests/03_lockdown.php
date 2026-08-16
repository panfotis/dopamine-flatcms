<?php
/**
 * The test that matters: a client (or anyone with their login) can change
 * values and nothing else. Runs a hostile save, then inspects what actually
 * landed on disk.
 */

declare(strict_types=1);

require __DIR__ . '/lib.php';

use Symfony\Component\Yaml\Yaml;

$file   = dirname(__DIR__) . '/content/pages/home.yml';
$backup = $file . '.bak';
copy($file, $backup);

$before = Yaml::parseFile($file);

exec(sprintf('php %s 2>&1', escapeshellarg(__DIR__ . '/_do_save.php')), $out, $code);

$after = Yaml::parseFile($file);
$hero  = $after['blocks'][0]['fields'];
$intro = $after['blocks'][1]['fields'];

section('Save completed');
ok($code === 0, 'save process exited cleanly');
ok(count($after['blocks']) === count($before['blocks']), 'block count unchanged (4)');

section('Structure is not editable');
ok($after['blocks'][0]['type'] === 'hero', 'posting blocks[hero][type] did not retype the component');
ok($after['slug'] === '/', 'posting slug did not move the page');
ok(array_column($after['blocks'], 'id') === array_column($before['blocks'], 'id'), 'block ids and order untouched');
ok(!in_array('injected_block', array_column($after['blocks'], 'id'), true), 'posting an unknown block id added nothing');
ok(!array_key_exists('evil', $hero), 'field not present in schema.yml was dropped');

section('Locked fields stay locked');
ok($hero['align'] === 'center', 'editable:false field ignored on save (still "center", posted "start")');

section('Legitimate edits go through');
ok($hero['subheading'] === 'Νέος υπότιτλος από τον πελάτη.', 'subheading updated');
ok($after['title'] === 'Αρχική σελίδα bold', 'page title trimmed, whitespace collapsed, tags stripped');

section('Text fields are sanitised');
missing($hero['heading'], '<script', 'script tag stripped from text field');
missing($hero['heading'], 'onerror', 'inline event handler stripped');
ok($hero['heading'] === 'Καλημέρα alert(1)', 'heading reduced to plain text: ' . $hero['heading']);
ok(mb_strlen($hero['heading']) <= 70, 'max length respected');

section('Link fields reject hostile URLs');
ok($hero['cta_url'] === '', 'javascript: URL rejected outright');

section('Rich text is whitelisted');
missing($intro['body'], '<script', 'script tag removed');
missing($intro['body'], '<style', 'style tag removed');
missing($intro['body'], 'onclick', 'attribute stripped from allowed tag');
missing($intro['body'], '<font', 'Word-paste font tag removed');
missing($intro['body'], 'javascript:', 'javascript: link removed');
contains($intro['body'], '<strong>έντονο</strong>', 'allowed formatting preserved');
contains($intro['body'], '<a href="https://example.gr" target="_blank" rel="noopener noreferrer">', 'external link kept and made safe');
missing($intro['body'], '&nbsp;</p>', 'empty paragraph dropped');

section('Revisions');
$revs = glob(dirname(__DIR__) . '/content/.revisions/home.*.yml') ?: [];
ok(count($revs) >= 1, 'a revision snapshot was written before saving');

section('Page still renders after a hostile save');
$html = cms()->renderPage(cms()->content->load('home'));
missing($html, '<script>alert', 'no injected script in the rendered page');
contains($html, 'Νέος υπότιτλος', 'edited copy is live');

// restore
rename($backup, $file);
// Scoped to the fixture page: a suite run must never wipe real revision history.
array_map('unlink', glob(dirname(__DIR__) . '/content/.revisions/home.*.yml') ?: []);

summary();
