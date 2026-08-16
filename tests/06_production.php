<?php
/**
 * Phase 0 — the production contracts, as checks rather than prose.
 *
 * Each section corresponds to one Phase 0 item and to its acceptance criterion:
 * the production paths resolve from a fixture config, an atomic-release spike
 * preserves shared state across two releases, a private page is not edge-cached,
 * the derivative route rejects an off-allowlist width without decoding, and all
 * four unsafe auth configurations fail closed.
 */

declare(strict_types=1);

require __DIR__ . '/lib.php';

use Dopamine\FlatCms\Cms;
use Dopamine\FlatCms\Content;
use Dopamine\FlatCms\Media;

$root = dirname(__DIR__);

/** Load the fixture env into an array without touching this process's env. */
function fixture_env(string $file): array
{
    $out = [];
    foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        if (str_starts_with(trim($line), '#') || !str_contains($line, '=')) {
            continue;
        }
        [$k, $v] = explode('=', trim($line), 2);
        $out[$k] = $v;
    }

    return $out;
}

/** Run a script in a child process with an explicit environment. */
function run(string $script, array $env = [], string $args = ''): string
{
    $prefix = '';
    foreach ($env as $k => $v) {
        $prefix .= escapeshellarg($k . '=' . $v) . ' ';
    }

    $cmd = 'env ' . $prefix . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script)
        . ($args === '' ? '' : ' ' . escapeshellarg($args)) . ' 2>&1';

    return (string) shell_exec($cmd);
}

// ── 1. Production layout ────────────────────────────────────────────────────

section('Production layout resolves from the fixture config');

$fixture = __DIR__ . '/fixtures/production.env';
ok(is_file($fixture), 'tests/fixtures/production.env exists');

$env = fixture_env($fixture);
ok($env['CONTENT_PATH'] === '/var/www/pelatis/shared/content', 'content/ is shared, outside the release directory');
ok($env['VAR_PATH'] === '/var/www/pelatis/shared/var', 'var/ is shared: cache, locks, submissions never deployed');
ok(str_starts_with($env['UPLOADS_PATH'], $env['CONTENT_PATH']), 'uploads live inside the content repository, not the release');
ok($env['ROLES_FILE'] === '/var/www/pelatis/shared/roles.yml', 'the roles file is shared state too');

// The engine must actually honour them — a fixture nobody reads is a comment.
$probe = __DIR__ . '/_probe_paths.php';
file_put_contents($probe, "<?php\n\$c = require dirname(__DIR__) . '/config.php';\n"
    . "echo json_encode(\$c['paths'] + ['roles' => \$c['auth']['roles_file']]);\n");
// APP_ENV dropped: the guard is item 5's business and roles.yml does not exist here.
$paths = json_decode(run($probe, ['CONTENT_PATH' => $env['CONTENT_PATH'], 'VAR_PATH' => $env['VAR_PATH'],
    'UPLOADS_PATH' => $env['UPLOADS_PATH'], 'ROLES_FILE' => $env['ROLES_FILE']]), true);
unlink($probe);

ok(($paths['content'] ?? '') === $env['CONTENT_PATH'], 'config.php resolves CONTENT_PATH');
ok(($paths['cache'] ?? '') === $env['VAR_PATH'] . '/cache', 'config.php puts the cache under VAR_PATH');
ok(($paths['uploads'] ?? '') === $env['UPLOADS_PATH'], 'config.php resolves UPLOADS_PATH');
ok(($paths['roles'] ?? '') === $env['ROLES_FILE'], 'config.php resolves ROLES_FILE');

// ── 2. Release discipline and page-storage shape ────────────────────────────

section('Releases stay 0.x, and page storage has one permanent shape');

$composer = json_decode((string) file_get_contents($root . '/composer.json'), true);
ok(str_starts_with((string) ($composer['version'] ?? ''), '0.'),
    'the package is ' . ($composer['version'] ?? '?') . ' — no stability promise before the pilot');
ok(($composer['license'] ?? '') === 'proprietary', 'license is still proprietary; going public is a deliberate decision, not a slip');

// Single-locale storage is the permanent shape: content/pages/<id>.yml today,
// content/pages/<locale>/<id>.yml in Phase 9. Anything else is a migration
// nobody agreed to, so pin the resolver rather than trusting a comment.
$fileOf = new ReflectionMethod(Content::class, 'file');
$fileOf->setAccessible(true);
$resolved = $fileOf->invoke(new Content('/CONTENT'), 'home');
ok($resolved === '/CONTENT/pages/home.yml', 'a page id resolves to <content>/pages/<id>.yml, flat and single-locale');
ok(glob($root . '/content/pages/*/', GLOB_ONLYDIR) === [], 'no locale directory has been invented ahead of Phase 9');
ok(count(glob($root . '/content/pages/*.yml') ?: []) > 0, 'and the real content is stored in exactly that shape');

// ── 3. Atomic release spike ─────────────────────────────────────────────────

section('An atomic release preserves shared state across two releases');

$spike = $root . '/var/cache/spike-' . bin2hex(random_bytes(4));
foreach (['releases/r1', 'releases/r2', 'shared/content/pages', 'shared/var/cache', 'shared/var/locks'] as $d) {
    mkdir($spike . '/' . $d, 0775, true);
}
// Each release is code only. Nothing a client can write lives inside one.
file_put_contents($spike . '/releases/r1/VERSION', "r1\n");
file_put_contents($spike . '/releases/r2/VERSION', "r2\n");

$deploy = static function (string $target) use ($spike): void {
    // Atomic: a new symlink beside the old one, then rename over it. A reader
    // sees either the old release or the new one, never a missing `current`.
    $tmp = $spike . '/current.tmp';
    @unlink($tmp);
    symlink($target, $tmp);
    rename($tmp, $spike . '/current');
};

$shared = new Content($spike . '/shared/content');

$deploy($spike . '/releases/r1');
ok(readlink($spike . '/current') === $spike . '/releases/r1', 'current points at release r1');

// The client edits, and the engine writes through the shared path only.
$shared->save('home', ['title' => 'Γραμμένο στο r1', 'slug' => '/', 'blocks' => []]);
$shared->snapshot('home');
file_put_contents($spike . '/shared/var/locks/home.json', '{"who":"client"}');
file_put_contents($spike . '/shared/var/cache/warm.txt', 'warm');

ok(is_file($spike . '/shared/content/pages/home.yml'), 'the save landed in shared/content, not in the release');
ok(!is_file($spike . '/releases/r1/content/pages/home.yml'), 'the release directory holds no content at all');

$deploy($spike . '/releases/r2');

ok(readlink($spike . '/current') === $spike . '/releases/r2', 'deploy flipped current to r2');
ok(trim((string) file_get_contents($spike . '/current/VERSION')) === 'r2', 'the new code is live');
ok(($shared->load('home')['title'] ?? '') === 'Γραμμένο στο r1', 'the client save survives the deploy untouched');
ok(count(glob($spike . '/shared/content/.revisions/home.*.yml') ?: []) === 1, 'revision history survives the deploy');
ok(is_file($spike . '/shared/var/locks/home.json'), 'shared/var/locks survives the deploy');
ok(is_file($spike . '/shared/var/cache/warm.txt'), 'shared/var/cache survives the deploy');

// A rollback is the same flip in reverse, and equally harmless to content.
$deploy($spike . '/releases/r1');
ok(($shared->load('home')['title'] ?? '') === 'Γραμμένο στο r1', 'a rollback to r1 does not resurrect old content either');

exec('rm -rf ' . escapeshellarg($spike));

// ── 4. Media derivative contract ────────────────────────────────────────────

section('The derivative contract is settled before any GD code exists');

$cms = cms();
$d = $cms->config['images']['derivatives'];
$bases = $cms->fieldContext()['media_bases'];

ok($d['widths'] === [320, 640, 960, 1280, 1600, 2048], 'the width allowlist is finite and explicit');
ok($d['formats'] === ['auto', 'webp', 'jpeg'], 'format rules are an allowlist; avif is deliberately absent');
ok($d['sources'] === ['uploads', 'r2'], 'source adapters are named: local uploads and the R2 public base');
ok(is_int($d['memory_budget']) && $d['memory_budget'] > 0, 'a per-decode memory budget exists (' . $d['memory_budget'] . ' bytes)');
ok($d['memory_budget'] < $cms->config['images']['max_pixels'] * 4, 'the anonymous-GET budget is tighter than the upload guard');
ok(is_int($d['cache_max_age']) && is_int($d['cache_max_bytes']), 'cache ceilings exist for both age and disk');
ok(!class_exists('Dopamine\FlatCms\Media') || !method_exists(Media::class, 'encode'), 'no encoder exists yet — the contract came first');

section('The derivative route rejects a bad width without decoding');

$good = Media::spec($cms->config['images'], $bases, ['src' => '/uploads/a.png', 'w' => '960']);
ok($good !== null && $good['width'] === 960, 'an allowlisted width is accepted');
ok($good['format'] === 'jpeg', 'format=auto without an Accept header falls back to jpeg');
ok(Media::spec($cms->config['images'], $bases, ['src' => '/uploads/a.png', 'w' => '960'], 'image/webp,*/*')['format'] === 'webp', 'format=auto honours Accept: image/webp');

ok(Media::spec($cms->config['images'], $bases, ['src' => '/uploads/a.png', 'w' => '1337']) === null, 'a width outside the allowlist is refused');
ok(Media::spec($cms->config['images'], $bases, ['src' => '/uploads/a.png', 'w' => '0960']) === null, 'a padded width is refused, not coerced into an allowlisted one');
ok(Media::spec($cms->config['images'], $bases, ['src' => '/uploads/a.png', 'w' => '960abc']) === null, 'a trailing-garbage width is refused, not cast');
ok(Media::spec($cms->config['images'], $bases, ['src' => '/uploads/a.png', 'w' => '99999999']) === null, 'an enormous width is refused');
ok(Media::spec($cms->config['images'], $bases, ['src' => '/uploads/a.png', 'w' => '960', 'f' => 'avif']) === null, 'an off-allowlist format is refused');
ok(Media::spec($cms->config['images'], $bases, ['src' => 'https://evil.tld/x.jpg', 'w' => '960']) === null, 'an off-base src is refused — no open image proxy');
ok(Media::spec($cms->config['images'], $bases, ['src' => '/uploads/../config.php', 'w' => '960']) === null, 'traversal is refused');

// spec() never touches the filesystem: it accepts a src that does not exist.
// That is the proof that rejection happens before any read, not after one.
ok(Media::spec($cms->config['images'], $bases, ['src' => '/uploads/does-not-exist.png', 'w' => '640']) !== null,
    'spec() resolves without stat()ing the source — validation precedes every read');

// End to end, against a real decompression bomb on disk.
$bomb = $root . '/public/uploads/_bomb.png';
@mkdir(dirname($bomb), 0775, true);
$ihdr = pack('N', 30000) . pack('N', 30000) . "\x08\x02\x00\x00\x00";
file_put_contents($bomb, "\x89PNG\r\n\x1a\n" . pack('N', 13) . 'IHDR' . $ihdr . pack('N', 0));

$bad = run(__DIR__ . '/_img_route.php', [], 'src=/uploads/_bomb.png&w=1337');
contains($bad, 'status=404', 'the route 404s a bad width pointed at a 30000x30000 source');
preg_match('/peak_growth=(\d+)/', $bad, $m);
ok(isset($m[1]) && (int) $m[1] < 4 * 1024 * 1024, 'and grew peak memory by ' . (int) ($m[1] ?? -1) . ' bytes — the bomb was never decoded');

$off = run(__DIR__ . '/_img_route.php', [], 'src=https://evil.tld/x.jpg&w=960');
contains($off, 'status=404', 'the route 404s an off-base src');

unlink($bomb);

// ── 5. Contact-form caching ─────────────────────────────────────────────────

section('Form pages are private and bypass edge caching');

$public  = $cms->content->load('home');
$private = $cms->content->load('epikoinonia');

ok(($private['private'] ?? false) === true, 'the contact page is marked private in its YAML');
ok(!array_key_exists('private', $public), 'ordinary pages are unaffected and still cacheable');

$ph = implode("\n", $cms->cacheHeaders($private));
contains($ph, 'no-store', 'a private page sends no-store');
contains($ph, 'private', 'and marks the response private, so no shared cache may hold it');
missing($ph, 's-maxage', 'no s-maxage: nothing is offered to the edge');
missing($ph, 'Cache-Tag', 'and no Cache-Tag, since there is nothing at the edge to purge');

$bh = implode("\n", $cms->cacheHeaders($public));
contains($bh, 's-maxage=31536000', 'a public page still goes to the edge for a year');
contains($bh, 'Cache-Tag: page:home,site', 'and still carries its purge tags');

// Over real HTTP, through nginx, as Cloudflare would see it.
$curl = static fn (string $path): string => (string) shell_exec(
    'curl -sI ' . escapeshellarg('http://localhost' . $path) . ' 2>&1'
);
$live = $curl('/epikoinonia');
ok(str_contains($live, '200'), 'the contact page serves 200 over HTTP');
ok(stripos($live, 'no-store') !== false, 'and the live response really is no-store');
ok(stripos($live, 's-maxage') === false, 'the live response offers the edge nothing');
ok(stripos($curl('/'), 's-maxage=31536000') !== false, 'while the home page is still edge-cached for a year');

// ── 6. Production boot guard ────────────────────────────────────────────────

section('APP_ENV=prod fails closed on every unsafe auth configuration');

$roles = $root . '/var/cache/roles-spike.yml';
file_put_contents($roles, "admin: [hello@example.gr]\n");

// A safe production environment, which each case below then breaks in one way.
$safe = [
    'APP_ENV'       => 'prod',
    'AUTH_MODE'     => 'cf_access',
    'AUTH_DEV_BYPASS' => '0',
    'CF_ACCESS_AUD' => str_repeat('a', 64),
    'ROLES_FILE'    => $roles,
];

contains(run(__DIR__ . '/_boot.php', $safe), 'BOOTED', 'a correctly configured production box boots');

$m1 = run(__DIR__ . '/_boot.php', ['AUTH_MODE' => 'none'] + $safe);
contains($m1, 'REFUSED', 'AUTH_MODE=none is refused');
contains($m1, 'wide open', 'and the refusal says why');

contains(run(__DIR__ . '/_boot.php', ['AUTH_DEV_BYPASS' => '1'] + $safe), 'REFUSED', 'AUTH_DEV_BYPASS=1 is refused');
contains(run(__DIR__ . '/_boot.php', ['CF_ACCESS_AUD' => ''] + $safe), 'REFUSED', 'an empty Access audience is refused');
contains(run(__DIR__ . '/_boot.php', ['ROLES_FILE' => $root . '/var/cache/nope.yml'] + $safe), 'REFUSED', 'a missing roles file is refused');

// All four at once must report all four, not just the first.
$all = run(__DIR__ . '/_boot.php', [
    'APP_ENV' => 'prod', 'AUTH_MODE' => 'none', 'AUTH_DEV_BYPASS' => '1',
    'CF_ACCESS_AUD' => '', 'ROLES_FILE' => $root . '/var/cache/nope.yml',
]);
ok(substr_count($all, "\n  - ") === 4, 'a fully broken box reports all four problems at once, not one per deploy');

// And the guard is inert outside production, or nobody could develop.
contains(run(__DIR__ . '/_boot.php', ['AUTH_MODE' => 'none', 'AUTH_DEV_BYPASS' => '1']), 'BOOTED', 'the guard does not fire when APP_ENV is not prod');

unlink($roles);

summary();
