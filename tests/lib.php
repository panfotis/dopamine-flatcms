<?php

declare(strict_types=1);

use Dopamine\FlatCms\Admin;
use Dopamine\FlatCms\Cms;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

require dirname(__DIR__) . '/vendor/autoload.php';

// Several checks deliberately trigger the admin error path, which error_log()s.
// In-process that lands on stderr and reads like a broken suite, so park it in
// a file instead — still inspectable, no longer mixed into the results.
ini_set('error_log', dirname(__DIR__) . '/var/cache/test-errors.log');

$GLOBALS['__fails'] = 0;
$GLOBALS['__passes'] = 0;

function ok(bool $cond, string $label): void
{
    if ($cond) {
        $GLOBALS['__passes']++;
        echo "  \033[32m✓\033[0m {$label}\n";
    } else {
        $GLOBALS['__fails']++;
        echo "  \033[31m✗ {$label}\033[0m\n";
    }
}

function contains(string $haystack, string $needle, string $label): void
{
    ok(str_contains($haystack, $needle), $label);
}

function missing(string $haystack, string $needle, string $label): void
{
    ok(!str_contains($haystack, $needle), $label);
}

function section(string $name): void
{
    echo "\n\033[1m{$name}\033[0m\n";
}

function summary(): void
{
    $f = $GLOBALS['__fails'];
    $p = $GLOBALS['__passes'];
    echo "\n" . ($f === 0
        ? "\033[32mAll {$p} checks passed.\033[0m\n"
        : "\033[31m{$f} of " . ($p + $f) . " checks FAILED.\033[0m\n");
    exit($f === 0 ? 0 : 1);
}

/**
 * A private copy of tests/fixtures/content/, made once per process.
 *
 * The suite saves hostile input, restores revisions and forces stale-baseline
 * conflicts - all of which write. Pointed at the repository's own content/ it
 * corrupted real files whenever a test failed before its restore ran, and the
 * revisions it wrote accumulated as untracked files in a tracked directory.
 * A per-process copy under var/cache/ cannot do either.
 */
function content_root(): string
{
    static $dir = null;
    if ($dir !== null) {
        return $dir;
    }

    $dir = dirname(__DIR__) . '/var/cache/content-' . getmypid();
    exec('rm -rf ' . escapeshellarg($dir));
    mkdir($dir, 0775, true);
    exec('cp -R ' . escapeshellarg(__DIR__ . '/fixtures/content/pages') . ' ' . escapeshellarg($dir . '/pages'));
    copy(__DIR__ . '/fixtures/content/redirects.yml', $dir . '/redirects.yml');
    mkdir($dir . '/uploads', 0775, true);
    register_shutdown_function(static function () use ($dir): void {
        exec('rm -rf ' . escapeshellarg($dir));
    });

    return $dir;
}

/**
 * The engine config, pointed at this process's content copy.
 *
 * @return array<string, mixed>
 */
function test_config(): array
{
    $config = require dirname(__DIR__) . '/config.php';
    $config['paths']['content'] = content_root();
    $config['paths']['uploads'] = content_root() . '/uploads';
    // Inline delivery by default, deliberately: inlining is the production
    // fallback whenever bundles cannot be written, so every assertion in the
    // suite that reads CSS or JS out of the rendered HTML is real coverage of
    // that path. 10_assets.php opts into bundles with its own temp directory.
    unset($config['paths']['public_assets']);

    return $config;
}

function cms(): Cms
{
    return new Cms(test_config());
}

/**
 * Run one public request in-process, exactly as public/index.php does.
 *
 * Phase 1 exists so this is possible. The front controller used to echo and
 * exit, so its *composition* — the order of the decisions, and which of them
 * skipped the headers at the bottom of the file — could only be asserted by
 * grepping a subprocess's stdout, and never was. Here the status, the headers
 * and the body are all first-class, the same way admin() made them.
 *
 * @param array<string, mixed>|null $config
 */
function site(Request $request, ?array $config = null): Response
{
    return (new Dopamine\FlatCms\Site(new Cms($config ?? test_config())))->handle($request);
}

/**
 * @param array<string, mixed>|null $config
 */
function site_get(string $uri, ?array $config = null): Response
{
    return site(Request::create($uri, 'GET'), $config);
}

/**
 * Run one admin request in-process, exactly as public/admin.php does.
 *
 * Phase 2 exists so this is possible: handle() returns a Response instead of
 * echoing and exiting, which retired five subprocess helper scripts. A child
 * process could only ever be asserted on by grepping its stdout; here the
 * status code, the headers and the body are all first-class.
 *
 * $config overrides the live config — that is how the unauthenticated cases
 * get a production auth setup without touching this process's environment.
 *
 * @param array<string, mixed>|null $config
 */
function admin(Request $request, ?array $config = null): Response
{
    return (new Admin(new Cms($config ?? test_config())))->handle($request);
}

/**
 * @param array<string, mixed>      $query
 * @param array<string, mixed>|null $config
 */
function admin_get(array $query, ?array $config = null): Response
{
    return admin(Request::create('/admin.php', 'GET', $query), $config);
}

/**
 * @param array<string, mixed>      $body
 * @param array<string, mixed>|null $config
 */
function admin_post(array $body, ?array $config = null): Response
{
    return admin(Request::create('/admin.php', 'POST', $body), $config);
}

/**
 * A working Cloudflare Access setup: a throwaway RSA keypair, the JWKS Access
 * would serve dropped into a private cert cache, and a config pointed at it.
 *
 * Phase 3 turned "who are you" into a second question — "and may you be here" —
 * and the only honest way to ask it is with a token Auth really verifies. A
 * config knob that faked an identity would be precisely the backdoor users.yml
 * exists to prevent, and a test that used one would prove nothing about the
 * path a real request takes.
 *
 * @return array{0: array<string, mixed>, 1: callable(string): string}
 */
function access(): array
{
    static $ready = null;

    if ($ready !== null) {
        return $ready;
    }

    $dir = dirname(__DIR__) . '/var/cache/access-test-' . bin2hex(random_bytes(4));
    mkdir($dir, 0775, true);

    $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    openssl_pkey_export($key, $pem);
    $rsa = openssl_pkey_get_details($key)['rsa'];

    $b64 = static fn (string $v): string => rtrim(strtr(base64_encode($v), '+/', '-_'), '=');

    // Written straight into the cert cache with a fresh mtime, so certs() finds
    // it valid and never reaches the network.
    file_put_contents($dir . '/access-certs.json', json_encode(['keys' => [[
        'kty' => 'RSA',
        'kid' => 'test-key',
        'alg' => 'RS256',
        'use' => 'sig',
        'n'   => $b64($rsa['n']),
        'e'   => $b64($rsa['e']),
    ]]], JSON_THROW_ON_ERROR));

    $config = test_config();
    $config['auth']['mode']        = 'cf_access';
    $config['auth']['dev_bypass']  = false;          // the production setting
    $config['auth']['aud']         = str_repeat('a', 64);
    $config['auth']['team_domain'] = 'test-team.cloudflareaccess.com';
    $config['paths']['cache']      = $dir;

    $sign = static fn (string $email): string => \Firebase\JWT\JWT::encode([
        'aud'   => [$config['auth']['aud']],
        'iss'   => 'https://' . $config['auth']['team_domain'],
        'email' => $email,
        'iat'   => time(),
        'exp'   => time() + 600,
    ], $pem, 'RS256', 'test-key');

    register_shutdown_function(static function () use ($dir): void {
        array_map('unlink', glob($dir . '/*') ?: []);
        @rmdir($dir);
    });

    return $ready = [$config, $sign];
}

/**
 * One panel request carrying a genuine Access token for $email.
 *
 * @param array<string, mixed> $params
 * @param array<string, mixed> $auth   overrides merged over config.auth, so a
 *                                     test can point Auth at a crafted roles
 *                                     file without a second signing setup
 */
function as_user(string $email, string $method = 'GET', array $params = [], array $auth = []): Response
{
    [$config, $sign] = access();
    $config['auth'] = $auth + $config['auth'];

    return admin(
        Request::create('/admin.php', $method, $params, [], [], [
            'HTTP_CF_ACCESS_JWT_ASSERTION' => $sign($email),
        ]),
        $config
    );
}
