<?php

declare(strict_types=1);

require __DIR__ . '/lib.php';

$cms = cms();

section('Components load from disk');
$types = array_keys($cms->components->all());
sort($types);
ok($types === ['contact_cta', 'faq', 'hero', 'text_image'], 'four components discovered: ' . implode(', ', $types));
ok($cms->components->get('hero')['fields']['heading']['max'] === 70, 'hero.heading max parsed from schema.yml');
ok($cms->components->get('hero')['fields']['align']['editable'] === false, 'hero.align is marked non-editable');
ok(!array_key_exists('align', $cms->components->editableFields('hero')), 'non-editable field excluded from editable set');
ok($cms->components->get('hero')['fields']['align']['options'] === ['start' => 'Αριστερά', 'center' => 'Κέντρο'], 'select options normalised to a map');

section('Page rendering');
$page = $cms->content->findBySlug('/');
ok($page !== null, 'home page resolved from slug /');
$html = $cms->renderPage($page);
contains($html, '<title>Αρχική — Demo Πελάτη</title>', 'title rendered');
contains($html, 'Μικρά site, χωρίς βαρύ CMS', 'hero heading rendered');
contains($html, '<strong>schema.yml</strong>', 'richtext HTML survives rendering');
contains($html, 'hello@example.gr', 'contact component rendered');
ok(substr_count($html, '<section') === 5, 'all five blocks rendered as sections');

section('Every image renders through picture.twig');
// Mechanical, because "remember to use the partial" is not a mechanism. A
// component that hand-rolls an <img> is one that will ship without a
// width/height pair, without a WebP source, or with alt on the wrong element.
$bare = array_filter(
    glob(dirname(__DIR__) . '/components/*/*.twig') ?: [],
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
missing($html, 'rel="canonical"', 'nor claims a canonical it does not have');
// home.yml's hero image is the first image on the page, so og:image is filled
// in from it rather than being absent.
contains($html, '<meta property="og:image"', 'og:image is filled in from the page itself');
missing($html, 'og:image:alt', 'with no og:image:alt — the share image is decorative and the card carries text');

// The keys a template may ask for are the schema's, whatever is in the file.
$stray = $cms->seo(['title' => 'T', 'slug' => '/x', 'seo' => ['description' => 'D', 'evil' => 'x']]);
ok(!array_key_exists('evil', $stray), 'a key the file carries but Fields::SEO does not is never exposed as page.seo.*');
ok($stray['noindex'] === false && $stray['canonical'] === '', 'while every declared key is present, defaulted');

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
    'noindex' => true, 'canonical' => 'https://pelatis.gr/kanoniko',
]]);
contains($noindexed, '<meta name="robots" content="noindex, nofollow">', 'seo.noindex emits the robots meta');
contains($noindexed, '<link rel="canonical" href="https://pelatis.gr/kanoniko">', 'and a canonical is rendered when set');

section('/sitemap.xml is generated from the content files');
$sitemap = $cms->sitemap();
contains($sitemap, '<?xml version="1.0" encoding="UTF-8"?>', 'it is XML');
contains($sitemap, 'xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"', 'in the sitemap namespace');
ok(substr_count($sitemap, '<loc>') === count($cms->content->list()), 'every page is listed once');
contains($sitemap, '<loc>' . $base . '/</loc>', 'the home page at the site base URL');
contains($sitemap, '<loc>' . $base . '/epikoinonia</loc>', 'and the contact page, private: true or not — it is a real page');
ok(substr_count($sitemap, '<lastmod>') === substr_count($sitemap, '<loc>'), 'each with a lastmod');
ok((bool) preg_match('#<lastmod>\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}</lastmod>#', $sitemap),
    'in W3C datetime, from the page file mtime');
missing($sitemap, '<changefreq>', 'no changefreq — Google ignores it, and a value invented to fill it is a wrong claim');
missing($sitemap, '<priority>', 'nor priority, for the same reason');

// One locale is resolved today, so one alternate: a page must list itself
// among its own alternates for the group to be valid at all.
contains($sitemap, 'xmlns:xhtml="http://www.w3.org/1999/xhtml"', 'the xhtml namespace is declared');
contains($sitemap, '<xhtml:link rel="alternate" hreflang="el" href="' . $base . '/"/>',
    'and each URL carries an alternate for the configured locale');
ok(substr_count($sitemap, '<xhtml:link') === substr_count($sitemap, '<loc>'), 'one per URL');

// A noindex page asking not to be indexed and then being submitted anyway is
// asking twice and answering differently.
$sitemapFile = $cms->content->pagesDir() . '/_seo.yml';
// Away even if an assertion below throws: a stray page file in the locale
// directory fails every page-count check in the suite from then on.
register_shutdown_function(static fn (): bool => @unlink($sitemapFile));
file_put_contents($sitemapFile, \Symfony\Component\Yaml\Yaml::dump([
    'title' => 'Κρυφή', 'slug' => '/kryfi', 'seo' => ['noindex' => true], 'blocks' => [],
], 6, 2));
$withHidden = cms()->sitemap();
ok(count(cms()->content->list()) === 3, 'a third page is on disk');
missing($withHidden, '/kryfi', 'but a noindex page is excluded from the sitemap');
ok(substr_count($withHidden, '<loc>') === 2, 'leaving only the two indexable ones');
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
ok(count($cms->content->list()) === 2, 'page list finds both pages');

section('Unknown component does not fatal a live page');
$page['blocks'][] = ['id' => 'ghost', 'type' => 'no_such_component', 'fields' => []];
$html2 = $cms->renderPage($page);
ok(substr_count($html2, '<section') === 5, 'unknown block skipped rather than crashing');

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

summary();
