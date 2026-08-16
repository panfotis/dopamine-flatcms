<?php

declare(strict_types=1);

namespace Dopamine\FlatCms;

use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFunction;

/**
 * Wires everything together. One instance per request.
 */
final class Cms
{
    public readonly Components $components;
    public readonly Content $content;
    public readonly Auth $auth;
    public readonly R2 $r2;
    public readonly Cloudflare $cf;
    public readonly Locks $locks;
    public readonly Environment $twig;

    /** @param array<string, mixed> $config */
    public function __construct(public readonly array $config)
    {
        $paths = $config['paths'];

        $this->components = new Components($paths['components']);
        $this->content    = new Content($paths['content']);
        $this->auth       = new Auth($config['auth'], $paths['cache']);
        $this->r2         = new R2($config['r2'], $paths['uploads']);
        $this->cf         = new Cloudflare($config['cloudflare']);
        $this->locks      = new Locks(dirname($paths['cache']) . '/locks');

        $loader = new FilesystemLoader([$paths['templates'], $paths['components']]);
        $this->twig = new Environment($loader, [
            'cache'      => $config['twig_cache'] ? $paths['cache'] . '/twig' : false,
            'autoescape' => 'html',
            'strict_variables' => false,
        ]);

        $this->twig->addFunction(new TwigFunction('img', $this->imageUrl(...)));
        $this->twig->addFunction(new TwigFunction('site', fn (string $k): mixed => $config['site'][$k] ?? null));
        // NOTE: there is deliberately no `|rich` filter. A filter that marks
        // arbitrary strings `is_safe: html` is an XSS primitive waiting for
        // someone to reach for it; richtext is already sanitised on save and
        // templates use `|raw` explicitly so the trust decision stays visible.
    }

    /**
     * Build a Cloudflare image-transformation URL.
     *
     *   {{ img(block.fields.image, 1600) }}
     *   -> /cdn-cgi/image/width=1600,quality=82,format=auto/https://media.site.gr/uploads/x.jpg
     *
     * Cloudflare resizes and re-encodes on the fly and caches the result, so we
     * never generate or store derivatives. Free plan covers 5.000 unique
     * transformations a month, which is far more than a brochure site uses.
     */
    public function imageUrl(?string $src, int $width = 0, string $fit = 'cover'): string
    {
        $src = (string) $src;
        if ($src === '') {
            return '';
        }

        if (!$this->config['images']['transform'] || $width <= 0) {
            return $src;
        }

        $opts = [
            'width=' . $width,
            'quality=' . (int) $this->config['images']['quality'],
            'format=auto',
            'fit=' . $fit,
        ];

        // The source must be absolute for /cdn-cgi/image to fetch it.
        $abs = str_starts_with($src, 'http')
            ? $src
            : rtrim((string) $this->config['site']['base_url'], '/') . '/' . ltrim($src, '/');

        return '/cdn-cgi/image/' . implode(',', $opts) . '/' . $abs;
    }

    /**
     * Render a page: for each block, render its component template with that
     * block's fields, then drop the result into the layout.
     *
     * @param array<string, mixed> $page
     */
    public function renderPage(array $page): string
    {
        $html = [];
        foreach ($page['blocks'] as $block) {
            $schema = $this->components->get((string) $block['type']);
            if ($schema === null) {
                // Unknown component: skip in production rather than fatal on a
                // live client site. Visible only in the admin panel.
                continue;
            }

            $html[] = $this->twig->render($schema['template'], [
                'block'  => $block,
                'fields' => $this->withDefaults($schema, $block['fields']),
                'page'   => $page,
            ]);
        }

        return $this->twig->render('layout.twig', [
            'page'   => $page,
            'blocks' => $html,
        ]);
    }

    /**
     * Fill in defaults for fields the content file does not define yet, so
     * adding a field to schema.yml never breaks an existing page.
     *
     * @param  array<string, mixed> $schema
     * @param  array<string, mixed> $values
     * @return array<string, mixed>
     */
    public function withDefaults(array $schema, array $values): array
    {
        $out = [];
        foreach ($schema['fields'] as $name => $def) {
            $out[$name] = $values[$name] ?? $def['default'] ?? '';
        }

        // Deliberately NOT `+ $values`. Keys still sitting in the content file
        // after a field was removed from schema.yml must not reach templates —
        // if a field was deleted because it was unsafe, its value has never
        // been through the current sanitiser.
        return $out;
    }

    /**
     * Constraints handed to Fields::sanitise() at save time.
     *
     * @return array<string, mixed>
     */
    public function fieldContext(): array
    {
        $bases = (array) ($this->config['media_bases'] ?? ['/uploads/']);

        $r2Base = rtrim((string) ($this->config['r2']['public_base'] ?? ''), '/');
        if ($r2Base !== '') {
            $bases[] = $r2Base . '/';
        }

        return ['media_bases' => array_values(array_filter($bases))];
    }

    /**
     * Cache headers for a page, as literal header lines.
     *
     * Split out from sendCacheHeaders() so the policy is assertable — header()
     * is a no-op under the CLI SAPI, which is where the suite runs.
     *
     * A page marked `private: true` in its YAML bypasses edge caching
     * altogether. That is the v1 contact-form policy: a form page carries a
     * per-visitor CSRF token, and a shared cache that stores one visitor's
     * token and serves it to the next turns every submission into a rejected
     * one — or worse, a valid one attributed to the wrong session. A later
     * uncached-token endpoint would let the page itself be cached again, but
     * that is an optimisation to make only if traffic proves a five-page site
     * needs its contact page at the edge.
     *
     * @param  array<string, mixed> $page
     * @return list<string>
     */
    public function cacheHeaders(array $page): array
    {
        if (($page['private'] ?? false) === true) {
            // No Cache-Tag: there is nothing at the edge to purge, and emitting
            // one would imply otherwise.
            return ['Cache-Control: no-store, private'];
        }

        $c = $this->config['cache'];

        return [
            sprintf(
                'Cache-Control: public, max-age=%d, s-maxage=%d',
                (int) $c['browser_max_age'],
                (int) $c['edge_max_age']
            ),
            'Cache-Tag: ' . Cloudflare::tagFor((string) $page['id']) . ',site',
        ];
    }

    /** @param array<string, mixed> $page */
    public function sendCacheHeaders(array $page): void
    {
        foreach ($this->cacheHeaders($page) as $line) {
            header($line);
        }
    }
}
