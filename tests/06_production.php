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
$liveHttp = getenv('TEST_LIVE_HTTP') !== '0';
$testBaseUrl = rtrim(getenv('TEST_BASE_URL') ?: 'https://dopamine-flatcms.ddev.site', '/');

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
ok($env['CONTENT_PATH'] === '/var/www/example-domain/shared/content', 'content/ is shared, outside the release directory');
ok($env['VAR_PATH'] === '/var/www/example-domain/shared/var', 'var/ is shared: cache, locks, submissions never deployed');
ok(str_starts_with($env['UPLOADS_PATH'], $env['CONTENT_PATH']), 'uploads live inside the content repository, not the release');
ok($env['USERS_FILE'] === '/var/www/example-domain/shared/users.yml', 'the users file is shared state too');

// The engine must actually honour them — a fixture nobody reads is a comment.
$probe = __DIR__ . '/_probe_paths.php';
file_put_contents($probe, "<?php\n\$c = require dirname(__DIR__) . '/config.php';\n"
    . "echo json_encode(\$c['paths'] + ['roles' => \$c['auth']['users_file']]);\n");
// APP_ENV dropped: the guard is item 5's business and users.yml does not exist here.
$paths = json_decode(run($probe, ['CONTENT_PATH' => $env['CONTENT_PATH'], 'VAR_PATH' => $env['VAR_PATH'],
    'UPLOADS_PATH' => $env['UPLOADS_PATH'], 'USERS_FILE' => $env['USERS_FILE']]), true);
unlink($probe);

ok(($paths['content'] ?? '') === $env['CONTENT_PATH'], 'config.php resolves CONTENT_PATH');
ok(($paths['cache'] ?? '') === $env['VAR_PATH'] . '/cache', 'config.php puts the cache under VAR_PATH');
ok(($paths['uploads'] ?? '') === $env['UPLOADS_PATH'], 'config.php resolves UPLOADS_PATH');
ok(($paths['roles'] ?? '') === $env['USERS_FILE'], 'config.php resolves USERS_FILE');

// Production does not export twenty values into every command. PHP entrypoints
// load the one shared file, selected explicitly by ENV_FILE; prove that path in
// a child process so Dotenv's process-level state cannot leak into this suite.
$dotenvRoot = $root . '/var/cache/dotenv-' . bin2hex(random_bytes(4));
mkdir($dotenvRoot, 0775, true);
$dotenv = $dotenvRoot . '/.env';
file_put_contents($dotenv, implode("\n", [
    'APP_ENV=dev',
    'CONTENT_PATH=' . $dotenvRoot . '/content',
    'VAR_PATH=' . $dotenvRoot . '/var',
    'UPLOADS_PATH=' . $dotenvRoot . '/content/uploads',
    '',
]));
$envProbe = $dotenvRoot . '/probe.php';
file_put_contents($envProbe, "<?php\nrequire " . var_export($root . '/vendor/autoload.php', true) . ";\n"
    . "\$c = require " . var_export($root . '/config.php', true) . ";\n"
    . "echo json_encode(\$c['paths']);\n");
$fromFile = json_decode(run($envProbe, ['ENV_FILE' => $dotenv]), true);
ok(($fromFile['content'] ?? '') === $dotenvRoot . '/content', 'ENV_FILE loads shared content configuration');
ok(($fromFile['cache'] ?? '') === $dotenvRoot . '/var/cache', 'and shared var configuration in the same process');
unlink($envProbe);
unlink($dotenv);
rmdir($dotenvRoot);

// ── 2. Release discipline and page-storage shape ────────────────────────────

section('Releases stay 0.x, and page storage has one permanent shape');

$cmsBoot = require $root . '/config.php';
$composer = json_decode((string) file_get_contents($root . '/composer.json'), true);
ok(!array_key_exists('version', $composer),
    'composer.json leaves versioning to git tags, so Composer can publish it cleanly');
// MIT was the deliberate decision (Packagist requires an OSS license); this
// pins it so a *drift* — back to proprietary, or to some third thing — is
// caught as loudly as going public accidentally would have been.
ok(($composer['license'] ?? '') === 'MIT', 'license is MIT — the deliberate public release decision');
ok(is_file($root . '/LICENSE') && str_contains((string) file_get_contents($root . '/LICENSE'), 'MIT License'),
    'and the LICENSE file backing it exists');
ok(($composer['type'] ?? '') === 'library', 'the root package is the reusable engine, not a runnable project pretending to be one');
ok(in_array('src/bootstrap.php', (array) ($composer['autoload']['files'] ?? []), true),
    'its process-level error handler is loaded through Composer rather than a site-relative require');

// content/pages/<locale>/<id>.yml is the permanent shape. Phase 5 adopts it —
// on a single-language site too — precisely so Phase 9 is a resolver change
// rather than a migration across twenty live sites after v1.0.0. The case is
// unchanged: pin the resolver rather than trusting a comment, and prove the
// real content is stored in exactly the shape the resolver expects.
$fileOf = new ReflectionMethod(Content::class, 'file');
$fileOf->setAccessible(true);
$resolved = $fileOf->invoke(new Content('/CONTENT', 'el'), 'home');
ok($resolved === '/CONTENT/pages/el/home.yml', 'a page id resolves to <content>/pages/<locale>/<id>.yml');
ok(glob($root . '/content/pages/*.yml') === [], 'no page is left at the old flat path — one shape, not two');
// One directory per configured language, and no others: a stray directory is
// content nobody can reach, since resolution goes through the locale map.
$dirs = array_map('basename', glob($root . '/content/pages/*', GLOB_ONLYDIR) ?: []);
$configured = array_keys((array) $cmsBoot['locales']);
sort($dirs);
sort($configured);
ok($dirs === $configured,
    'and one locale directory per configured language, no more: ' . implode(', ', $dirs));
ok(count(glob($root . '/content/pages/' . $cmsBoot['site']['locale'] . '/*.yml') ?: []) > 0,
    'with the real content inside it');

// ── 3. Atomic release spike ─────────────────────────────────────────────────

section('An atomic release preserves shared state across two releases');

$spike = $root . '/var/cache/spike-' . bin2hex(random_bytes(4));
foreach (['releases/r1', 'releases/r2', 'shared/content/pages/el', 'shared/var/cache', 'shared/var/locks'] as $d) {
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

$shared = new Content($spike . '/shared/content', 'el');

$deploy($spike . '/releases/r1');
ok(readlink($spike . '/current') === $spike . '/releases/r1', 'current points at release r1');

// The client edits, and the engine writes through the shared path only.
$shared->save('home', ['title' => 'Γραμμένο στο r1', 'slug' => '/', 'blocks' => []]);
$shared->snapshot('home');
file_put_contents($spike . '/shared/var/locks/home.json', '{"who":"client"}');
file_put_contents($spike . '/shared/var/cache/warm.txt', 'warm');

ok(is_file($spike . '/shared/content/pages/el/home.yml'), 'the save landed in shared/content, not in the release');
ok(!is_file($spike . '/releases/r1/content/pages/el/home.yml'), 'the release directory holds no content at all');

$deploy($spike . '/releases/r2');

ok(readlink($spike . '/current') === $spike . '/releases/r2', 'deploy flipped current to r2');
ok(trim((string) file_get_contents($spike . '/current/VERSION')) === 'r2', 'the new code is live');
ok(($shared->load('home')['title'] ?? '') === 'Γραμμένο στο r1', 'the client save survives the deploy untouched');
ok(count(glob($spike . '/shared/content/.revisions/el/home.*.yml') ?: []) === 1, 'revision history survives the deploy');
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
// Was "no encoder exists yet". Phase 4 wrote it, so the case becomes the one
// that mattered all along: the encoder came second and still answers to the
// contract rather than carrying rules of its own.
ok(method_exists(Media::class, 'encode'), 'the encoder exists, and it was written after the contract');
$encodeParams = (new ReflectionMethod(Media::class, 'encode'))->getParameters();
ok(count($encodeParams) === 2 && $encodeParams[1]->getName() === 'spec',
    'and it takes a spec() result rather than raw query parameters — nothing reaches GD that the allowlist did not pass');

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
$bomb = $root . '/content/uploads/_bomb.png';
@mkdir(dirname($bomb), 0775, true);
$ihdr = pack('N', 30000) . pack('N', 30000) . "\x08\x02\x00\x00\x00";
file_put_contents($bomb, "\x89PNG\r\n\x1a\n" . pack('N', 13) . 'IHDR' . $ihdr . pack('N', 0));

$bad = run(__DIR__ . '/_img_route.php', [], 'src=/uploads/_bomb.png&w=1337');
contains($bad, 'status=404', 'the route 404s a bad width pointed at a 30000x30000 source');
preg_match('/peak_growth=(\d+)/', $bad, $m);
ok(isset($m[1]) && (int) $m[1] < 4 * 1024 * 1024, 'and grew peak memory by ' . (int) ($m[1] ?? -1) . ' bytes — the bomb was never decoded');

$off = run(__DIR__ . '/_img_route.php', [], 'src=https://evil.tld/x.jpg&w=960');
contains($off, 'status=404', 'the route 404s an off-base src');

// A source that IS in the allowlist but too big to handle inside the budget
// must also be refused off the header, before a decode. This is the case the
// bomb above cannot prove — there the width was wrong, so nothing was reached.
$budget = run(__DIR__ . '/_img_route.php', [], 'src=/uploads/_bomb.png&w=960');
contains($budget, 'status=404', 'a source over the memory budget is refused at an allowlisted width too');
preg_match('/peak_growth=(\d+)/', $budget, $mb);
ok(isset($mb[1]) && (int) $mb[1] < 4 * 1024 * 1024,
    'and grew peak memory by ' . (int) ($mb[1] ?? -1) . ' bytes — refused off the header, not after a decode');

unlink($bomb);

section('The encoder produces real derivatives, once, atomically');

// The repository's own uploads, not this process's content copy - and this is
// the one section that has to be. What follows fetches the same file back over
// HTTPS to exercise the R2 adapter, and the web server resolves /uploads/
// against the repository. So point this Cms at the same directory the fixture
// is written into, or the local encoder looks somewhere the file is not.
$uploads = $root . '/content/uploads';
$mediaCfg = $cms->config;
$mediaCfg['paths']['uploads'] = $uploads;
$cms = new Cms($mediaCfg);
@mkdir($uploads, 0775, true);

// content/uploads/ is tracked in git now, so a run that dies half way through
// must not leave a fixture behind for the backup job to commit. The unlinks at
// the end of this section are the normal path; this is the one that survives an
// exception.
register_shutdown_function(static function () use ($uploads): void {
    array_map('unlink', glob($uploads . '/_derive.*') ?: []);
});

// An opaque JPEG and a PNG with a genuinely transparent corner.
$jpegSrc = $uploads . '/_derive.jpg';
$im = imagecreatetruecolor(1200, 800);
imagefilledrectangle($im, 0, 0, 1199, 799, imagecolorallocate($im, 200, 60, 60));
imagejpeg($im, $jpegSrc, 90);
imagedestroy($im);

$pngSrc = $uploads . '/_derive.png';
$im = imagecreatetruecolor(1200, 800);
imagealphablending($im, false);
imagesavealpha($im, true);
imagefilledrectangle($im, 0, 0, 1199, 799, imagecolorallocatealpha($im, 0, 0, 0, 127));
imagepng($im, $pngSrc);
imagedestroy($im);

$cacheDir = $cms->config['paths']['cache'] . '/images';
array_map('unlink', glob($cacheDir . '/*') ?: []);

$derive = static fn (string $src, string $format = 'auto'): ?array => Media::encode(
    $cms->config,
    Media::spec($cms->config['images'], $bases, ['src' => $src, 'w' => '640', 'f' => $format])
);

$webp = $derive('/uploads/_derive.jpg', 'webp');
ok($webp !== null && is_file($webp['path']), 'a local upload encodes to a derivative on disk');
ok(($webp['mime'] ?? '') === 'image/webp', 'WebP is produced when it is asked for');
$dim = @getimagesize($webp['path']);
ok($dim[0] === 640, 'at exactly the requested width (' . $dim[0] . 'px)');
ok($dim[1] === 427, 'with the aspect ratio preserved (' . $dim[1] . 'px tall)');

$jpeg = $derive('/uploads/_derive.jpg');
ok(($jpeg['mime'] ?? '') === 'image/jpeg', 'an opaque source falls back to JPEG');

// The fallback for a browser without WebP has to keep alpha, or every logo on
// the site grows a black box.
$png = $derive('/uploads/_derive.png');
ok(($png['mime'] ?? '') === 'image/png', 'a source with transparency falls back to PNG instead');
$alpha = imagecreatefrompng($png['path']);
// Near-127 rather than exactly 127: resampling rounds. The failure this
// catches is 0 — a flattened black rectangle, which is what came out before
// the alpha flags moved to before the scale.
$a = imagecolorat($alpha, 5, 5) >> 24 & 0x7F;
ok($a >= 120, 'and the transparency survives the round trip (alpha ' . $a . '/127, not flattened to 0)');
imagedestroy($alpha);

// Keyed by source content hash: the same bytes at the same width and format
// are the same file, and a request for one that already exists re-uses it.
$again = $derive('/uploads/_derive.jpg', 'webp');
ok($again['path'] === $webp['path'], 'the same src/width/format resolves to the same cache entry');
ok(count(glob($cacheDir . '/*.webp') ?: []) === 1, 'and encoding it twice leaves one file, not two');
ok(glob($cacheDir . '/*.lock') === [], 'the per-key lock is cleaned up after the encode');
ok(glob($cacheDir . '/*.tmp') === [], 'and no partial file is left behind — the rename is atomic');

// Concurrent misses: four processes, one cold cache, one valid derivative.
array_map('unlink', glob($cacheDir . '/*') ?: []);
$procs = [];
for ($i = 0; $i < 4; $i++) {
    $procs[] = popen('env ' . escapeshellarg(PHP_BINARY) . ' '
        . escapeshellarg(__DIR__ . '/_img_route.php') . ' '
        . escapeshellarg('src=/uploads/_derive.jpg&w=960&f=webp') . ' 2>&1', 'r');
}
$results = array_map(static fn ($p): string => (string) stream_get_contents($p), $procs);
array_map('pclose', $procs);

ok(count(array_filter($results, static fn (string $r): bool => str_contains($r, 'status=200'))) === 4,
    'four concurrent misses all get a 200');
ok(count(glob($cacheDir . '/*.webp') ?: []) === 1, 'and produce exactly one derivative between them');
ok(@getimagesize(glob($cacheDir . '/*.webp')[0])[0] === 960, 'which is a valid, complete image');

section('The R2 source adapter reads over HTTPS, and only over HTTPS');
// The same bytes, reached the other way: config points media_bases and the R2
// public base at this site's own HTTPS origin, so the fetch path runs for real
// — redirects off, byte cap, timeout and all — without a bucket.
if ($liveHttp) {
    $r2cfg = $cms->config;
    $r2cfg['r2']['public_base'] = $testBaseUrl;
    $r2cms = new Cms($r2cfg);
    $r2bases = $r2cms->fieldContext()['media_bases'];
    $remote = $testBaseUrl . '/uploads/_derive.jpg';

    ok(in_array($testBaseUrl . '/', $r2bases, true), 'the R2 public base is appended to media_bases when configured');

    $viaR2 = Media::encode(
        $r2cfg,
        Media::spec($r2cfg['images'], $r2bases, ['src' => $remote, 'w' => '640', 'f' => 'webp'])
    );
    ok($viaR2 !== null && is_file($viaR2['path']), 'a src under the R2 base is fetched and encoded');
    ok(@getimagesize($viaR2['path'])[0] === 640, 'to the same width the local adapter produces');

    // Content-addressed: the same bytes reached through either adapter are the same
    // derivative, so switching R2_ENABLED does not invalidate a cache.
    ok(basename($viaR2['path']) === basename($webp['path']),
        'and to the same cache entry, because the key is the source content hash');

    $plain = str_replace('https://', 'http://', $remote);
    $httpBases = array_map(static fn (string $b): string => str_replace('https://', 'http://', $b), $r2bases);
    $httpCfg = $r2cfg;
    $httpCfg['r2']['public_base'] = str_replace('https://', 'http://', $testBaseUrl);
    ok(Media::encode($httpCfg, Media::spec($httpCfg['images'], $httpBases, ['src' => $plain, 'w' => '640'])) === null,
        'plain HTTP is refused: this runs on an anonymous GET and a downgraded fetch is not one we made');

    ok(Media::spec($r2cfg['images'], $r2bases, ['src' => 'https://evil.tld/uploads/x.jpg', 'w' => '640']) === null,
        'and a host that is not the configured base never reaches the adapter at all');
}

section('AVIF is refused on the way in as well as on the way out');
ok(!in_array('image/avif', $cms->config['images']['allowed'], true), 'avif is not an accepted upload type');
ok(!in_array('avif', $d['formats'], true), 'nor an output format');
ok(!in_array('image/avif', $d['decodable'], true), 'nor a source we will decode');

unlink($jpegSrc);
unlink($pngSrc);
array_map('unlink', glob($cacheDir . '/*') ?: []);

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

$forgottenFlag = $private;
unset($forgottenFlag['private']);
$forced = implode("\n", $cms->cacheHeaders($forgottenFlag));
contains($forced, 'no-store, private', 'the runtime detects a form and stays private even if its YAML flag was forgotten');
missing($forced, 's-maxage', 'so a developer typo can never publish a session-bound CSRF token to the edge');

$bh = implode("\n", $cms->cacheHeaders($public));
contains($bh, 's-maxage=31536000', 'a public page still goes to the edge for a year');
contains($bh, 'Cache-Tag: page:home,site', 'and still carries its purge tags');

// Over real HTTP, through nginx, as Cloudflare would see it.
$curl = static fn (string $path): string => (string) shell_exec(
    'curl -sI ' . escapeshellarg($testBaseUrl . $path) . ' 2>&1'
);
if ($liveHttp) {
    $live = $curl('/epikoinonia');
    ok(str_contains($live, '200'), 'the contact page serves 200 over HTTP');
    ok(stripos($live, 'no-store') !== false, 'and the live response really is no-store');
    ok(stripos($live, 's-maxage') === false, 'the live response offers the edge nothing');
    ok(stripos($curl('/'), 's-maxage=31536000') !== false, 'while the home page is still edge-cached for a year');
}

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
    'USERS_FILE'    => $roles,
];

contains(run(__DIR__ . '/_boot.php', $safe), 'BOOTED', 'a correctly configured production box boots');

$m1 = run(__DIR__ . '/_boot.php', ['AUTH_MODE' => 'none'] + $safe);
contains($m1, 'REFUSED', 'AUTH_MODE=none is refused');
contains($m1, 'wide open', 'and the refusal says why');

contains(run(__DIR__ . '/_boot.php', ['AUTH_DEV_BYPASS' => '1'] + $safe), 'REFUSED', 'AUTH_DEV_BYPASS=1 is refused');
contains(run(__DIR__ . '/_boot.php', ['CF_ACCESS_AUD' => ''] + $safe), 'REFUSED', 'an empty Access audience is refused');
contains(run(__DIR__ . '/_boot.php', ['USERS_FILE' => $root . '/var/cache/nope.yml'] + $safe), 'REFUSED', 'a missing users file is refused');

// All four at once must report all four, not just the first.
$all = run(__DIR__ . '/_boot.php', [
    'APP_ENV' => 'prod', 'AUTH_MODE' => 'none', 'AUTH_DEV_BYPASS' => '1',
    'CF_ACCESS_AUD' => '', 'USERS_FILE' => $root . '/var/cache/nope.yml',
]);
ok(substr_count($all, "\n  - ") === 4, 'a fully broken box reports all four problems at once, not one per deploy');

// And the guard is inert outside production, or nobody could develop.
contains(run(__DIR__ . '/_boot.php', ['AUTH_MODE' => 'none', 'AUTH_DEV_BYPASS' => '1']), 'BOOTED', 'the guard does not fire when APP_ENV is not prod');

unlink($roles);

summary();
