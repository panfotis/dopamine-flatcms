<?php

declare(strict_types=1);

use Dopamine\FlatCms\Cms;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

require dirname(__DIR__) . '/vendor/autoload.php';
require dirname(__DIR__) . '/src/bootstrap.php';

$cms = new Cms(require dirname(__DIR__) . '/config.php');

// A Twig error after a schema rename used to be a white page with a PHP fatal
// on a live client site. Now it is 500.twig, and the detail goes to the log
// where it belongs rather than to whoever happened to be reading.
Dopamine\FlatCms\bootstrap_error_handler($cms);

$request = Request::createFromGlobals();
$slug = $request->getPathInfo();
$page = null;

// Both are generated from the same content files the pages are, and both are
// cross-page output — so both carry the `site` cache tag and never page:<id>.
// A sitemap tagged per page is never purged, because the page that changed is
// never the sitemap. match() only evaluates the arm it matches, so an ordinary
// request builds neither.
$feed = match ($slug) {
    '/sitemap.xml' => ['application/xml; charset=utf-8', $cms->sitemap()],
    '/robots.txt'  => ['text/plain; charset=utf-8', $cms->robotsTxt()],
    default        => null,
};

if ($feed !== null) {
    $response = new Response($feed[1], 200, ['Content-Type' => $feed[0]]);
} else {
    $page = $cms->content->findBySlug($slug);

    if ($page === null) {
        // Before the 404, never after: nearly every one of these sites replaces
        // an existing one, and the old URLs are the client's search rankings.
        $to = $cms->redirectFor($slug);

        $response = $to !== null
            ? new RedirectResponse($to, 301, ['Cache-Control' => 'public, max-age=3600'])
            : new Response(
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
    }
}

// The 404 and the redirect set their own; everything else takes the site policy.
if ($feed !== null || $page !== null) {
    foreach ($cms->cacheHeaders($page ?? []) as $line) {
        [$name, $value] = explode(': ', $line, 2);
        $response->headers->set($name, $value);
    }
}

// A pre-launch domain must not be indexed — including its 404s and redirects,
// which is why this sits after the branch rather than inside it.
$robots = $cms->robotsHeader();
if ($robots !== null) {
    $response->headers->set('X-Robots-Tag', $robots);
}

$response->send();
