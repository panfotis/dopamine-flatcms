<?php
/**
 * Local image derivatives — the fallback used when CF_IMAGES_ENABLED is off.
 * With Cloudflare on, /cdn-cgi/image does this job and this file never runs.
 *
 *   /img.php?src=/uploads/2026/08/a.jpg&w=960&f=auto
 *
 * Every parameter is validated against a finite allowlist first, so a bad
 * request costs a stat of this file and nothing else — no source read, no
 * decode, no memory. Only once spec() has said yes does anything expensive
 * become reachable.
 */

declare(strict_types=1);

use Dopamine\FlatCms\Cms;
use Dopamine\FlatCms\Media;

require dirname(__DIR__) . '/vendor/autoload.php';

$cms  = new Cms(require dirname(__DIR__) . '/config.php');
$spec = Media::spec(
    $cms->config['images'],
    $cms->fieldContext()['media_bases'],
    $_GET,
    (string) ($_SERVER['HTTP_ACCEPT'] ?? '')
);

$derivative = $spec === null ? null : Media::encode($cms->config, $spec);

if ($derivative === null) {
    // no-store: a rejection must never occupy a cache entry an attacker chose.
    // A source that is missing, undecodable or too big to handle is a 404 for
    // the same reason a bad width is — there is nothing here, and saying more
    // would describe the filesystem to whoever asked.
    http_response_code(404);
    header('Cache-Control: no-store');
    header('Content-Type: text/plain; charset=utf-8');
    echo "Not found\n";
    exit;
}

// Immutable: a derivative is a pure function of src + width + format, and the
// src path itself changes whenever the image does.
http_response_code(200);
header('Content-Type: ' . $derivative['mime']);
header('Cache-Control: public, max-age=' . $spec['max_age'] . ', immutable');
header('Content-Length: ' . filesize($derivative['path']));
readfile($derivative['path']);
