<?php

declare(strict_types=1);

use Dopamine\FlatCms\Cms;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

require dirname(__DIR__) . '/vendor/autoload.php';

$cms = new Cms(require dirname(__DIR__) . '/config.php');

$request = Request::createFromGlobals();
$slug = $request->getPathInfo();
$page = $cms->content->findBySlug($slug);

if ($page === null) {
    $response = new Response(
        $cms->twig->render('404.twig', ['slug' => $slug]),
        404,
        ['Content-Type' => 'text/html; charset=utf-8', 'Cache-Control' => 'no-store']
    );
} else {
    $response = new Response(
        $cms->renderPage($page),
        200,
        ['Content-Type' => 'text/html; charset=utf-8']
    );

    foreach ($cms->cacheHeaders($page) as $line) {
        [$name, $value] = explode(': ', $line, 2);
        $response->headers->set($name, $value);
    }
}

$response->send();
