<?php
/**
 * Local image derivatives — the fallback used when CF_IMAGES_ENABLED is off.
 * With Cloudflare on, /cdn-cgi/image does this job and this file never runs.
 *
 *   /img.php?src=/uploads/2026/08/a.jpg&w=960&f=auto
 *
 * Phase 0 ships the contract and the rejection path only. Every parameter is
 * validated against a finite allowlist first, so a bad request costs a stat of
 * this file and nothing else — no source read, no decode, no memory.
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

if ($spec === null) {
    // no-store: a rejection must never occupy a cache entry an attacker chose.
    http_response_code(404);
    header('Cache-Control: no-store');
    header('Content-Type: text/plain; charset=utf-8');
    echo "Not found\n";
    exit;
}

// ponytail: the encoder is Phase 4. The contract above is what it must satisfy.
http_response_code(501);
header('Cache-Control: no-store');
header('Content-Type: text/plain; charset=utf-8');
echo "Derivative encoder not implemented yet (Phase 4).\n";
