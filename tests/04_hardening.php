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
use Dopamine\FlatCms\ValidationException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Yaml\Yaml;

putenv('AUTH_DEV_BYPASS=1');

$_SESSION['csrf'] = 'test-token';
$hostile = require __DIR__ . '/fixtures/hostile_save.php';

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

// The cloudflared / DDEV-router case: the request genuinely arrives from
// loopback, but the bypass is off. dev_bypass is read from the environment
// here rather than hand-set on the array, so this exercises the real path.
putenv('AUTH_DEV_BYPASS=0');
$cfgNoBypass = require dirname(__DIR__) . '/config.php';
putenv('AUTH_DEV_BYPASS=1');

ok($cfgNoBypass['auth']['dev_bypass'] === false, 'AUTH_DEV_BYPASS=0 really does turn the bypass off');

$loopback = admin(
    Request::create('/admin.php', 'GET', ['action' => 'edit', 'page' => 'home'], [], [], ['REMOTE_ADDR' => '127.0.0.1']),
    $cfgNoBypass
);
contains((string) $loopback->getContent(), 'Cloudflare Access', 'a loopback request is refused when the bypass is off (cloudflared case)');
ok($loopback->getStatusCode() === 403, 'and refused with a 403, not served');

section('The roles file fails closed on every way it can be wrong');
// Authorisation is a second gate, and a gate that opens when its config is
// broken is not a gate. Every case here grants nothing.
$rolesFile = dirname(__DIR__) . '/var/cache/roles-hardening.yml';
file_put_contents($rolesFile, implode("\n", [
    '- { email: good@example.gr, role: editor }',
    '- { email: typo@example.gr, role: administrator }',   // not a role we have
    '- { email: blank@example.gr }',                       // no role at all
    '- { email: "", role: admin }',                        // no email at all
    '- just-a-string',
    '',
]));

$roleOf = static fn (string $email, ?string $file = null): int
    => as_user($email, 'GET', [], ['roles_file' => $file ?? $rolesFile])->getStatusCode();

ok($roleOf('good@example.gr') === 200, 'a well-formed row grants access');
ok($roleOf('GOOD@example.GR') === 200, 'and the address is matched case-insensitively, as mail is');
ok($roleOf('typo@example.gr') === 403, 'role: administrator grants nothing — an unknown role is not a role');
ok($roleOf('blank@example.gr') === 403, 'a row with no role grants nothing');
ok($roleOf('nobody@example.gr') === 403, 'an address that is simply absent is refused');
ok($roleOf('good@example.gr', $rolesFile . '.missing') === 403, 'a missing roles file denies everyone rather than opening the panel');

file_put_contents($rolesFile, "not: a list\n");
ok($roleOf('good@example.gr') === 403, 'a roles file of the wrong shape denies everyone too');
unlink($rolesFile);

section('Auth::user() answers with an identity and a role, or with nothing');
$who = new \Dopamine\FlatCms\Auth(
    ['mode' => 'none', 'dev_bypass' => false, 'roles_file' => ''],
    dirname(__DIR__) . '/var/cache'
);
$dev = $who->user(Request::create('/admin.php'));
ok(($dev['email'] ?? '') === 'dev@localhost', 'the dev bypass reports an email');
ok(($dev['role'] ?? '') === 'admin', 'and a role, so nothing downstream has to guess one');

$closed = new \Dopamine\FlatCms\Auth(
    ['mode' => 'cf_access', 'dev_bypass' => false, 'aud' => 'x', 'team_domain' => 't', 'roles_file' => ''],
    dirname(__DIR__) . '/var/cache'
);
ok($closed->user(Request::create('/admin.php')) === null, 'and an unauthenticated request is null, not a partial user');

section('An unrecognised `editable:` value locks the field, it does not open it');
// A typo in schema.yml must cost the client a field, never hand them one.
foreach (['yes', 'ADMIN', 1, null] as $bad) {
    ok(\Dopamine\FlatCms\Components::mayEdit($bad, 'admin') === false,
        var_export($bad, true) . ' is not "editable" for an admin either');
}
ok(\Dopamine\FlatCms\Components::mayEdit('admin', 'editor') === false, 'editable: admin is closed to an editor');
ok(\Dopamine\FlatCms\Components::mayEdit(true, 'editor') === true, 'editable: true is open to an editor');

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

// demand() treats '' as empty and a link is a map, which is never '' — so
// `required: true` on one would pass silently without a check of its own.
// Either half satisfies it: a custom address is a destination too.
$req = ['type' => 'link', 'label' => 'CTA', 'required' => true];
$refused = static function (array $in) use ($req): bool {
    try {
        Fields::sanitise($req, $in, ['section' => 'Hero']);
        return false;
    } catch (ValidationException) {
        return true;
    }
};
ok($refused(['page' => '', 'url' => '']), 'a required link with neither half filled in is refused');
ok(!$refused(['page' => 'epikoinonia', 'url' => '']), 'a picked page satisfies it');
ok(!$refused(['page' => '', 'url' => 'https://example.gr']), 'and so does a custom address on its own');

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
$file = dirname(__DIR__) . '/content/pages/el/home.yml';
$backup = $file . '.hardening.bak';
copy($file, $backup);

$raw = Yaml::parseFile($file);
$raw['blocks'][0]['fields']['ghost_field'] = 'should not survive a save';
file_put_contents($file, Yaml::dump($raw, 6, 2));

// With the panel's catalogue, as Cms builds it. Without one a message
// falls back to its key, which is checked below.
$content = new Content(dirname(__DIR__) . '/content', 'el', cms()->lang);
admin_post($hostile('test-token', (string) hash_file('sha256', $file)));
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
contains($threw, cms()->lang->t('err.stale'), 'a save carrying a stale baseline is refused');

// A store built without a catalogue — a script, a cron — still refuses; it just
// says so in keys. A missing translation must never be a missing guard.
$mute = '';
try {
    (new Content(dirname(__DIR__) . '/content', 'el'))->transaction('home', $stale, static fn (array $p): array => $p);
} catch (Throwable $e) {
    $mute = $e->getMessage();
}
ok($mute === 'err.stale', 'and refuses identically with no catalogue loaded, falling back to the key: ' . $mute);

$fresh = $content->baseline('home');
$ran = false;
$content->transaction('home', $fresh, function (array $p) use (&$ran): array {
    $ran = true;
    return $p;
});
ok($ran, 'a save carrying the current baseline goes through');

section('Revision snapshots do not collide within one second');
// Scoped to the fixture page: a suite run must never wipe real revision history.
array_map('unlink', glob(dirname(__DIR__) . '/content/.revisions/el/home.*.yml') ?: []);
for ($i = 0; $i < 3; $i++) {
    $content->snapshot('home');
}
ok(count(glob(dirname(__DIR__) . '/content/.revisions/el/home.*.yml') ?: []) === 3, 'three snapshots in the same second produce three files');

// Pruning sorts by name, and a name only carries seconds — so inside one second
// the order is the random suffix, and the snapshot just taken could sort lowest
// and be deleted by its own prune. That made exactly one save in a burst the
// one that could not be undone.
$revsOf = static fn (): array => glob(dirname(__DIR__) . '/content/.revisions/el/home.*.yml') ?: [];
$kept = true;
for ($i = 0; $i < 12; $i++) {
    $was = $revsOf();
    $content->snapshot('home', 5);
    $kept = $kept && array_diff($revsOf(), $was) !== [];
}
ok($kept, 'and a snapshot is never pruned by its own prune, however many land in one second');
ok(count($revsOf()) === 5, 'while the keep limit still holds (' . count($revsOf()) . ' files)');

// restore
rename($backup, $file);
// Scoped to the fixture page: a suite run must never wipe real revision history.
array_map('unlink', glob(dirname(__DIR__) . '/content/.revisions/el/home.*.yml') ?: []);

section('Admin errors do not leak filesystem paths');
$error = admin_post([
    'action'   => 'save',
    'csrf'     => 'test-token',
    'page'     => 'no-such-page',
    'baseline' => '',
    'blocks'   => [],
]);
$body = (string) $error->getContent();
ok($error->getStatusCode() === 400, 'an internal error is a 400, not a 200 with an error-shaped page');
missing($body, '/home/', 'no absolute path in the error shown to the client');
missing($body, '.yml', 'no filename in the error shown to the client');
contains($body, cms()->lang->t('err.page_missing'), 'a client-appropriate message is shown instead');

section('Decompression-bomb images are refused before GD sees them');
// A valid PNG header claiming 30000x30000. Tiny on disk, ~3.6 GB decoded.
$ihdr = pack('N', 30000) . pack('N', 30000) . "\x08\x02\x00\x00\x00";
$png  = "\x89PNG\r\n\x1a\n" . pack('N', 13) . 'IHDR' . $ihdr . pack('N', 0);
$dim  = @getimagesizefromstring($png);
ok(is_array($dim) && $dim[0] === 30000, 'crafted header parses as 30000px wide without decoding');
ok(($dim[0] * $dim[1]) > $cfg['images']['max_pixels'], 'it exceeds the configured max_pixels guard');

section('A 48 MP phone image is refused, not survived');
// Not hostile — just a current flagship on its default setting. The rule is
// that it either normalizes or comes back with something the client can read,
// and never takes the worker with it.
$phone = "\x89PNG\r\n\x1a\n" . pack('N', 13) . 'IHDR'
    . pack('N', 8000) . pack('N', 6000) . "\x08\x02\x00\x00\x00" . pack('N', 0);
$phoneFile = sys_get_temp_dir() . '/dopamine-48mp-' . bin2hex(random_bytes(4)) . '.png';
file_put_contents($phoneFile, $phone);

$peak = memory_get_peak_usage(true);
$tooBig = admin(Request::create(
    '/admin.php',
    'POST',
    ['action' => 'upload', 'csrf' => 'test-token'],
    [],
    ['file' => new \Symfony\Component\HttpFoundation\File\UploadedFile(
        $phoneFile,
        'IMG_9000.PNG',
        'image/png',
        null,
        true
    )]
));
$grew = memory_get_peak_usage(true) - $peak;

ok($tooBig->getStatusCode() === 400, 'a 48 MP upload is refused');
contains((string) $tooBig->getContent(), cms()->lang->t('up.too_many_pixels'), 'with a message the client can act on');
missing((string) $tooBig->getContent(), '/home/', 'and no filesystem path in it');
// 8000x6000 at 4 bytes a pixel is 192 MB before the destination buffer. The
// bar here is generous because this measures a whole in-process admin request,
// error page and all; what it cannot pass is a decode.
ok($grew < 32 * 1024 * 1024,
    'having grown peak memory by ' . $grew . ' bytes rather than the 192 MB a decode would cost');

@unlink($phoneFile);

section('The upload memory budget catches images just below max_pixels');
// 38.5 MP is below the coarse 40 MP ceiling, but the source plus the 2400px
// normalized destination is ~172 MB of pixel buffers. That must be rejected
// before GD decodes it on a 256 MB production worker.
$nearLimit = "\x89PNG\r\n\x1a\n" . pack('N', 13) . 'IHDR'
    . pack('N', 7000) . pack('N', 5500) . "\x08\x02\x00\x00\x00" . pack('N', 0);
$nearFile = sys_get_temp_dir() . '/dopamine-38mp-' . bin2hex(random_bytes(4)) . '.png';
file_put_contents($nearFile, $nearLimit);

$nearSize = @getimagesizefromstring($nearLimit);
ok(($nearSize[0] * $nearSize[1]) < $cfg['images']['max_pixels'],
    'the fixture is deliberately below max_pixels, so that guard alone cannot save it');

$nearRefused = admin(Request::create(
    '/admin.php',
    'POST',
    ['action' => 'upload', 'csrf' => 'test-token'],
    [],
    ['file' => new \Symfony\Component\HttpFoundation\File\UploadedFile(
        $nearFile,
        'large-but-under-the-old-limit.png',
        'image/png',
        null,
        true
    )]
));
ok($nearRefused->getStatusCode() === 400, 'the combined source/destination memory budget refuses it');
contains((string) $nearRefused->getContent(), cms()->lang->t('up.too_many_pixels'), 'with the same actionable dimensions message');
@unlink($nearFile);

section('A global is content, never a URL');
// One rule, applied in one place — Content::list() — which is why routing, the
// sitemap, the menu and the link picker all agree without four separate checks.
$globalCms = cms();
$ids = array_column($globalCms->content->list(), 'id');
ok(!in_array('_header', $ids, true) && !in_array('_footer', $ids, true),
    'globals are absent from the page list: ' . implode(', ', $ids));
ok(array_column($globalCms->content->globals(), 'id') === ['_header', '_footer'],
    'and present in globals(), in reading order, which is what the panel lists');

ok($globalCms->content->findBySlug('/_header') === null, 'no slug resolves to a global');
ok($globalCms->content->findBySlug('/header') === null, 'nor does the name without its prefix');
missing($globalCms->sitemap(), '_header', 'the sitemap does not list one');
missing($globalCms->sitemap(), '_footer', 'nor the other');
$navIds = array_column($globalCms->nav(), 'id');
ok(array_filter($navIds, static fn (string $id): bool => str_starts_with($id, '_')) === [],
    'the menu never contains one: ' . implode(', ', $navIds));
ok($globalCms->pageUrl('_header') === '', 'and a link field pointing at one resolves to nothing, like a deleted page');

// The prefix is a routing rule, not a path guard. assertSafeSegment() is the
// path guard, and it refuses rather than strips: an earlier version stripped,
// so `../home` quietly resolved to `home` and an obvious attack was answered
// with a page.
$refused = static function (callable $fn): bool {
    try {
        $fn();

        return false;
    } catch (\RuntimeException) {
        return true;
    }
};
ok($refused(static fn () => $globalCms->content->load('../_header')),
    'a traversal attempt in a page id is refused, not silently stripped to a bare id');
ok($refused(static fn () => $globalCms->content->load('../../config')), 'and cannot reach outside the pages directory');
ok($refused(static fn () => $globalCms->content->load('home.yml')), 'nor can a filename be smuggled in as an id');

section('A crafted locale segment cannot escape content/pages/');
// Two gates, because one gate that moves is not a gate: the locale is matched
// against the configured map first, and Content refuses it again on the way to
// a directory name.
ok($refused(static fn () => $globalCms->useLocale('../../etc')), 'an unconfigured locale is refused by the map');
ok($refused(static fn () => $globalCms->useLocale('EL')), 'and the match is exact, not merely case-insensitive-ish');
ok($refused(static fn () => new Content(dirname(__DIR__) . '/content', '../el')),
    'and a traversal in a locale is refused by Content itself, whatever called it');

$localeCms = cms();
[$code, $rest] = $localeCms->localeOf('/en/contact');
ok($code === 'en' && $rest === '/contact', 'a prefixed path resolves to its language and the path inside it');
ok($localeCms->localeOf('/en') === ['en', '/'], 'the prefix alone is that language\'s home page');
ok($localeCms->localeOf('/epikoinonia') === ['el', '/epikoinonia'], 'and an unprefixed path is the default language');
ok($localeCms->localeOf('/english-lessons') === ['el', '/english-lessons'],
    'a slug that merely starts with the prefix letters is not a locale match');

summary();
