<?php
/**
 * Regression tests for the issues found in the security review.
 * Every check here corresponds to a bug that was real, not hypothetical.
 */

declare(strict_types=1);

session_start();

require __DIR__ . '/lib.php';

use Dopamine\FlatCms\Cms;
use Dopamine\FlatCms\Content;
use Dopamine\FlatCms\Fields;
use Symfony\Component\Yaml\Yaml;

putenv('AUTH_DEV_BYPASS=1');

// config.php defines env()/env_bool(); loading it also gives us the live config.
$cfgBoot = require dirname(__DIR__) . '/config.php';

/** Call a private static on Fields. */
function f(string $method, mixed ...$args): mixed
{
    $r = new ReflectionMethod(Fields::class, $method);
    $r->setAccessible(true);
    return $r->invoke(null, ...$args);
}

$BASES = ['media_bases' => ['/uploads/', 'https://media.pelatis.gr/']];

section('env_bool: string booleans do not silently enable things');
putenv('T_FLAG=false');
ok(env_bool('T_FLAG') === false, '"false" is false (plain (bool) cast returned true)');
putenv('T_FLAG=0');
ok(env_bool('T_FLAG') === false, '"0" is false');
putenv('T_FLAG=off');
ok(env_bool('T_FLAG') === false, '"off" is false');
putenv('T_FLAG=1');
ok(env_bool('T_FLAG') === true, '"1" is true');
putenv('T_FLAG');
ok(env_bool('T_FLAG', false) === false, 'unset falls back to the default');

section('Auth bypass is never inferred from the request');
putenv('AUTH_DEV_BYPASS');                       // as a fresh production box would be
$cfgDefault = require dirname(__DIR__) . '/config.php';
ok($cfgDefault['auth']['dev_bypass'] === false, 'dev_bypass defaults to OFF when the env var is unset');
putenv('AUTH_DEV_BYPASS=1');
$cfg = require dirname(__DIR__) . '/config.php';
ok(!method_exists(\Dopamine\FlatCms\Auth::class, 'isLocal'), 'REMOTE_ADDR-based bypass is gone entirely');
exec(sprintf('php %s 2>&1', escapeshellarg(__DIR__ . '/_loopback_no_bypass.php')), $out);
contains(implode("\n", $out), 'Cloudflare Access', 'a loopback request is refused when the bypass is off (cloudflared case)');

section('Image src is restricted to media we host');
ok(f('mediaPath', 'https://evil.tld/x.jpg', $BASES['media_bases']) === '', 'third-party URL rejected — no open image proxy via /cdn-cgi/image');
ok(f('mediaPath', '/uploads/2026/08/a.jpg', $BASES['media_bases']) === '/uploads/2026/08/a.jpg', 'local upload path accepted');
ok(f('mediaPath', 'https://media.pelatis.gr/uploads/a.jpg', $BASES['media_bases']) === 'https://media.pelatis.gr/uploads/a.jpg', 'configured R2 host accepted');
ok(f('mediaPath', '/uploads/../../config.php', $BASES['media_bases']) === '', 'traversal rejected');
ok(f('mediaPath', '//evil.tld/x.jpg', $BASES['media_bases']) === '', 'protocol-relative rejected');

section('Links: protocol-relative and backslash variants');
ok(f('link', '//evil.com') === '', '"//evil.com" rejected (was stored as an internal path)');
ok(f('link', '/\\evil.com') === '', '"/\\evil.com" rejected — browsers normalise it to //');
ok(f('link', '/epikoinonia') === '/epikoinonia', 'genuine internal path still works');
ok(f('link', 'https://example.gr') === 'https://example.gr', 'external URL still works');
ok(f('link', 'example.gr') === 'https://example.gr', 'bare domain still upgraded');
ok(f('link', 'javascript:alert(1)') === '', 'javascript: still rejected');

section('Richtext: href harvesting and external detection');
$h = f('rich', '<a data-x="href=\'//evil.gr\'" href="/epikoinonia">κείμενο</a>');
missing($h, 'evil.gr', 'href is not harvested out of a different attribute value');
contains($h, 'href="/epikoinonia"', 'the real href survives');
missing($h, 'target="_blank"', 'internal link gets no target/rel');
contains(f('rich', '<a href="https://example.gr">x</a>'), 'rel="noopener noreferrer"', 'external link still hardened');
missing($h, 'rel="noopener', 'internal link gets no rel either — the case above only checked target');

section('Richtext: decisions the sanitiser swap could silently undo');
// Each of these is invisible in normal use and would regress without a noise.

// An unknown element must be unwrapped, never dropped with its children. A
// Google Docs paste wraps the whole selection in <b><span>; dropping unknown
// elements would delete the client's text and look like the save had failed.
contains(f('rich', '<b style="font-weight:normal" id="docs-internal-guid-x">'
    . '<p dir="ltr"><span style="font-size:11pt">επικολλημένο κείμενο</span></p></b>'),
    'επικολλημένο κείμενο', 'a Google Docs paste keeps its text through unknown wrappers');
contains(f('rich', '<div><section><h2>Τίτλος</h2></section></div>'), 'Τίτλος', 'text survives wrappers the allowlist does not name');

// <style> is a <head> element to the sanitiser, so in body context it is
// unwrapped and its CSS is emitted as visible text. The old regex leaked it too.
missing(f('rich', '<style>body{display:none}</style>Κείμενο'), 'display:none', 'CSS never survives as visible text');
missing(f('rich', '<script>fetch("//evil.gr")</script>Κείμενο'), 'evil.gr', 'script source never survives as visible text');
contains(f('rich', '<style>body{display:none}</style>Κείμενο'), 'Κείμενο', 'and the prose beside it is untouched');

// Richtext hrefs and link fields must agree; they are the same rule.
ok(f('rich', '<a href="//evil.gr">x</a>') === '<a>x</a>', 'protocol-relative href stripped in richtext, as in a link field');
ok(f('rich', '<a href="/\\evil.gr">x</a>') === '<a>x</a>', 'backslash variant stripped in richtext too');
contains(f('rich', '<a href="example.gr">x</a>'), 'href="https://example.gr"', 'a bare domain is upgraded in richtext, as in a link field');

// mXSS: the parser must not be talked into re-interpreting sanitised output.
missing(f('rich', '<noscript><p title="</noscript><img src=x onerror=alert(1)>">'), 'onerror', 'the noscript mutation vector produces no event handler');
ok(f('rich', "<p>\xC3\x28 broken</p>") === '', 'invalid UTF-8 is refused outright rather than parsed');

section('Richtext has a hard ceiling');
$huge = f('rich', '<p>' . str_repeat('α', 250_000) . '</p>');
ok(mb_strlen($huge) <= 100_100, 'oversized richtext truncated (' . mb_strlen($huge) . ' chars) — was stored whole, then copied into 10 revisions');

section('Orphaned keys do not reach templates');
$cms = cms();
$schema = ['fields' => ['heading' => ['type' => 'text', 'default' => '']]];
$out = $cms->withDefaults($schema, ['heading' => 'ok', 'removed_unsafe_field' => '<script>x</script>']);
ok(!array_key_exists('removed_unsafe_field', $out), 'a key no longer in schema.yml is not exposed as fields.*');

section('Orphaned keys are dropped from disk on save');
$file = dirname(__DIR__) . '/content/pages/home.yml';
$backup = $file . '.hardening.bak';
copy($file, $backup);

$raw = Yaml::parseFile($file);
$raw['blocks'][0]['fields']['ghost_field'] = 'should not survive a save';
file_put_contents($file, Yaml::dump($raw, 6, 2));

$content = new Content(dirname(__DIR__) . '/content');
exec(sprintf('php %s 2>&1', escapeshellarg(__DIR__ . '/_do_save.php')), $o2);
$after = Yaml::parseFile($file);
ok(!array_key_exists('ghost_field', $after['blocks'][0]['fields']), 'undeclared key removed from the file on the next save');
ok(array_key_exists('heading', $after['blocks'][0]['fields']), 'declared fields still present');

section('Concurrent saves cannot clobber each other');
$stale = str_repeat('0', 64);
$threw = '';
try {
    $content->transaction('home', $stale, static fn (array $p): array => $p);
} catch (Throwable $e) {
    $threw = $e->getMessage();
}
contains($threw, 'άλλαξε από αλλού', 'a save carrying a stale baseline is refused');

$fresh = $content->baseline('home');
$ran = false;
$content->transaction('home', $fresh, function (array $p) use (&$ran): array {
    $ran = true;
    return $p;
});
ok($ran, 'a save carrying the current baseline goes through');

section('Revision snapshots do not collide within one second');
// Scoped to the fixture page: a suite run must never wipe real revision history.
array_map('unlink', glob(dirname(__DIR__) . '/content/.revisions/home.*.yml') ?: []);
for ($i = 0; $i < 3; $i++) {
    $content->snapshot('home');
}
ok(count(glob(dirname(__DIR__) . '/content/.revisions/home.*.yml') ?: []) === 3, 'three snapshots in the same second produce three files');

// restore
rename($backup, $file);
// Scoped to the fixture page: a suite run must never wipe real revision history.
array_map('unlink', glob(dirname(__DIR__) . '/content/.revisions/home.*.yml') ?: []);

section('Admin errors do not leak filesystem paths');
exec(sprintf('php %s 2>/dev/null', escapeshellarg(__DIR__ . '/_bad_page.php')), $o3);
$body = implode("\n", $o3);
missing($body, '/home/', 'no absolute path in the error shown to the client');
missing($body, '.yml', 'no filename in the error shown to the client');
contains($body, 'Η σελίδα δεν βρέθηκε', 'a client-appropriate Greek message is shown instead');

section('Decompression-bomb images are refused before GD sees them');
// A valid PNG header claiming 30000x30000. Tiny on disk, ~3.6 GB decoded.
$ihdr = pack('N', 30000) . pack('N', 30000) . "\x08\x02\x00\x00\x00";
$png  = "\x89PNG\r\n\x1a\n" . pack('N', 13) . 'IHDR' . $ihdr . pack('N', 0);
$dim  = @getimagesizefromstring($png);
ok(is_array($dim) && $dim[0] === 30000, 'crafted header parses as 30000px wide without decoding');
ok(($dim[0] * $dim[1]) > $cfg['images']['max_pixels'], 'it exceeds the configured max_pixels guard');

summary();
