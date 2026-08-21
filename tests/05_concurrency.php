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
$file  = content_root() . '/pages/el/home.yml';
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
$_SESSION['csrf'] = 'test-token';
$refused = admin_post([
    'action'   => 'save',
    'csrf'     => 'test-token',
    'page'     => 'home',
    'title'    => 'Τίτλος από τη σελίδα που συγκρούστηκε',
    'baseline' => str_repeat('0', 64),   // stale by construction
    'blocks'   => [
        'hero' => [
            'subheading' => 'Κείμενο που δεν πρέπει να χαθεί<script>alert(1)</script>',
        ],
    ],
]);
$body = (string) $refused->getContent();

ok($refused->getStatusCode() === 200, 'the conflict re-renders the form rather than erroring out');
contains($body, cms()->lang->t('err.stale'), 'the conflict is explained');
contains($body, cms()->lang->t('flash.conflict_help'), 'the editor is told their text was kept');
contains($body, 'value="Τίτλος από τη σελίδα που συγκρούστηκε"',
    'the editable page title is kept with the rest of the rejected submission');
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
$locks->touch('home', 'admin@example-domain.com');

ok($locks->heldByOther('home', 'admin@example-domain.com') === null, 'you never collide with yourself');

$other = $locks->heldByOther('home', 'editor@example-domain.com');
ok(is_array($other) && $other['user'] === 'admin@example-domain.com', 'a second editor is told who else is in the page');
ok(is_array($other) && $other['minutes'] === 0, 'and how long ago they arrived');

$locks->release('home', 'editor@example-domain.com');
ok($locks->heldByOther('home', 'editor@example-domain.com') !== null, 'releasing someone else\'s marker does nothing');

$locks->release('home', 'admin@example-domain.com');
ok($locks->heldByOther('home', 'editor@example-domain.com') === null, 'releasing your own marker clears it');

section('A stale marker never strands a page');
$locks->touch('home', 'admin@example-domain.com');
$lockFile = dirname(__DIR__) . '/var/locks/home.json';
file_put_contents($lockFile, json_encode(['user' => 'admin@example-domain.com', 'at' => time() - 3600]));
ok($locks->heldByOther('home', 'editor@example-domain.com') === null, 'a marker older than the TTL is ignored');
@unlink($lockFile);

section('A global rides the same transaction as a page');
// It is a page file, so lock, baseline and snapshot are not reimplemented for
// it — this is the proof rather than an assumption.
$headerFile = content_root() . '/pages/el/_header.yml';
$headerBackup = $headerFile . '.concurrency.bak';
copy($headerFile, $headerBackup);

$staleGlobal = $cms->content->baseline('_header');
$header = $cms->content->load('_header');
$header['blocks'][0]['fields']['logo']['alt'] = 'Αλλαγή από τον συνάδελφο';
$cms->content->save('_header', $header);

$caughtGlobal = null;
try {
    $cms->content->transaction('_header', $staleGlobal, static fn (array $p): array => $p);
} catch (StaleContentException $e) {
    $caughtGlobal = $e;
}
ok($caughtGlobal instanceof StaleContentException, 'a stale save to the header is refused with the same typed exception');
ok(Yaml::parseFile($headerFile)['blocks'][0]['fields']['logo']['alt'] === 'Αλλαγή από τον συνάδελφο',
    "and the other person's header edit survived intact");
// A refused transaction leaves no snapshot behind, so this asks after one that
// is allowed to complete.
$cms->content->transaction('_header', '', static function (array $p): array {
    $p['blocks'][0]['fields']['logo']['alt'] = 'Δεύτερη αλλαγή';

    return $p;
});
ok($cms->content->revisions('_header') !== [], 'a global is snapshotted before it is overwritten, like any page');

rename($headerBackup, $headerFile);
array_map('unlink', glob(content_root() . '/.revisions/el/_header.*.yml') ?: []);

rename($backup, $file);
// Scoped to the fixture page: a suite run must never wipe real revision history.
array_map('unlink', glob(content_root() . '/.revisions/el/home.*.yml') ?: []);

summary();
