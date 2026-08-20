<?php

declare(strict_types=1);

require __DIR__ . '/lib.php';

$cms = cms();

section('Components load from disk');
$types = array_keys($cms->components->all());
sort($types);
ok($types === ['contact_cta', 'contact_form', 'faq', 'gallery', 'hero',
               'site_footer', 'site_header', 'text_image', 'video'],
    'nine components discovered: ' . implode(', ', $types));
ok($cms->components->get('hero')['fields']['heading']['max'] === 70, 'hero.heading max parsed from schema.yml');
ok($cms->components->get('hero')['fields']['align']['editable'] === false, 'hero.align is marked non-editable');
ok(!array_key_exists('align', $cms->components->editableFields('hero')), 'non-editable field excluded from editable set');
ok($cms->components->get('hero')['fields']['align']['options'] === ['start' => 'Left', 'center' => 'Center'], 'select options normalised to a map');

section('Page rendering');
$page = $cms->content->findBySlug('/');
ok($page !== null, 'home page resolved from slug /');
$html = $cms->renderPage($page);
contains($html, '<title>Αρχική — Demo Πελάτη</title>', 'title rendered');
contains($html, 'Μικρά site, χωρίς βαρύ CMS', 'hero heading rendered');
contains($html, '<strong>schema.yml</strong>', 'richtext HTML survives rendering');
contains($html, 'hello@example.gr', 'contact component rendered');
ok(substr_count($html, '<section') === count($page['blocks']), 'every block rendered as a section');

section('Every image renders through picture.twig');
// Mechanical, because "remember to use the partial" is not a mechanism. A
// component that hand-rolls an <img> is one that will ship without a
// width/height pair, without a WebP source, or with alt on the wrong element.
$bare = array_filter(
    glob(dirname(__DIR__) . '/theme/components/*/*.twig') ?: [],
    static fn (string $f): bool => str_contains((string) file_get_contents($f), '<img')
);
ok($bare === [], 'no component template writes its own <img>: ' . implode(', ', array_map('basename', $bare)));

$contactPage = $cms->content->findBySlug('/epikoinonia');
$heroImage = $contactPage['blocks'][0]['fields']['image'];
$hero = $cms->renderPage($contactPage);
contains($hero, '<picture>', 'the rendered page uses <picture>');
contains($hero, '<source type="image/webp"', 'with a WebP source, since there is no format=auto to negotiate for us');
// Read off the page rather than hardcoded: this is demo content, and replacing
// the picture from the panel must not fail the suite.
contains($hero, sprintf('width="%d" height="%d"', $heroImage['width'], $heroImage['height']),
    "and the original's intrinsic dimensions, so the box is reserved before the bytes arrive");
contains($hero, 'loading="eager"', 'the hero is not lazy — lazy-loading the LCP image is the classic mistake');
contains($hero, 'fetchpriority="high"', 'and is fetched at high priority');
ok(substr_count($hero, 'alt=') === substr_count($hero, '<img'), 'alt appears once per <img>, and never on a <source>');
contains($hero, 'alt=""', 'the decorative hero renders alt="" — by declaration now, not by a hardcoded attribute');
missing($hero, 'w=1800', 'a width outside the allowlist never reaches the markup');

section('SEO: the seo block reaches the head, and falls back where it is empty');
// home.yml carries a description and nothing else, which is the normal case: a
// client fills in one field and leaves the other four alone.
// Read off the config rather than hardcoded: DDEV sets SITE_BASE_URL, and a
// suite that assumed localhost would pass in exactly one environment.
$base = rtrim((string) $cms->config['site']['base_url'], '/');
$homeSeo = $cms->seo($cms->content->load('home'));
ok($homeSeo['title'] === 'Αρχική', 'a page with no seo.title falls back to its page title');
contains($html, '<title>Αρχική — Demo Πελάτη</title>', 'and the head is unchanged by the fallback');
contains($html, '<meta name="description" content="Δείγμα σελίδας για το FlatCMS.">', 'the description is rendered');
contains($html, '<meta property="og:title" content="Αρχική">', 'og:title uses the same resolved title');
contains($html, '<meta property="og:url" content="' . $base . '/">', 'og:url is absolute, since a scraper does not resolve a relative one');
missing($html, '<meta name="robots"', 'a page that is not noindex says nothing about robots');
contains($html, '<link rel="canonical" href="' . $base . '/">', 'and a self-referencing canonical, so ?fbclid= variants collapse to the real URL');
// home.yml's hero image is the first image on the page, so og:image is filled
// in from it rather than being absent.
contains($html, '<meta property="og:image"', 'og:image is filled in from the page itself');
missing($html, 'og:image:alt', 'with no og:image:alt — the share image is decorative and the card carries text');

// The keys a template may ask for are the schema's, whatever is in the file.
$stray = $cms->seo(['title' => 'T', 'slug' => '/x', 'seo' => ['description' => 'D', 'evil' => 'x']]);
ok(!array_key_exists('evil', $stray), 'a key the file carries but Fields::SEO does not is never exposed as page.seo.*');
ok($stray['noindex'] === false && $stray['canonical'] === $base . '/x', 'while every declared key is present, defaulted — canonical to the page\'s own URL');

// The URL a request should be served at: no trailing slash, except a locale
// root. This is what the front controller 301s to.
ok($cms->canonicalPath('/about/') === '/about', 'canonicalPath strips a trailing slash');
ok($cms->canonicalPath('/about') === '/about', 'and leaves the canonical form alone');
ok($cms->canonicalPath('/') === '/', 'the site root keeps its slash');
ok($cms->canonicalPath('/en') === '/en/', 'a bare locale prefix gains one — /en/ is the English home');
ok($cms->canonicalPath('/en/about/') === '/en/about', 'and a locale page loses one');

// og_image is a map now, not a string, and it must leave here absolute.
$withOg = $cms->seo(['title' => 'T', 'slug' => '/x', 'seo' => [
    'og_image' => ['src' => '/uploads/a.jpg', 'alt' => 'Η ομάδα'],
]]);
ok($withOg['og_image']['src'] === $base . '/uploads/a.jpg', 'a stored og_image src is made absolute: ' . $withOg['og_image']['src']);
ok(!array_key_exists('alt', $withOg['og_image']), 'and carries no alt at all — the field is declared decorative');

section('SEO: what a page says about itself when the block is empty');
// Both fallbacks come off the schema rather than off a heuristic: the first
// textarea/richtext value in block order, and the first image. "The first long
// text field" would need a length to tune, and would pick a different field the
// day someone edited the copy; the field type is a question the component
// already answered, once, in schema.yml.
$home = $cms->content->load('home');
$bare = ['title' => 'Αρχική', 'slug' => '/', 'blocks' => $home['blocks']];   // no seo: at all
$derived = $cms->seo($bare);

ok($derived['description'] !== '', 'a page with no seo.description still publishes one');
ok($derived['description'] === $home['blocks'][0]['fields']['subheading'],
    'taken from the first prose field on the page: ' . $derived['description']);
ok($derived['og_image']['src'] === $base . $home['blocks'][0]['fields']['image']['src'],
    'and og:image from the first image on it');

// Resolved at render and never written back. A derived description sitting in
// the file would look filled in from the panel, so nobody would ever replace it
// with a real one, and it would go stale the moment the copy above it changed.
ok(!array_key_exists('seo', $bare), 'and nothing is written back onto the page — the fallback is a render-time answer');
ok(($cms->content->load('home')['seo']['description'] ?? '') === 'Δείγμα σελίδας για το FlatCMS.',
    'the file still holds only what the client actually typed');

// Richtext is HTML, and a snippet reading "drag &amp;amp; drop" is worse than none.
$rich = $cms->seo(['title' => 'T', 'slug' => '/x', 'blocks' => [
    ['id' => 'i', 'type' => 'text_image', 'fields' => ['body' => '<p>Drag &amp; drop <strong>χωρίς</strong> κόπο.</p>']],
]]);
ok($rich['description'] === 'Drag & drop χωρίς κόπο.', 'tags come out and entities come back: ' . $rich['description']);

// Google shows what it is given, so a derived description that stops mid-word
// looks like a broken site rather than a busy one.
$long = $cms->seo(['title' => 'T', 'slug' => '/x', 'blocks' => [
    ['id' => 'i', 'type' => 'text_image', 'fields' => ['body' => '<p>' . str_repeat('λέξη ', 60) . '</p>']],
]]);
ok(mb_strlen($long['description']) <= 155, 'an over-long one is cut to 155 (' . mb_strlen($long['description']) . ')');
ok(str_ends_with($long['description'], '…'), 'and says so');
ok(str_ends_with($long['description'], 'λέξη…'), 'on a word boundary, never mid-word: ' . mb_substr($long['description'], -12));

// A written value always wins: the fallback is for the empty case only.
$explicit = $cms->seo(['title' => 'T', 'slug' => '/x', 'seo' => ['description' => 'Γραμμένο από τον πελάτη'], 'blocks' => [
    ['id' => 'i', 'type' => 'text_image', 'fields' => ['body' => '<p>Δεν πρέπει να χρησιμοποιηθεί.</p>']],
]]);
ok($explicit['description'] === 'Γραμμένο από τον πελάτη', 'a description the client wrote is never overridden by the fallback');

// An image inside a list row is one of many by construction: "the first row of
// the gallery" is not a considered choice of share image.
ok($cms->seo(['title' => 'T', 'slug' => '/x', 'blocks' => [
    ['id' => 'f', 'type' => 'faq', 'fields' => ['questions' => [['question' => 'Q', 'answer' => '<p>A</p>']]]],
]])['og_image']['src'] === '', 'a value inside a list row is not harvested for either fallback');

$cfgOg = require dirname(__DIR__) . '/config.php';
$cfgOg['site']['og_default'] = '/uploads/social.png';
$siteFallback = (new \Dopamine\FlatCms\Cms($cfgOg))->seo(['title' => 'T', 'slug' => '/x']);
ok($siteFallback['og_image']['src'] === $base . '/uploads/social.png',
    'a page with no og_image and no image of its own falls back to the site default');
ok((new \Dopamine\FlatCms\Cms($cfgOg))->seo(['title' => 'T', 'slug' => '/x', 'blocks' => [
    ['id' => 'h', 'type' => 'hero', 'fields' => ['image' => ['src' => '/uploads/hero.jpg']]],
]])['og_image']['src'] === $base . '/uploads/hero.jpg',
    'while an image on the page beats the site default');

$noindexed = $cms->renderPage(['id' => 'x', 'title' => 'T', 'slug' => '/x', 'blocks' => [], 'seo' => [
    'noindex' => true, 'canonical' => 'https://example-domain.com/kanoniko',
]]);
contains($noindexed, '<meta name="robots" content="noindex, nofollow">', 'seo.noindex emits the robots meta');
contains($noindexed, '<link rel="canonical" href="https://example-domain.com/kanoniko">', 'and a canonical is rendered when set');

section('/sitemap.xml is generated from the content files');
$sitemap = $cms->sitemap();
contains($sitemap, '<?xml version="1.0" encoding="UTF-8"?>', 'it is XML');
contains($sitemap, 'xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"', 'in the sitemap namespace');
// Every language's pages are in the one file, so the count is the sum.
$everyPage = 0;
foreach (array_keys($cms->locales()) as $code) {
    $everyPage += count($cms->contentIn($code)->list());
}
ok(substr_count($sitemap, '<loc>') === $everyPage, 'every page in every language is listed once');
contains($sitemap, '<loc>' . $base . '/</loc>', 'the home page at the site base URL');
contains($sitemap, '<loc>' . $base . '/epikoinonia</loc>', 'and the contact page, private: true or not — it is a real page');
ok(substr_count($sitemap, '<lastmod>') === substr_count($sitemap, '<loc>'), 'each with a lastmod');
ok((bool) preg_match('#<lastmod>\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}</lastmod>#', $sitemap),
    'in W3C datetime, from the page file mtime');
missing($sitemap, '<changefreq>', 'no changefreq — Google ignores it, and a value invented to fill it is a wrong claim');
missing($sitemap, '<priority>', 'nor priority, for the same reason');
contains($sitemap, '<?xml-stylesheet type="text/xsl" href="/sitemap.xsl"?>',
    'it points at the XSL, so a browser shows a table instead of run-together text');
$xsl = $cms->sitemapXsl();
contains($xsl, '<xsl:stylesheet', 'and /sitemap.xsl serves a stylesheet');
ok(simplexml_load_string($xsl) !== false, 'that is well-formed XML');

// A page lists itself among its own alternates — without that the group is not
// valid at all — plus every language that has it, plus x-default.
contains($sitemap, 'xmlns:xhtml="http://www.w3.org/1999/xhtml"', 'the xhtml namespace is declared');
contains($sitemap, '<xhtml:link rel="alternate" hreflang="el" href="' . $base . '/"/>',
    'each URL carries an alternate for the default language');
contains($sitemap, '<xhtml:link rel="alternate" hreflang="en" href="' . $base . '/en/"/>',
    'and one for the second, at its prefixed URL');
contains($sitemap, '<xhtml:link rel="alternate" hreflang="x-default" href="' . $base . '/"/>',
    'with x-default pointing at the default language');
// Derived from the content rather than `<loc> × languages`, because that
// product is only right when every page exists in every language. A page one
// language lacks carries one alternate fewer — advertising an hreflang for a
// URL that does not exist is exactly the bug this counts against. `bin/doctor`
// warns about a missing translation instead of refusing it, so a partly
// translated site is a state the sitemap has to get right, not an invalid one.
$langsWith = [];
foreach (array_keys($cms->locales()) as $code) {
    foreach ($cms->contentIn($code)->list() as $p) {
        $langsWith[$p['id']] = ($langsWith[$p['id']] ?? 0) + 1;
    }
}
$expectedAlts = 0;
foreach (array_keys($cms->locales()) as $code) {
    foreach ($cms->contentIn($code)->list() as $p) {
        // Plus one for x-default, emitted only when the default language has
        // this page — it is the entry x-default would have to point at.
        $expectedAlts += $langsWith[$p['id']]
            + ($cms->contentIn($cms->defaultLocale())->load($p['id']) !== null ? 1 : 0);
    }
}
ok(substr_count($sitemap, '<xhtml:link') === $expectedAlts,
    'one alternate per language that actually has the page, plus x-default');

// A noindex page asking not to be indexed and then being submitted anyway is
// asking twice and answering differently.
// Counted before the file is written, not hardcoded: the suite runs against the
// real content directory, so a page added to the site must not fail a test.
$pagesBefore = count($cms->content->list());
$sitemapFile = $cms->content->pagesDir() . '/tmp-seo.yml';
// Away even if an assertion below throws: a stray page file in the locale
// directory fails every page-count check in the suite from then on.
register_shutdown_function(static fn (): bool => @unlink($sitemapFile));
file_put_contents($sitemapFile, \Symfony\Component\Yaml\Yaml::dump([
    'title' => 'Κρυφή', 'slug' => '/kryfi', 'seo' => ['noindex' => true], 'blocks' => [],
], 6, 2));
$withHidden = cms()->sitemap();
ok(count(cms()->content->list()) === $pagesBefore + 1, 'the extra page is on disk');
missing($withHidden, '/kryfi', 'but a noindex page is excluded from the sitemap');
ok(substr_count($withHidden, '<loc>') === $everyPage, 'leaving exactly the indexable ones');
unlink($sitemapFile);

section('/robots.txt points at the sitemap');
$robots = $cms->robotsTxt();
contains($robots, 'User-agent: *', 'it names an agent');
contains($robots, 'Allow: /', 'a live site is crawlable');
contains($robots, 'Sitemap: ' . $base . '/sitemap.xml', 'and the sitemap is advertised at an absolute URL');

// The same policy as the X-Robots-Tag header, said where the other half of the
// crawler looks. A pre-launch domain that noindexes its pages while robots.txt
// says "help yourself" is one crawler away from being indexed anyway.
putenv('SITE_NOINDEX=1');
$hidden = new \Dopamine\FlatCms\Cms(require dirname(__DIR__) . '/config.php');
putenv('SITE_NOINDEX');
contains($hidden->robotsTxt(), 'Disallow: /', 'SITE_NOINDEX=1 disallows everything');
missing($hidden->robotsTxt(), 'Allow: /', 'rather than saying both things at once');
contains($hidden->robotsTxt(), 'Sitemap:', 'while still naming the sitemap, which is where it goes when the flag comes off');

section('Cross-page output is tagged `site`, never page:<id>');
// A sitemap tagged page:<id> is never purged: the page that changed is never
// the sitemap. `site` is the tag every save already purges.
$feedHeaders = implode("\n", $cms->cacheHeaders([]));
contains($feedHeaders, 'Cache-Tag: site', 'a response with no page behind it carries the site tag alone');
missing($feedHeaders, 'page:', 'and no page tag at all');
contains($feedHeaders, 's-maxage=31536000', 'while still being edge-cached like a page');
contains(implode("\n", $cms->cacheHeaders($cms->content->load('home'))), 'Cache-Tag: page:home,site',
    'and a page still carries both');

section('Slug resolution');
ok($cms->content->findBySlug('/epikoinonia') !== null, 'second page resolves');
ok($cms->content->findBySlug('/does-not-exist') === null, 'unknown slug returns null');
$listedIds = array_column($cms->content->list(), 'id');
ok(in_array('home', $listedIds, true) && in_array('epikoinonia', $listedIds, true),
    'the page list finds the pages on disk');

section('Unknown component does not fatal a live page');
$before = substr_count($cms->renderPage($page), '<section');
$page['blocks'][] = ['id' => 'ghost', 'type' => 'no_such_component', 'fields' => []];
$html2 = $cms->renderPage($page);
ok(substr_count($html2, '<section') === $before, 'unknown block skipped rather than crashing');

section('Image transformation URLs');
// Local dev no longer diverges from production: with transform off, img()
// points at the local derivative route instead of handing back a bare path, so
// the responsive markup is exercised before it is deployed.
$off = $cms->imageUrl('/uploads/x.jpg', 960);
ok($off === '/img.php?src=%2Fuploads%2Fx.jpg&w=960', 'transform disabled: the local derivative route is used — ' . $off);
contains($cms->imageUrl('/uploads/x.jpg', 960, 'webp'), '&f=webp', 'an explicit format reaches the route');
ok($cms->imageUrl('/uploads/x.jpg', 800) === '', 'a width outside the allowlist yields no URL at all, rather than one that would 404');
ok($cms->imageUrl('/uploads/x.jpg', 960, 'avif') === '', 'and neither does a format outside it');
ok($cms->imageUrl('/uploads/x.jpg') === '/uploads/x.jpg', 'asking for no width still means the stored original');

$cfg = require dirname(__DIR__) . '/config.php';
$cfg['images']['transform'] = true;
$on = (new \Dopamine\FlatCms\Cms($cfg))->imageUrl('https://media.test.gr/uploads/x.jpg', 1280);
contains($on, '/cdn-cgi/image/width=1280,quality=82,format=auto,fit=cover/', 'cdn-cgi transform URL built');
contains($on, 'https://media.test.gr/uploads/x.jpg', 'absolute source preserved');
ok((new \Dopamine\FlatCms\Cms($cfg))->imageUrl('https://media.test.gr/uploads/x.jpg', 1200) === '',
    'the width allowlist applies to the Cloudflare path too — one set of variants, both backends');

section('The header and the footer are content, on every page');
$withGlobals = cms()->renderPage(cms()->content->findBySlug('/'));
contains($withGlobals, '<header>', 'the layout renders a header landmark');
contains($withGlobals, 'aria-label="Κύρια πλοήγηση"', 'and the menu comes from the site_header component inside it');
contains($withGlobals, '<footer>', 'a footer landmark too');
contains($withGlobals, 'Θεσσαλονίκη, Ελλάδα', 'carrying what _footer.yml actually says');
contains($withGlobals, '© ' . date('Y'), 'and a copyright year the client never has to retype');

// The same blocks on a different page, unchanged — that is the whole feature.
$second = cms()->renderPage(cms()->content->findBySlug('/epikoinonia'));
contains($second, 'Θεσσαλονίκη, Ελλάδα', 'the footer is on the second page as well');
// `page` reaches a global's blocks as the page being rendered, not as the file
// the block was read from, which is what lets the menu mark where you are.
contains($second, 'href="/epikoinonia" aria-current="page"', 'and the menu marks the current page, from `page` shared into the region');

// A global's blocks are not the page's, so nothing in the header or the footer
// can become a page's meta description or its share image.
$footerSeo = cms()->seo(cms()->content->load('epikoinonia'));
ok(!str_contains($footerSeo['description'], 'Θεσσαλονίκη, Ελλάδα'),
    'a footer sentence never becomes a page description: ' . $footerSeo['description']);

section('A page may name its own layout');
// The alternative to one optional filename is a `bare: true` boolean, then a
// second one, then a page-type system — which §4 has said no to since v1.
$landing = ['id' => 'l', 'title' => 'L', 'slug' => '/l', 'layout' => 'bare', 'blocks' => [
    ['id' => 'h', 'type' => 'hero', 'fields' => ['heading' => 'Προσφορά']],
]];
$bare = $cms->renderPage($landing);
contains($bare, 'Προσφορά', 'the page renders');
missing($bare, '<header>', 'with no header');
missing($bare, '<footer>', 'and no footer — that is the whole difference');
contains($bare, '<title>L — Demo Πελάτη</title>', 'while the head is still built from page.seo');

// Developer-owned, and it names a *template*: a value from a request would be a
// file-read primitive. A name that does not resolve falls back rather than
// fatalling a live client site — the same policy as an unknown component.
foreach (['nope', '../../etc/passwd', 'admin/edit', ''] as $bad) {
    $fellBack = $cms->renderPage(['id' => 'x', 'title' => 'X', 'slug' => '/x', 'layout' => $bad, 'blocks' => []]);
    contains($fellBack, '<footer>', var_export($bad, true) . ' falls back to layout.twig rather than fatalling');
}

// The two heads must not diverge quietly: a change to the SEO block that
// reaches one layout and not the other is invisible until a client shares a
// link and gets the wrong card.
$headOf = static function (string $file): array {
    preg_match('#<head>(.*?)</head>#s', (string) file_get_contents(dirname(__DIR__) . '/theme/' . $file), $m);
    preg_match_all('#page\.seo\.[a-z_.]+#', $m[1] ?? '', $keys);

    return array_unique($keys[0]);
};
$a = $headOf('layout.twig');
$b = $headOf('bare.twig');
sort($a);
sort($b);
ok($a === $b, 'both layouts publish the same seo keys: ' . implode(', ', $a));

section('A gallery renders through the same partial as every other image');
$galleryPage = $cms->renderPage($cms->content->load('home'));
contains($galleryPage, 'class="gallery-block"', 'the gallery component renders');
// Every photo is a <picture>, not an <img>: the mechanical check above already
// forbids a bare <img> in a component, and this is the positive half of it.
$photos = $cms->content->load('home')['blocks'][array_search('gallery',
    array_column($cms->content->load('home')['blocks'], 'id'), true)]['fields']['photos'];
ok(substr_count($galleryPage, '<picture>') >= count($photos), 'with one <picture> per photo');
contains($galleryPage, 'loading="lazy"', 'and lazily — a gallery is below the fold by construction');

section('A video is a facade: nothing third-party loads until the visitor clicks');
ok(str_contains($galleryPage, 'class="video-facade"')
    && str_contains($galleryPage, "querySelectorAll('.video-facade')"),
    'the facade renders and video.js initialises every instance in one pass');
contains($galleryPage, 'data-video-src="https://www.youtube-nocookie.com/embed/', 'pointing at the no-cookie host');
missing($galleryPage, '<iframe', 'with no iframe on the page at all');
missing($galleryPage, 'youtube.com/embed', 'and no request to youtube.com either — that is the whole point');
// The iframe is built from a stored {provider, id}; pasted HTML is never stored,
// so there is no path from what a client typed to what a browser executes.
contains($galleryPage, '<button type="button" class="video-play">', 'the control is a real button, focusable and announced');

$loopPage = $cms->renderPage(['id' => 'v', 'title' => 'V', 'slug' => '/v', 'blocks' => [
    ['id' => 'v', 'type' => 'video', 'fields' => [
        'loop' => ['src' => '/uploads/clip.mp4', 'poster' => ['src' => '/uploads/p.jpg', 'alt' => 'A', 'width' => 800, 'height' => 450]],
    ]],
]]);
contains($loopPage, '<source src="/uploads/clip.mp4" type="video/mp4">', 'a self-hosted loop renders a <video>');
contains($loopPage, 'autoplay loop muted playsinline', 'muted and playsinline, without which no browser will autoplay it');
contains($loopPage, 'poster="/uploads/p.jpg"', 'with its poster');
contains($loopPage, 'prefers-reduced-motion', 'and detects anyone who asked the OS for less motion');
missing($loopPage, '.video-block .loop{display:none}', 'without hiding the poster together with the moving image');
contains($loopPage, "video.removeAttribute('autoplay')", 'instead disabling playback while the poster remains visible');

section('Two languages, resolved by URL prefix');
$i18n = cms();
ok(array_keys($i18n->locales()) === ['el', 'en'], 'both languages are configured');
ok($i18n->defaultLocale() === 'el', 'and one of them is the default');
ok($i18n->locales()['el']['prefix'] === '', 'whose prefix is empty, so its URLs are what they always were');

$i18n->useLocale('en');
$en = $i18n->content->findBySlug('/contact');
ok($en !== null && $en['title'] === 'Contact', 'an English slug resolves in the English store');
ok($i18n->content->findBySlug('/epikoinonia') === null, 'and the Greek slug does not resolve there');
// The filename is the translation identity, and nothing inside the file says so.
ok($en['id'] === 'epikoinonia', 'both languages share the page id, which is the filename: ' . $en['id']);

$enForm = new \Dopamine\FlatCms\Form($i18n);
$enFormBlock = $enForm->blockOn($en);
$enFormSchema = $i18n->components->get((string) ($enFormBlock['type'] ?? '')) ?? [];
$enHtml = $i18n->renderPage($en, ['form_inputs' => $enForm->inputs($enFormSchema)]);
contains($enHtml, '<html lang="en">', 'the document declares the language being rendered');
contains($enHtml, 'We usually answer within one working day', 'with the English copy');
contains($enHtml, 'href="/en/"', 'the menu links to English URLs, prefix included');
contains($enHtml, 'href="/en/contact" aria-current="page"', 'and marks the current page at its prefixed URL');
contains($enHtml, '<meta property="og:url" content="' . $base . '/en/contact">', 'og:url carries the prefix too');
contains($enHtml, '<a class="brand" href="/en/">', 'the logo keeps an English visitor in the English site');
contains($enHtml, 'Name <span aria-hidden="true">*</span>', 'visitor form labels speak the page language');
contains($enHtml, '<span>Phone</span>', 'and so do fixed labels in public components');
missing($enHtml, '>Ονοματεπώνυμο', 'no Greek form label leaks into the English page');
contains($enHtml, '<a href="/en/contact">Contact</a>', 'the English footer link resolves to the prefixed route');

$notFound = $i18n->twig->render('404.twig', [
    'slug' => '/en/missing', 'locale' => 'en', 'home_url' => '/en/',
]);
contains($notFound, '<html lang="en">', 'the English 404 declares the requested language');
contains($notFound, 'The page /en/missing was not found.', 'and its message is translated');
contains($notFound, 'href="/en/">Home</a>', 'with a locale-aware way home');

section('hreflang and the language switcher are one list asked twice');
contains($enHtml, '<link rel="alternate" hreflang="en" href="' . $base . '/en/contact">', 'the page links to itself');
contains($enHtml, '<link rel="alternate" hreflang="el" href="' . $base . '/epikoinonia">', 'and to its Greek translation');
contains($enHtml, '<link rel="alternate" hreflang="x-default" href="' . $base . '/epikoinonia">',
    'with x-default on the default language');
contains($enHtml, 'class="lang-switch"', 'the header renders a switcher');
contains($enHtml, '<a href="/epikoinonia" hreflang="el"', 'pointing at the same page in the other language');

$i18n->useLocale('el');
$elHtml = $i18n->renderPage($i18n->content->findBySlug('/epikoinonia'));
contains($elHtml, '<html lang="el">', 'the Greek page declares Greek');
contains($elHtml, 'href="/epikoinonia" aria-current="page"', 'and its own menu is unprefixed');
missing($elHtml, '/en/epikoinonia', 'no Greek slug is ever served under the English prefix');

section('Changing a prefix moves the URLs, with no code change');
// The acceptance criterion for the whole phase: every link goes through
// localeUrl(), so config is the only place a prefix is written down.
$moved = require dirname(__DIR__) . '/config.php';
$moved['locales']['en']['prefix'] = '/english';
$movedCms = new \Dopamine\FlatCms\Cms($moved);
$movedCms->useLocale('en');
contains($movedCms->renderPage($movedCms->content->findBySlug('/contact')), 'href="/english/"',
    'the menu follows the new prefix');
ok($movedCms->localeOf('/english/contact') === ['en', '/contact'], 'and so does resolution');
ok($movedCms->localeOf('/en/contact') === ['el', '/en/contact'], 'while the old prefix is no longer a language at all');

section('A missing translation honours its fallback');
$orphan = $i18n->content->pagesDir() . '/tmp-orphan.yml';
register_shutdown_function(static fn (): bool => @unlink($orphan));
file_put_contents($orphan, \Symfony\Component\Yaml\Yaml::dump([
    'title' => 'Μόνο στα ελληνικά', 'slug' => '/mono-ellinika', 'blocks' => [],
], 6, 2));

$fb = cms();
$fb->useLocale('en');
// `fallback: default` — the link goes to the version that exists rather than
// to nothing, and it goes there at the *default* language's URL.
ok($fb->pageUrl('tmp-orphan') === '/mono-ellinika',
    'with fallback: default an untranslated link resolves to the default language: ' . $fb->pageUrl('tmp-orphan'));

$strict = require dirname(__DIR__) . '/config.php';
$strict['locales']['en']['fallback'] = '404';
$strictCms = new \Dopamine\FlatCms\Cms($strict);
$strictCms->useLocale('en');
ok($strictCms->pageUrl('tmp-orphan') === '',
    'with fallback: 404 it resolves to nothing, which templates render as plain text');
ok($strictCms->locales()['el']['fallback'] === '',
    'and the default language carries no fallback at all — there is nothing for it to fall back to');

// The switcher still offers the language, pointing at its home page: being
// shown fewer languages on one page than on another reads as a broken site.
$fb->useLocale('el');
$alts = $fb->alternates($fb->content->load('tmp-orphan'));
$enAlt = $alts[array_search('en', array_column($alts, 'locale'), true)];
ok($enAlt['missing'] === true, 'the alternate for the untranslated language is marked missing');
ok($enAlt['url'] === '/en/', 'and points at that language\'s home page rather than at a 404');
@unlink($orphan);

section('A missing global renders an empty region, never a fatal');
// A site whose developer has not written a _footer.yml yet must still serve
// every page. bin/doctor is where that omission is reported, not a 500.
$moved = cms()->content->pagesDir() . '/_footer.yml';
rename($moved, $moved . '.gone');
try {
    $without = cms()->renderPage(cms()->content->findBySlug('/'));
    contains($without, '<header>', 'the header still renders');
    missing($without, '<footer>', 'the footer landmark is absent rather than empty');
    contains($without, 'Μικρά site, χωρίς βαρύ CMS', 'and the page itself is served as normal');
} finally {
    rename($moved . '.gone', $moved);
}

summary();
