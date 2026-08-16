<?php
/**
 * Concurrent editing: refusing a stale save is correct, but the editor's work
 * must survive the refusal, and two people should be warned before they get
 * there at all.
 */

declare(strict_types=1);

session_start();

require __DIR__ . '/lib.php';

use Dopamine\FlatCms\Locks;
use Dopamine\FlatCms\StaleContentException;
use Symfony\Component\Yaml\Yaml;

putenv('AUTH_DEV_BYPASS=1');
require dirname(__DIR__) . '/config.php';

$cms   = cms();
$file  = dirname(__DIR__) . '/content/pages/home.yml';
$backup = $file . '.concurrency.bak';
copy($file, $backup);

section('A stale save is refused with a typed exception');
$stale = $cms->content->baseline('home');

// Someone else saves in the meantime.
$page = $cms->content->load('home');
$page['blocks'][0]['fields']['heading'] = 'Αλλαγή από τον συνάδελφο';
$cms->content->save('home', $page);

$caught = null;
try {
    $cms->content->transaction('home', $stale, static fn (array $p): array => $p);
} catch (StaleContentException $e) {
    $caught = $e;
}
ok($caught instanceof StaleContentException, 'StaleContentException thrown, not a generic RuntimeException');
ok(Yaml::parseFile($file)['blocks'][0]['fields']['heading'] === 'Αλλαγή από τον συνάδελφο', "the other person's save survived intact");

section("The refused editor's work is handed back, not discarded");
exec(sprintf('php %s 2>/dev/null', escapeshellarg(__DIR__ . '/_stale_save.php')), $out);
$body = implode("\n", $out);

contains($body, 'Η σελίδα άλλαξε από αλλού', 'the conflict is explained');
contains($body, 'είναι ακόμα στη', 'the editor is told their text was kept');
contains($body, 'Κείμενο που δεν πρέπει να χαθεί', 'the rejected submission is re-rendered in the form');
ok(Yaml::parseFile($file)['blocks'][0]['fields']['heading'] === 'Αλλαγή από τον συνάδελφο', 'and nothing was written to disk');

section('The re-rendered form carries the CURRENT baseline');
$current = hash_file('sha256', $file);
contains($body, 'name="baseline" value="' . $current . '"', 'saving again is a deliberate overwrite, not a second conflict');

section('Reflected input is sanitised before it is shown back');
contains($body, 'Κείμενο που δεν πρέπει να χαθεί', 'legitimate text preserved');
missing($body, '<script>alert(1)</script>', 'a hostile value in the rejected submission is not reflected raw');

section('Advisory presence markers');
$locks = new Locks(dirname(__DIR__) . '/var/locks');
$locks->touch('home', 'fotis@wearedope.com');

ok($locks->heldByOther('home', 'fotis@wearedope.com') === null, 'you never collide with yourself');

$other = $locks->heldByOther('home', 'pelatis@example.gr');
ok(is_array($other) && $other['user'] === 'fotis@wearedope.com', 'a second editor is told who else is in the page');
ok(is_array($other) && $other['minutes'] === 0, 'and how long ago they arrived');

$locks->release('home', 'pelatis@example.gr');
ok($locks->heldByOther('home', 'pelatis@example.gr') !== null, 'releasing someone else\'s marker does nothing');

$locks->release('home', 'fotis@wearedope.com');
ok($locks->heldByOther('home', 'pelatis@example.gr') === null, 'releasing your own marker clears it');

section('A stale marker never strands a page');
$locks->touch('home', 'fotis@wearedope.com');
$lockFile = dirname(__DIR__) . '/var/locks/home.json';
file_put_contents($lockFile, json_encode(['user' => 'fotis@wearedope.com', 'at' => time() - 3600]));
ok($locks->heldByOther('home', 'pelatis@example.gr') === null, 'a marker older than the TTL is ignored');
@unlink($lockFile);

rename($backup, $file);
// Scoped to the fixture page: a suite run must never wipe real revision history.
array_map('unlink', glob(dirname(__DIR__) . '/content/.revisions/home.*.yml') ?: []);

summary();
