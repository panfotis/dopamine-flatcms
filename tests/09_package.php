<?php
/**
 * Composer split — prove the skeleton works with a mirrored vendor package.
 *
 * This deliberately installs into an empty temporary directory. Rendering the
 * source checkout cannot reveal hard-coded root paths or a missing Composer
 * bootstrap, which are exactly the failures this boundary is meant to catch.
 */

declare(strict_types=1);

require __DIR__ . '/lib.php';

$root = dirname(__DIR__);

/** @param non-empty-string $path */
function packageRemove(string $path): void
{
    if (is_link($path) || is_file($path)) {
        unlink($path);
        return;
    }
    if (!is_dir($path)) {
        return;
    }
    foreach (new FilesystemIterator($path) as $item) {
        packageRemove($item->getPathname());
    }
    rmdir($path);
}

/** @param non-empty-string $from @param non-empty-string $to */
function packageCopy(string $from, string $to): void
{
    mkdir($to, 0775, true);
    foreach (new FilesystemIterator($from) as $item) {
        $target = $to . '/' . $item->getBasename();
        if ($item->isDir() && !$item->isLink()) {
            packageCopy($item->getPathname(), $target);
        } else {
            copy($item->getPathname(), $target);
            chmod($target, fileperms($item->getPathname()) & 0777);
        }
    }
}

/** @return array{0:int,1:string} */
function packageRun(string $cwd, string $command): array
{
    $lines = [];
    $status = 0;
    exec('cd ' . escapeshellarg($cwd) . ' && ' . $command . ' 2>&1', $lines, $status);
    return [$status, implode("\n", $lines)];
}

section('The skeleton installs the engine as a real Composer dependency');

$target = sys_get_temp_dir() . '/dopamine-package-' . bin2hex(random_bytes(5));
register_shutdown_function(static fn () => packageRemove($target));
packageCopy($root . '/skeleton', $target);

// Portable mode intentionally clears HOME so tests cannot inherit a
// developer's credentials or configuration. Composer requires either HOME or
// COMPOSER_HOME, so give this package test its own disposable, isolated home.
$composerHome = $target . '/.composer';
$composerEnv = 'COMPOSER_HOME=' . escapeshellarg($composerHome) . ' ';

$manifestFile = $target . '/composer.json';
$manifest = json_decode((string) file_get_contents($manifestFile), true, flags: JSON_THROW_ON_ERROR);
$manifest['repositories'] = [[
    'type' => 'path',
    'url' => $root,
    'options' => [
        'symlink' => false,
        'versions' => ['dopamine/flatcms' => '1.0.0'],
    ],
]];
file_put_contents(
    $manifestFile,
    json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n"
);

[$installStatus, $installOutput] = packageRun(
    $target,
    $composerEnv . 'composer update --no-interaction --no-progress --prefer-dist'
);
ok($installStatus === 0, 'Composer resolves the skeleton in an empty directory: ' . $installOutput);

$engine = $target . '/vendor/dopamine/flatcms';
ok(is_file($engine . '/src/Cms.php'), 'the engine is installed under vendor/dopamine/flatcms');
ok(!is_link($engine), 'the test uses a mirror, not a symlink back to this checkout');
ok(!is_dir($target . '/src'), 'the runnable site does not contain an engine source copy');

section('The engine archive contains engine code only');

$archiveDir = $target . '/artifact';
mkdir($archiveDir, 0775, true);
[$archiveStatus, $archiveOutput] = packageRun(
    $root,
    $composerEnv . 'COMPOSER_ROOT_VERSION=1.0.0 composer archive --format=zip --dir=' . escapeshellarg($archiveDir)
);
ok($archiveStatus === 0, 'Composer can build the distributable archive: ' . $archiveOutput);

$archives = glob($archiveDir . '/*.zip') ?: [];
$listStatus = 1;
$listOutput = '';
if (isset($archives[0])) {
    [$listStatus, $listOutput] = packageRun($target, 'unzip -Z1 ' . escapeshellarg($archives[0]));
}
ok($listStatus === 0, 'the generated package is a readable zip archive');
if ($listStatus === 0) {
    $names = preg_split('/\R/', trim($listOutput)) ?: [];
    ok(in_array('src/Cms.php', $names, true) && in_array('theme/components/hero/schema.yml', $names, true),
        'engine classes and starter components are included');
    ok(!in_array('config.php', $names, true)
        && !array_filter($names, static fn (string $name): bool => preg_match('#^(content|public|skeleton|vendor|PENS)/#', $name) === 1
            || preg_match('#^dopamine-flatcms-.*\.md$#', $name) === 1),
        'site state, entrypoints, skeleton and installed dependencies are excluded');
}

section('The two exclusion lists cannot drift');

// composer.json's archive.exclude governs `composer archive`; .gitattributes'
// export-ignore governs the GitHub zipball Packagist actually serves. v0.1.0
// shipped 4.8 MB of demo content because only the first existed — this pins
// every exclude entry to a matching export-ignore line.
$attributes = (string) file_get_contents($root . '/.gitattributes');
$excludes = json_decode((string) file_get_contents($root . '/composer.json'), true)['archive']['exclude'];
$missing = [];
foreach ($excludes as $entry) {
    $bare = ltrim((string) $entry, '/');
    if (in_array($bare, ['vendor', 'var'], true)) {
        continue; // never tracked, so never in a zipball to begin with
    }
    if (!str_contains($attributes, $bare)) {
        $missing[] = $bare;
    }
}
ok($missing === [], 'every archive.exclude entry has an export-ignore twin: ' . implode(', ', $missing));

section('Package fallbacks and site overrides both render');

$probe = <<<'PHP'
require 'vendor/autoload.php';
$cms = new Dopamine\FlatCms\Cms(require 'config.php');
$page = $cms->content->findBySlug('/');
$html = $cms->renderPage($page);
if (!str_contains($html, 'Your small site, ready to edit')) { exit(20); }
$response = (new Dopamine\FlatCms\Admin($cms))->handle(
    Symfony\Component\HttpFoundation\Request::create('/admin.php')
);
if ($response->getStatusCode() !== 200 || !str_contains($response->getContent(), 'Pages')) { exit(21); }
PHP;
[$probeStatus, $probeOutput] = packageRun(
    $target,
    'AUTH_DEV_BYPASS=1 php -r ' . escapeshellarg($probe)
);
ok($probeStatus === 0, 'the demo page and panel render through package templates: ' . $probeOutput);

$localComponent = $target . '/theme/components/hero';
mkdir($localComponent, 0775, true);
file_put_contents($localComponent . '/schema.yml', "label: Local hero\nfields:\n  heading:\n    type: text\n");
file_put_contents($localComponent . '/hero.twig', '<strong>LOCAL {{ fields.heading }}</strong>');

$overrideProbe = <<<'PHP'
require 'vendor/autoload.php';
$cms = new Dopamine\FlatCms\Cms(require 'config.php');
$html = $cms->renderPage($cms->content->findBySlug('/'));
if (!str_contains($html, '<strong>LOCAL Your small site, ready to edit</strong>')) {
    fwrite(STDERR, $html);
    exit(22);
}
PHP;
[$overrideStatus, $overrideOutput] = packageRun(
    $target,
    'AUTH_DEV_BYPASS=1 php -r ' . escapeshellarg($overrideProbe)
);
ok($overrideStatus === 0, 'a site-owned component completely overrides the package starter: ' . $overrideOutput);

section('Installed CLI tools operate on the consuming site');

[$doctorStatus, $doctorOutput] = packageRun($target, 'AUTH_DEV_BYPASS=1 bin/doctor --quiet');
ok($doctorStatus === 0, 'the site doctor wrapper reads its config and package roots: ' . $doctorOutput);

packageRemove($target);
summary();
