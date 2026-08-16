<?php

declare(strict_types=1);

use Dopamine\FlatCms\Cms;

require dirname(__DIR__) . '/vendor/autoload.php';

$cms = new Cms(require dirname(__DIR__) . '/config.php');

$slug = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$page = $cms->content->findBySlug($slug);

if ($page === null) {
    http_response_code(404);
    header('Cache-Control: no-store');
    echo $cms->twig->render('404.twig', ['slug' => $slug]);
    exit;
}

header('Content-Type: text/html; charset=utf-8');
$cms->sendCacheHeaders($page);

echo $cms->renderPage($page);
