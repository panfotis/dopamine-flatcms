<?php

declare(strict_types=1);

namespace Dopamine\FlatCms;

use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The public site: one request in, one Response out.
 *
 * This used to be the body of `public/index.php`. Every *ingredient* was
 * tested — canonicalPath(), localeOf(), cacheHeaders(), robotsHeader() all had
 * coverage — but the **composition** had none, because a front controller that
 * echoes and exits can only be asserted on by grepping a subprocess's stdout.
 * That gap was not theoretical: with SITE_NOINDEX set, the trailing-slash 301
 * and the post-submit 303 both `send(); exit;`-ed before the X-Robots-Tag line
 * at the bottom of the file ever ran, so a pre-launch domain's redirects were
 * indexable. Returning a Response instead of exiting fixes both by
 * construction, and the same move had already retired five subprocess helpers
 * on the admin side.
 *
 * A second copy of the same 168 lines lived in `skeleton/public/index.php`, and
 * the two had already drifted — the repo's own copy had gained a branded 404
 * and Cms::sessionOptions(), the skeleton's had not. A site's front controller
 * is now eleven lines that cannot drift, and this file arrives by
 * `composer update`.
 *
 * handle() never calls send() and never exits. That is the whole point, and it
 * is also the seam the deferred `routes` config key will hang off.
 */
final class Site
{
    public function __construct(private readonly Cms $cms)
    {
    }

    public function handle(Request $request): Response
    {
        $slug = $request->getPathInfo();

        $feed = $this->feedFor($slug);
        $page = null;

        if ($feed !== null) {
            $response = $feed;
        } else {
            // Which language, from the URL prefix, before anything is looked
            // up. The default language's prefix is empty, so a single-language
            // site's URLs resolve exactly as they always did.
            [$locale, $path] = $this->cms->localeOf($slug);
            $this->cms->useLocale($locale);

            $redirect = $this->redirectToCanonical($request, $slug);
            if ($redirect !== null) {
                // Not an early return: a pre-launch domain's redirects must
                // carry X-Robots-Tag too, and that is applied below. Returning
                // here is exactly the bug this class was written to remove.
                $response = $redirect;
            } else {
                $page = $this->findPage($path);

                $response = $page === null
                    ? $this->missing($slug)
                    : $this->renderPage($request, $page);
            }
        }

        // The 404 and the redirects set their own; everything else takes the
        // site policy. A page that carries a form is forced private by
        // cacheHeaders() itself, so a CSRF token can never reach the edge.
        if ($feed !== null || $page !== null) {
            foreach ($this->cms->cacheHeaders($page ?? []) as $line) {
                [$name, $value] = explode(': ', $line, 2);
                $response->headers->set($name, $value);
            }
        }

        // A pre-launch domain must not be indexed — including its 404s and
        // every one of its redirects, which is why this sits after the branch
        // rather than inside it.
        $robots = $this->cms->robotsHeader();
        if ($robots !== null) {
            $response->headers->set('X-Robots-Tag', $robots);
        }

        return $response;
    }

    /**
     * The three generated files.
     *
     * Both sitemaps and robots.txt are built from the same content files the
     * pages are, and all are cross-page output — so they carry the `site` cache
     * tag and never page:<id>. A sitemap tagged per page is never purged,
     * because the page that changed is never the sitemap. match() only
     * evaluates the arm it matches, so an ordinary request builds none of them.
     */
    private function feedFor(string $slug): ?Response
    {
        $feed = match ($slug) {
            '/sitemap.xml' => ['application/xml; charset=utf-8', $this->cms->sitemap(...)],
            '/sitemap.xsl' => ['text/xsl; charset=utf-8', $this->cms->sitemapXsl(...)],
            '/robots.txt'  => ['text/plain; charset=utf-8', $this->cms->robotsTxt(...)],
            default        => null,
        };

        return $feed === null
            ? null
            : new Response($feed[1](), 200, ['Content-Type' => $feed[0]]);
    }

    /**
     * One URL per page: /about/ 301s to /about before anything renders, so the
     * slash variant never exists as a duplicate.
     *
     * GET and HEAD only — a 301 would turn a POSTed form into a GET and drop
     * the submission on the floor.
     */
    private function redirectToCanonical(Request $request, string $slug): ?RedirectResponse
    {
        $canonical = $this->cms->canonicalPath($slug);
        if ($slug === $canonical || !$request->isMethodSafe()) {
            return null;
        }

        $qs = $request->getQueryString();

        return new RedirectResponse(
            $canonical . ($qs === null ? '' : '?' . $qs),
            301,
            ['Cache-Control' => 'public, max-age=3600']
        );
    }

    /**
     * The page at this path, in the language the URL asked for.
     *
     * No translation at this address and `fallback: default` serves the default
     * language's page rather than a dead end — rendered *as* that language, so
     * its own URL is the canonical one and the menu is not half-translated.
     *
     * @return array<string, mixed>|null
     */
    private function findPage(string $path): ?array
    {
        $page = $this->cms->content->findBySlug($path);
        if ($page !== null) {
            return $page;
        }

        if ($this->cms->locales()[$this->cms->locale()]['fallback'] !== 'default') {
            return null;
        }

        $default = $this->cms->defaultLocale();
        $fallback = $this->cms->contentIn($default)->findBySlug($path);
        if ($fallback === null) {
            return null;
        }

        $this->cms->useLocale($default);

        return $fallback;
    }

    /**
     * Nothing is here — but check the redirect map before saying so.
     *
     * Before the 404, never after: nearly every one of these sites replaces an
     * existing one, and the old URLs are the client's search rankings.
     */
    private function missing(string $slug): Response
    {
        $to = $this->cms->redirectFor($slug);

        if ($to !== null) {
            return new RedirectResponse($to, 301, ['Cache-Control' => 'public, max-age=3600']);
        }

        // renderTemplate, not twig->render: the 404 is a branded page and
        // carries the site's global CSS like any other.
        return new Response(
            $this->cms->renderTemplate('404.twig', [
                'slug' => $slug,
                'locale' => $this->cms->locale(),
                'home_url' => $this->cms->localeUrl('/'),
            ]),
            404,
            ['Content-Type' => 'text/html; charset=utf-8', 'Cache-Control' => 'no-store']
        );
    }

    /**
     * @param array<string, mixed> $page
     */
    private function renderPage(Request $request, array $page): Response
    {
        $form = new Form($this->cms);

        // A page carrying a form is the only public page with per-visitor
        // state, and it is the only one that opens a session. Preserving that
        // is what keeps every other page cacheable at the edge.
        $extra = $form->blockOn($page) === null
            ? []
            : $this->handleForm($request, $form, $page);

        // A submission accepted: POST/redirect/GET, so a refresh does not send
        // again and the back button does not re-post.
        if ($extra instanceof Response) {
            return $extra;
        }

        return new Response(
            $this->cms->renderPage($page, $extra),
            // A refused submission is not a successful page view. 422 rather
            // than 200 so a monitor can tell "the form is rejecting everyone"
            // from "the page is fine".
            ($extra !== [] && $extra['form_errors'] !== []) ? 422 : 200,
            ['Content-Type' => 'text/html; charset=utf-8']
        );
    }

    /**
     * The form branch. Returns the extra template vars, or the 303 that ends
     * the request when a submission is accepted.
     *
     * @param  array<string, mixed> $page
     * @return array<string, mixed>|Response
     */
    private function handleForm(Request $request, Form $form, array $page): array|Response
    {
        // Guarded exactly as Admin::handle() guards it: the suite runs many
        // requests in one process, and that is only possible because handle()
        // returns instead of exiting.
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start(Cms::sessionOptions($request));
        }

        $result = ['ok' => false, 'errors' => [], 'values' => []];

        if ($request->isMethod('POST')) {
            $result = $form->handle($request, $page);

            if ($result['ok']) {
                return new RedirectResponse(
                    $this->cms->localeUrl((string) $page['slug']) . '?sent=1',
                    303,
                    ['Cache-Control' => 'no-store, private']
                );
            }
        }

        // A new token and a new clock on every render, including the re-render
        // after a refusal: the minimum time-to-submit measures how long *this*
        // form was on screen.
        $_SESSION['form_csrf'] = $_SESSION['form_csrf'] ?? bin2hex(random_bytes(16));
        $_SESSION['form_opened_at'] = time();

        return [
            'form_csrf'   => $_SESSION['form_csrf'],
            'form_inputs' => $form->inputs(
                $this->cms->components->get((string) $form->blockOn($page)['type']) ?? []
            ),
            'form_errors' => $result['errors'],
            'form_values' => $result['values'],
            'form_sent'   => $request->query->get('sent') === '1',
            'turnstile_site_key' => (string) $this->cms->config['turnstile']['site_key'],
        ];
    }
}
