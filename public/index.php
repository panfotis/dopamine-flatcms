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
    // Which language, from the URL prefix, before anything is looked up. The
    // default language's prefix is empty, so an existing single-language site's
    // URLs resolve exactly as they did.
    [$locale, $path] = $cms->localeOf($slug);
    $cms->useLocale($locale);

    $page = $cms->content->findBySlug($path);

    // No translation at this address. `fallback: default` serves the default
    // language's page rather than a dead end — rendered *as* that language, so
    // its own URL is the canonical one and the menu is not half-translated.
    if ($page === null && $cms->locales()[$locale]['fallback'] === 'default') {
        $fallbackPage = $cms->contentIn($cms->defaultLocale())->findBySlug($path);
        if ($fallbackPage !== null) {
            $cms->useLocale($cms->defaultLocale());
            $page = $fallbackPage;
        }
    }

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
        // A page carrying a form is the only public page with per-visitor
        // state. It is `private: true`, so it is never edge-cached and this
        // token is genuinely this visitor's — the Phase 0 caching decision
        // exists precisely so this line is correct.
        $form = new Dopamine\FlatCms\Form($cms);
        $extra = [];

        if ($form->blockOn($page) !== null) {
            session_start([
                'cookie_httponly' => true,
                'cookie_samesite' => 'Lax',
                'cache_limiter'   => '',
                'cookie_secure'   => $request->isSecure()
                    || $request->headers->get('X-Forwarded-Proto') === 'https',
            ]);

            $result = ['ok' => false, 'errors' => [], 'values' => []];

            if ($request->isMethod('POST')) {
                $result = $form->handle($request, $page);

                if ($result['ok']) {
                    // POST/redirect/GET: a refresh after sending must not send
                    // again, and the back button must not re-post.
                    $response = new RedirectResponse(
                        $cms->localeUrl((string) $page['slug']) . '?sent=1',
                        303,
                        ['Cache-Control' => 'no-store, private']
                    );
                    $response->send();

                    exit;
                }
            }

            // A new token and a new clock on every render, including the
            // re-render after a refusal: the minimum time-to-submit measures
            // how long *this* form was on screen.
            $_SESSION['form_csrf'] = $_SESSION['form_csrf'] ?? bin2hex(random_bytes(16));
            $_SESSION['form_opened_at'] = time();

            $extra = [
                'form_csrf'   => $_SESSION['form_csrf'],
                'form_inputs' => $form->inputs(
                    $cms->components->get((string) $form->blockOn($page)['type']) ?? []
                ),
                'form_errors' => $result['errors'],
                'form_values' => $result['values'],
                'form_sent'   => $request->query->get('sent') === '1',
                'turnstile_site_key' => (string) $cms->config['turnstile']['site_key'],
            ];
        }

        $response = new Response(
            $cms->renderPage($page, $extra),
            // A refused submission is not a successful page view. 422 rather
            // than 200 so a monitor can tell "the form is rejecting everyone"
            // from "the page is fine".
            ($extra !== [] && $extra['form_errors'] !== []) ? 422 : 200,
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
