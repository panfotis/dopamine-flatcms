<?php
/**
 * The test that matters: a client (or anyone with their login) can change
 * values and nothing else. Runs a hostile save, then inspects what actually
 * landed on disk.
 */

declare(strict_types=1);

// The session backs the CSRF token; open it before this harness prints anything.
session_start();

require __DIR__ . '/lib.php';

use Symfony\Component\Yaml\Yaml;

putenv('AUTH_DEV_BYPASS=1');   // explicit, exactly as .ddev/config.yaml does it

$_SESSION['csrf'] = 'test-token';
$hostile = require __DIR__ . '/fixtures/hostile_save.php';

$file   = content_root() . '/pages/el/home.yml';
$backup = $file . '.bak';
copy($file, $backup);

$before = Yaml::parseFile($file);

// The hero's stored image, read rather than hardcoded: this is demo content,
// and someone replacing the picture in the panel must not fail the suite.
$storedImage = $before['blocks'][0]['fields']['image'];

// Correct baseline: this save is legitimate in every way except its payload.
$response = admin_post($hostile('test-token', (string) hash_file('sha256', $file), $storedImage['src']));

$blockNo = static fn (array $page, string $id): int
    => (int) array_search($id, array_column($page['blocks'], 'id'), true);

$after = Yaml::parseFile($file);
$hero  = $after['blocks'][0]['fields'];
$intro = $after['blocks'][1]['fields'];

section('Save completed');
// Stronger than the old "the child process exited 0": a save that fell into the
// error page also exited 0. Only the write path redirects.
ok($response->getStatusCode() === 303, 'the save was accepted and redirected (303), not refused');
ok(count($after['blocks']) === count($before['blocks']), 'block count unchanged (' . count($before['blocks']) . ')');

section('Structure is not editable');
ok($after['blocks'][0]['type'] === 'hero', 'posting blocks[hero][type] did not retype the component');
ok($after['slug'] === '/', 'posting slug did not move the page');
ok(array_column($after['blocks'], 'id') === array_column($before['blocks'], 'id'), 'block ids and order untouched');
ok(!in_array('injected_block', array_column($after['blocks'], 'id'), true), 'posting an unknown block id added nothing');
ok(!array_key_exists('evil', $hero), 'field not present in schema.yml was dropped');

section('Locked fields stay locked');
ok($hero['align'] === 'center', 'editable:false field ignored on save (still "center", posted "start")');

section('Legitimate edits go through');
ok($hero['subheading'] === 'Νέος υπότιτλος από τον πελάτη.', 'subheading updated');
ok($after['title'] === 'Αρχική σελίδα bold', 'page title trimmed, whitespace collapsed, tags stripped');

section('Text fields are sanitised');
missing($hero['heading'], '<script', 'script tag stripped from text field');
missing($hero['heading'], 'onerror', 'inline event handler stripped');
ok($hero['heading'] === 'Καλημέρα alert(1)', 'heading reduced to plain text: ' . $hero['heading']);
ok(mb_strlen($hero['heading']) <= 70, 'max length respected');

section('A site-declared field type is sanitised as plain text, never passed through');

// A type this engine has no arm for — a site's own `color` or `date` — falls to
// sanitise()'s default, and that default must stay `plain()`. If it ever became
// a pass-through, config.field_types would turn into a way for a component
// author to opt a field out of sanitisation entirely, which is the one thing
// the schema-driven save exists to prevent.
$custom = Dopamine\FlatCms\Fields::sanitise(
    ['type' => 'color'],
    '<script>alert(1)</script><b onerror=x>#ff0000</b>'
);
ok(is_string($custom), 'an undeclared type stores a string, never a structure it never validated');
missing($custom, '<script', 'script tag stripped');
missing($custom, 'onerror', 'inline event handler stripped');
ok($custom === 'alert(1)#ff0000', 'reduced to plain text: ' . $custom);

// The same ceilings apply, so `max:` on a custom type is not decoration.
ok(
    Dopamine\FlatCms\Fields::sanitise(['type' => 'date', 'max' => 4], '2026-08-26') === '2026',
    'and max: is enforced on it like any other text field'
);

// An array posted at a scalar type is dropped rather than stored: sanitise()
// casts non-scalars to '' before the match, which is what keeps a forged
// nested payload from reaching disk under a type nobody wrote a walk for.
ok(
    Dopamine\FlatCms\Fields::sanitise(['type' => 'color'], ['nested' => ['deep' => 'x']]) === '',
    'a forged nested payload under a custom type is dropped, not stored'
);

section('Link fields reject hostile URLs');
ok($hero['cta_url'] === ['page' => '', 'url' => '', 'target' => '_self'],
    'javascript: URL rejected outright in both halves, and a forged target falls back to the same tab');

section('An image is an object, and the server owns half of it');
// The client owns src and alt. width, height and `decorative` are the
// server's, and posting them has to be a no-op rather than a validation error
// — the browser sends the whole map back on every save.
ok(is_array($hero['image']), 'an image field stores a map, not a path');
ok(array_keys($hero['image']) === ['src', 'alt', 'width', 'height'],
    'with exactly the declared sub-keys: ' . implode(', ', array_keys($hero['image'])));
ok(!array_key_exists('evil', $hero['image']), 'an undeclared sub-key is dropped, exactly as at the top level');
ok(!array_key_exists('decorative', $hero['image']),
    'a forged `decorative` never lands: it is a schema value, not a request value');
ok($hero['image']['width'] === $storedImage['width'] && $hero['image']['height'] === $storedImage['height'],
    'a forged 99999x99999 is ignored in favour of the pair already on disk for that src ('
    . $hero['image']['width'] . 'x' . $hero['image']['height'] . ')');
ok($hero['image']['width'] > 0, 'which is a real measurement, not a silent zero');
ok($hero['image']['alt'] === '',
    'hero declares decorative: true, so alt is empty by declaration — posting one changes nothing');
ok($intro['image']['src'] === '', 'an image src outside media_bases is rejected — no open image proxy');

section('Alt cannot be forgotten on an image that carries information');
$withImage = static fn (array $image): array => [
    'action'   => 'save',
    'csrf'     => 'test-token',
    'page'     => 'home',
    'baseline' => (string) hash_file('sha256', $file),
    'blocks'   => ['intro' => ['image' => $image]],
];
// Any src the media guard accepts will do; take the one the page already has
// rather than naming a file someone may replace from the panel.
$real = (string) $storedImage['src'];

$noAlt = admin_post($withImage(['src' => $real, 'alt' => '   ']));
ok($noAlt->getStatusCode() === 422, 'a non-decorative image with a file and no description is refused');
contains((string) $noAlt->getContent(), cms()->lang->t('field.alt'), 'and the refusal names the field the client has to fill in');
ok(Yaml::parseFile($file)['blocks'][1]['fields']['image']['src'] === '', 'nothing was written by the refused save');

// A refusal that empties the form is the same support call as a lost save, so
// the refusal *is* the form, with the message on the field that caused it.
$noAltBody = (string) $noAlt->getContent();
contains($noAltBody, 'name="blocks[intro][image][alt]"', 'and the client is handed the form back, not an error page');
contains($noAltBody, 'name="blocks[intro][image][src]" value="' . $real . '"',
    'holding the image they picked, so the upload is not lost with the message');
contains($noAltBody, 'has-error', 'with the offending field marked inline');
ok(count(Yaml::parseFile($file)['blocks'][1]['fields']) > 0, 'and still nothing on disk');

// The condition matters: Phase 6's og_image defaults to an empty map on every
// page, and an unconditional rule would make every page unsaveable.
$empty = admin_post($withImage(['src' => '', 'alt' => '']));
ok($empty->getStatusCode() === 303, 'while an image field left empty saves fine');

$ok = admin_post($withImage(['src' => $real, 'alt' => 'Η ομάδα μας στο γραφείο']));
ok($ok->getStatusCode() === 303, 'and a described image saves');
ok(Yaml::parseFile($file)['blocks'][1]['fields']['image']['alt'] === 'Η ομάδα μας στο γραφείο', 'with its description');

section('The panel offers an alt input exactly where the save path demands one');
$imgForm = (string) admin_get(['action' => 'edit', 'page' => 'home'])->getContent();
contains($imgForm, 'name="blocks[intro][image][alt]"', 'a meaningful image gets an alt input');
missing($imgForm, 'name="blocks[hero][image][alt]"', 'a decorative one gets none — asking would produce "photo"');
contains($imgForm, 'name="blocks[hero][image][src]"', 'but it still gets its file picker');

section('The seo block goes through the same walk as a component');
// `seo` is not a component — no template, never repeats — so the risk is that
// it quietly grows a second, softer save path. Every case here is one the block
// walk already refuses, asked again of the page-level map.
$saveSeo = static fn (array $seo, string $csrf = 'test-token'): array => [
    'action'   => 'save',
    'csrf'     => $csrf,
    'page'     => 'home',
    'baseline' => (string) hash_file('sha256', $file),
    'seo'      => $seo,
];
$seoOf = static fn (): array => (array) (Yaml::parseFile($file)['seo'] ?? []);

$hostileSeo = admin_post($saveSeo([
    'title'       => 'Τίτλος <script>alert(1)</script> με ' . str_repeat('π', 100),
    'description' => "Περιγραφή\n\n\n<b>με tags</b>",
    'noindex'     => 'definitely-not-a-boolean',
    'canonical'   => 'javascript:alert(document.cookie)',
    'og_image'    => ['src' => 'https://evil.tld/x.jpg', 'alt' => 'Δεν έχει σημασία'],
    'evil'        => 'undeclared page-level key',
]));
ok($hostileSeo->getStatusCode() === 303, 'the save is accepted — these are forged values, not a forged request');

$seo = $seoOf();
ok(array_keys($seo) === ['title', 'description', 'og_image', 'noindex', 'canonical'],
    'the stored map holds the schema key set and nothing else: ' . implode(', ', array_keys($seo)));
ok(!array_key_exists('evil', $seo), 'an undeclared key is dropped, exactly as inside a block');
missing($seo['title'], '<script', 'seo.title is plain text');
ok(mb_strlen($seo['title']) <= 60, 'and cut to its 60-character max (' . mb_strlen($seo['title']) . ')');
missing($seo['description'], '<b>', 'seo.description is plain text too');
ok($seo['noindex'] === false, 'a value that is not a truthy literal stores false, not the string itself');
ok($seo['og_image']['src'] === '', 'an og_image src outside media_bases is rejected — the open-proxy guard is the same one');
ok(array_keys($seo['og_image']) === ['src', 'alt', 'width', 'height'], 'and og_image is an image map, with the server-owned half intact');

section('A url field rejects everything link() rejects');
// A new field type, so a hostile case per CLAUDE.md. `canonical` is the only
// place a typed URL is stored, and it goes straight into a <link> in the head:
// a canonical pointing at a host the client does not own hands that host the
// page's ranking, silently.
foreach ([
    'javascript:alert(1)'  => 'a javascript: URL',
    'data:text/html,x'     => 'a data: URL',
    '//evil.gr/x'          => 'a protocol-relative host',
    '/\\evil.gr'           => 'the backslash variant browsers normalise to //',
] as $bad => $why) {
    as_user('admin@example-domain.com', 'POST', $saveSeo(['canonical' => $bad]));
    ok($seoOf()['canonical'] === '', 'refused: ' . $why . ' (stored: "' . $seoOf()['canonical'] . '")');
}

as_user('admin@example-domain.com', 'POST', $saveSeo(['canonical' => 'https://example-domain.com/selida']));
ok($seoOf()['canonical'] === 'https://example-domain.com/selida', 'while a real absolute URL is stored');
as_user('admin@example-domain.com', 'POST', $saveSeo(['canonical' => '/selida']));
ok($seoOf()['canonical'] === '/selida', 'and so is a site-relative path');

section('seo.canonical is admin-only, and forging it does not help');
// The rest of the block costs a client a worse search result if they get it
// wrong. A canonical pointing at the wrong URL deindexes the page.
ok(\Dopamine\FlatCms\Components::seoFields()['canonical']['editable'] === 'admin',
    'canonical is declared editable: admin');

$editorSeo = as_user('editor@example-domain.com', 'POST', $saveSeo(['canonical' => 'https://evil.gr/']));
ok($editorSeo->getStatusCode() === 303, 'an editor\'s save is accepted');
ok($seoOf()['canonical'] === '/selida', 'but the canonical is unchanged (' . $seoOf()['canonical'] . '), even though it was posted');

$editorForm = (string) as_user('editor@example-domain.com', 'GET', ['action' => 'edit', 'page' => 'home'])->getContent();
ok((bool) preg_match('/id="seo-canonical"[^>]*readonly/', $editorForm), 'and the form locks it for them, as the save path would');
$adminForm = (string) as_user('admin@example-domain.com', 'GET', ['action' => 'edit', 'page' => 'home'])->getContent();
ok(!preg_match('/id="seo-canonical"[^>]*readonly/', $adminForm), 'while an admin may type in it');

// The client half of the block is still theirs.
as_user('editor@example-domain.com', 'POST', $saveSeo(['description' => 'Γραμμένο από τον πελάτη']));
ok($seoOf()['description'] === 'Γραμμένο από τον πελάτη', 'the fields that are the client\'s really are editable by them');

section('The share image is decorative, so it never blocks a save');
// og_image declares decorative: true — the share card carries the title and
// the description as text beside it. That makes alt empty by declaration, and
// a forged one no more effective than a forged `decorative` on a block image.
ok(\Dopamine\FlatCms\Components::seoFields()['og_image']['decorative'] === true,
    'og_image is declared decorative');

$ogAlt = as_user('admin@example-domain.com', 'POST', $saveSeo([
    'og_image' => [
        'src'    => (string) $storedImage['src'],
        'alt'    => 'Κείμενο που δεν πρέπει να αποθηκευτεί',
        'width'  => 99999,
        'height' => 99999,
    ],
]));
ok($ogAlt->getStatusCode() === 303, 'an og_image with a src and no description saves rather than being refused');
ok($seoOf()['og_image']['src'] === (string) $storedImage['src'], 'the src is stored');
ok($seoOf()['og_image']['alt'] === '', 'while a posted alt lands nowhere — it is empty by declaration');
// This field has no upload record and nothing previously on disk beside that
// src, so the honest answer is 0 rather than the forged pair or a guess copied
// from another field's image. picture.twig renders 0 as no attribute at all.
ok($seoOf()['og_image']['width'] === 0 && $seoOf()['og_image']['height'] === 0,
    'and a forged 99999x99999 stores 0 — dimensions are server-derived here too ('
    . $seoOf()['og_image']['width'] . 'x' . $seoOf()['og_image']['height'] . ')');

// A block image is a different question and still refuses: that one carries
// information, and this is the case the decorative flag exists to distinguish.
$blockNoAlt = admin_post($withImage(['src' => (string) $storedImage['src'], 'alt' => '   ']));
ok($blockNoAlt->getStatusCode() === 422, 'while a meaningful block image with no description is still refused');

section('A list is bounded before it is walked, not after');
$faq = $after['blocks'][$blockNo($after, 'faq')]['fields'];
ok(count($faq['questions']) === 20, '200 posted rows into a max: 20 list truncates to 20, not 200');
ok(array_is_list($faq['questions']),
    'attacker-chosen keys cannot turn the list into a YAML map — array_values() runs before the item loop');
ok(!array_key_exists('evil', $faq['questions'][0]), 'an undeclared sub-field is dropped inside a row, exactly as at the top level');
ok(array_keys($faq['questions'][0]) === ['question', 'answer'], 'a row holds the sub-schema and nothing else');
missing($faq['questions'][0]['question'], '<script', 'a row is sanitised by its own field type');
missing($faq['questions'][0]['answer'], 'onclick', 'and so is its richtext');

// Cheap proof that the bound is applied *before* the walk rather than after:
// 200 rows of richtext through the HTML sanitiser is measurable, 20 is not.
$raw = (string) file_get_contents($file);
ok(substr_count($raw, 'question:') === 20, 'and only 20 rows reached the file');

section('A boolean stores a real YAML bool');
ok($faq['open_first'] === false, 'a value that is not a recognised truthy literal is false, not the string itself');
ok(str_contains($raw, 'open_first: false'), 'and it lands in the YAML as a bool: ' . trim(explode("\n", explode('open_first:', $raw)[1])[0]));

$boolOn = admin_post([
    'action' => 'save', 'csrf' => 'test-token', 'page' => 'home',
    'baseline' => (string) hash_file('sha256', $file),
    'blocks' => ['faq' => ['open_first' => '1']],
]);
ok($boolOn->getStatusCode() === 303, 'a checked box saves');
ok(Yaml::parseFile($file)['blocks'][$blockNo(Yaml::parseFile($file), 'faq')]['fields']['open_first'] === true,
    'as a real true, so a template can branch on it without parsing strings');

section('A link stores a page id, and a slug rename cannot break it');
/** @param array<string, string> $link */
$linkTo = static fn (array $link): array => [
    'action' => 'save', 'csrf' => 'test-token', 'page' => 'home',
    'baseline' => (string) hash_file('sha256', $file),
    'blocks' => ['hero' => ['cta_url' => $link, 'cta_label' => 'Επικοινωνία']],
];
$toPage = static fn (string $v): array => $linkTo(['page' => $v]);
$heroOf = static fn (): array => Yaml::parseFile($file)['blocks'][0]['fields'];
$linkOf = static fn (): array => $heroOf()['cta_url'];

admin_post($toPage('epikoinonia'));
ok($linkOf()['page'] === 'epikoinonia', 'a real page id is stored as the id, not as a URL');
ok(array_keys($linkOf()) === ['page', 'url', 'target'],
    'a link is a map with exactly the declared sub-keys: ' . implode(', ', array_keys($linkOf())));

// The page half takes an id and nothing that merely looks like one. Every case
// here was refused when a link was a bare string, and still is.
foreach ([
    'javascript:alert(1)'  => 'a javascript: URL',
    '//evil.gr'            => 'a protocol-relative host',
    'https://evil.gr/x'    => 'an absolute URL',
    '/epikoinonia'         => 'a slug — the id is the filename, and slugs move',
    '../../config'         => 'a traversal',
    'home.yml'             => 'a filename',
] as $bad => $why) {
    admin_post($toPage($bad));
    ok($linkOf()['page'] === '', 'refused as a page id: ' . $why . ' (stored: "' . $linkOf()['page'] . '")');
}

// The custom-address half is the href rule richtext uses, so the schemes that
// are dangerous in an <a> are dangerous here for the same reason.
foreach ([
    'javascript:alert(1)' => 'a javascript: URL',
    'data:text/html,x'    => 'a data: URL',
    '//evil.gr'           => 'a protocol-relative host',
    '/\\evil.gr'          => 'a backslash variant browsers normalise to //',
] as $bad => $why) {
    admin_post($linkTo(['url' => $bad]));
    ok($linkOf()['url'] === '', 'refused as a custom address: ' . $why . ' (stored: "' . $linkOf()['url'] . '")');
}

admin_post($linkTo(['url' => 'https://example.gr/profil']));
ok($linkOf()['url'] === 'https://example.gr/profil', 'an external address a client really typed is kept');
ok($linkOf()['page'] === '', 'and no page is invented beside it');

admin_post($linkTo(['url' => '/epikoinonia']));
ok($linkOf()['url'] === '/epikoinonia',
    'a site-relative path is legitimate here — it is the escape hatch, not the picker');

// Two destinations in one field is a value nobody can read back.
admin_post($linkTo(['page' => 'epikoinonia', 'url' => 'https://example.gr']));
ok($linkOf()['page'] === 'epikoinonia' && $linkOf()['url'] === '',
    'a picked page wins and clears the custom address beside it');

// target is written straight into an attribute, so it is an allowlist.
admin_post($linkTo(['page' => 'epikoinonia', 'target' => '_blank']));
ok($linkOf()['target'] === '_blank', 'a declared target is stored');
foreach ([
    '_blank" onclick=alert(1)' => 'an attribute-breaking target',
    'javascript:alert(1)'      => 'a javascript: target',
    '_BLANK'                   => 'a target that differs only in case',
] as $bad => $why) {
    admin_post($linkTo(['page' => 'epikoinonia', 'target' => $bad]));
    ok($linkOf()['target'] === '_self',
        'refused, falling back to the same tab: ' . $why . ' (stored: "' . $linkOf()['target'] . '")');
}

// map() is the only schema walk, so a link's sub-keys are filtered by the same
// rule as an image's — there is no second implementation to forget.
admin_post($linkTo(['page' => 'epikoinonia', 'evil' => 'x']));
ok(!array_key_exists('evil', $linkOf()), 'an undeclared sub-key is dropped, exactly as inside an image');

// The whole point of storing the id: the href follows the slug wherever it goes.
$other = content_root() . '/pages/el/epikoinonia.yml';
copy($other, $other . '.bak');

$renamed = cms();
$page = $renamed->content->load('epikoinonia');
$page['slug'] = '/nea-epikoinonia';
$renamed->content->save('epikoinonia', $page);

admin_post($toPage('epikoinonia'));
$rendered = cms()->renderPage(cms()->content->load('home'));
contains($rendered, 'href="/nea-epikoinonia"', 'renaming a slug leaves the internal link intact, pointing at the new slug');
// Scoped to the button, not the page: richtext puts target="_blank" on its own
// external links, so a page-wide search proves nothing about this field.
contains($rendered, '<a class="btn" href="/nea-epikoinonia">Επικοινωνία</a>',
    'and the default target adds no attribute at all');

// A new tab and its rel travel together or the opener is handed over.
admin_post($linkTo(['page' => 'epikoinonia', 'target' => '_blank']));
contains(
    cms()->renderPage(cms()->content->load('home')),
    'target="_blank" rel="noopener noreferrer"',
    'a link set to open in a new tab renders rel="noopener noreferrer" with it'
);

// The custom address is what a client uses for somewhere this site does not
// own. The picker is cleared in the same post — a field the form does not send
// keeps what is stored, which is what makes a forged partial POST a no-op.
admin_post($linkTo(['page' => '', 'url' => 'https://example.gr/profil', 'target' => '_blank']));
contains(
    cms()->renderPage(cms()->content->load('home')),
    'href="https://example.gr/profil" target="_blank" rel="noopener noreferrer"',
    'a custom address renders as the href, with its target beside it'
);

// Byte-for-byte, comments included: save() rewrites the file, and a suite run
// must not quietly edit the developer's own content.
rename($other . '.bak', $other);
array_map('unlink', glob(content_root() . '/.revisions/el/epikoinonia.*.yml') ?: []);

// An id that no longer resolves must not become a dead href.
admin_post($toPage('deleted-page'));
$dead = cms()->renderPage(cms()->content->load('home'));
missing($dead, 'href="deleted-page"', 'an id that no longer resolves is never rendered as an href');
contains($dead, '<span class="btn is-dead">Επικοινωνία</span>', 'it renders as plain text instead');
contains((string) admin_get(['action' => 'edit', 'page' => 'home'])->getContent(),
    cms()->lang->t('edit.dead_link', 'deleted-page'), 'and the panel flags it so it can actually be fixed');

section('Rich text is whitelisted');
missing($intro['body'], '<script', 'script tag removed');
missing($intro['body'], '<style', 'style tag removed');
missing($intro['body'], 'onclick', 'attribute stripped from allowed tag');
missing($intro['body'], '<font', 'Word-paste font tag removed');
missing($intro['body'], 'javascript:', 'javascript: link removed');
contains($intro['body'], '<strong>έντονο</strong>', 'allowed formatting preserved');
contains($intro['body'], '<a href="https://example.gr" target="_blank" rel="noopener noreferrer">', 'external link kept and made safe');
missing($intro['body'], '&nbsp;</p>', 'empty paragraph dropped');

section('Revisions');
$revs = glob(content_root() . '/.revisions/el/home.*.yml') ?: [];
ok(count($revs) >= 1, 'a revision snapshot was written before saving');

section('Page still renders after a hostile save');
$html = cms()->renderPage(cms()->content->load('home'));
missing($html, '<script>alert', 'no injected script in the rendered page');
contains($html, 'Νέος υπότιτλος', 'edited copy is live');

// ── Roles ───────────────────────────────────────────────────────────────────
// Everything below runs against a real Cloudflare Access token, because the
// question Phase 3 added — "this address authenticated, but may it be here?" —
// only has an honest answer on the path a real request takes.

$ADMIN   = 'admin@example-domain.com';   // users.yml: admin
$EDITOR  = 'editor@example-domain.com';    // users.yml: editor
$UNKNOWN = 'kanenas@example.gr';    // not in users.yml at all

/** The forged save an editor would send to change an editable:admin field. */
$forgeEmail = static fn (string $value): array => [
    'action'   => 'save',
    'csrf'     => 'test-token',
    'page'     => 'home',
    'baseline' => (string) hash_file('sha256', content_root() . '/pages/el/home.yml'),
    'blocks'   => ['contact' => ['email' => $value, 'heading' => 'Πείτε μας τι χρειάζεστε']],
];

$emailOf = static function () use ($file, $blockNo): string {
    $page = Yaml::parseFile($file);

    return (string) $page['blocks'][$blockNo($page, 'contact')]['fields']['email'];
};

section('An authenticated address is not automatically a user');
$stranger = as_user($UNKNOWN, 'GET', ['action' => 'edit', 'page' => 'home']);
ok($stranger->getStatusCode() === 403, 'an email absent from users.yml is refused, not made an implicit editor');
missing((string) $stranger->getContent(), 'name="blocks[hero][heading]"', 'and sees no edit form');
missing((string) $stranger->getContent(), 'Cloudflare Access', 'the refusal does not tell them to log in again — they already did');
// This is the one panel page a refused client ever sees, and the person seeing
// it is usually a real editor nobody added to users.yml, not an intruder.
contains((string) $stranger->getContent(), '<!DOCTYPE html>', 'the 403 is a whole page, not a bare fragment');
contains((string) $stranger->getContent(), 'Demo Πελάτη', 'rendered through the panel layout, so it looks refused rather than broken');

$listed = as_user($EDITOR, 'GET', ['action' => 'edit', 'page' => 'home']);
ok($listed->getStatusCode() === 200, 'a listed address gets in');
contains((string) $listed->getContent(), $EDITOR, 'and the panel names who it thinks they are');

section('editable:admin is refused on save, not merely disabled in the UI');
ok(cms()->components->get('contact_cta')['fields']['email']['editable'] === 'admin',
    'contact_cta.email is declared editable: admin');

$was = $emailOf();
$forged = as_user($EDITOR, 'POST', $forgeEmail('editor@evil.gr'));
ok($forged->getStatusCode() === 303, 'the editor\'s save is accepted — this is a forged field, not a forged request');
ok($emailOf() === $was, 'but the editable:admin field is unchanged (' . $emailOf() . '), even though it was posted');

$allowed = as_user($ADMIN, 'POST', $forgeEmail('nea@example.gr'));
ok($allowed->getStatusCode() === 303, 'an admin posting the same field is accepted');
ok($emailOf() === 'nea@example.gr', 'and for an admin the field really is written');

section('The edit form locks what the save path would refuse');
$editorForm = (string) as_user($EDITOR, 'GET', ['action' => 'edit', 'page' => 'home'])->getContent();
$adminForm  = (string) as_user($ADMIN, 'GET', ['action' => 'edit', 'page' => 'home'])->getContent();
ok((bool) preg_match('/id="contact-email"[^>]*readonly/', $editorForm), 'an editor sees the admin-only field read-only');
ok(!preg_match('/id="contact-email"[^>]*readonly/', $adminForm), 'an admin does not');
missing($editorForm, 'action=revisions', 'an editor is not offered the revisions link');
contains($adminForm, 'action=revisions', 'an admin is');

section('Revisions are admin-only, and forging the action does not help');
$revs = cms()->content->revisions('home');
ok(count($revs) >= 1, 'the saves above left revisions to list');

$adminList = as_user($ADMIN, 'GET', ['action' => 'revisions', 'page' => 'home']);
ok($adminList->getStatusCode() === 200, 'an admin can list revisions');
contains((string) $adminList->getContent(), $revs[0]['file'], 'and sees a restorable version');

$editorList = as_user($EDITOR, 'GET', ['action' => 'revisions', 'page' => 'home']);
ok($editorList->getStatusCode() === 403, 'an editor forging ?action=revisions gets 403, not 400');
missing((string) $editorList->getContent(), $revs[0]['file'], 'and no revision name leaks in the refusal');
missing((string) $editorList->getContent(), 'Νέος υπότιτλος', 'nor any revision content');

$editorRestore = as_user($EDITOR, 'POST', [
    'action' => 'restore', 'csrf' => 'test-token', 'page' => 'home', 'revision' => $revs[0]['file'],
]);
ok($editorRestore->getStatusCode() === 403, 'an editor forging action=restore gets 403');
ok($emailOf() === 'nea@example.gr', 'and nothing was written by the attempt');

$noCsrf = as_user($ADMIN, 'POST', [
    'action' => 'restore', 'csrf' => 'forged', 'page' => 'home', 'revision' => $revs[0]['file'],
]);
contains((string) $noCsrf->getContent(), cms()->lang->t('err.session'), 'even an admin needs a CSRF token to restore');
ok($noCsrf->getStatusCode() === 400, 'and the forged restore is refused');

section('The settings screen is admin-only, and never prints a credential');
$adminSettings = as_user($ADMIN, 'GET', ['action' => 'settings']);
ok($adminSettings->getStatusCode() === 200, 'an admin can read the settings screen');

$editorSettings = as_user($EDITOR, 'GET', ['action' => 'settings']);
ok($editorSettings->getStatusCode() === 403, 'an editor forging ?action=settings gets 403');
missing((string) $editorSettings->getContent(), 'base_url', 'and no configuration leaks in the refusal');

// The screen renders whatever config.php holds, so the only honest test is to
// put a recognisable value in every credential key and grep the whole page —
// including the Access `aud`, which the harness sets to a distinctive string.
[$secretConfig, $sign] = access();
$SECRET = 'SECRET-VALUE-THAT-MUST-NEVER-RENDER';
$secretConfig['r2']['secret_key']        = $SECRET;
$secretConfig['r2']['access_key']        = $SECRET;
$secretConfig['turnstile']['secret']     = $SECRET;
$secretConfig['cloudflare']['api_token'] = $SECRET;
$secretConfig['form']['dsn']             = 'smtp://someone:' . $SECRET . '@mail.example.gr';

$leak = (string) admin(
    \Symfony\Component\HttpFoundation\Request::create('/admin.php', 'GET', ['action' => 'settings'], [], [], [
        'HTTP_CF_ACCESS_JWT_ASSERTION' => $sign($ADMIN),
    ]),
    $secretConfig
)->getContent();

missing($leak, $SECRET, 'no credential value reaches the page, in any section');
missing($leak, 'smtp://someone', 'not even the half of a DSN that is not the password');
missing($leak, $secretConfig['auth']['aud'], 'nor the Access aud, which identifies the application');
contains($leak, cms()->lang->t('settings.set'), 'they are reported as set instead of shown');
// auth.dev_bypass matches the credential pattern on its name and is a boolean.
// Masking it would hide the one value on this screen someone urgently needs to
// read — "is authentication switched off on this box" — behind "set".
contains($leak, cms()->lang->t('settings.off'), 'a boolean is never masked, whatever its key is called');
// The point of the screen: what is not a secret is legible.
contains($leak, 'base_url', 'while ordinary settings are named');
contains($leak, (string) $secretConfig['site']['name'], 'and their values shown');

section('A revision name is a filename, never a path');
foreach ([
    '../../pages/home.yml'                  => 'traversal',
    'home.20260101-000000-aaaaaa.yml/../x'  => 'traversal past a valid-looking name',
    'epikoinonia.20260101-000000-aaaaaa.yml' => "another page's history",
    'home.yml'                              => 'the live page file itself',
] as $name => $why) {
    $r = as_user($ADMIN, 'POST', [
        'action' => 'restore', 'csrf' => 'test-token', 'page' => 'home', 'revision' => $name,
    ]);
    ok($r->getStatusCode() === 400, 'restore refuses ' . $why);
    missing((string) $r->getContent(), '/var/www', 'and the refusal leaks no path (' . $why . ')');
}

section('Restore re-runs the sanitiser instead of copying the file back');
// A revision written *before* the allowlist tightened: hostile HTML sitting on
// disk in a file the panel is about to put back. copy() would land it verbatim,
// and text_image renders body with |raw.
$revDir = content_root() . '/.revisions/el';
$poisoned = Yaml::parseFile($file);
$poisoned['title'] = 'Παλιός <b>τίτλος</b>';
$poisoned['slug'] = '/hijacked';
$poisoned['blocks'][0]['fields']['heading'] = 'Παλιά επικεφαλίδα <script>alert(1)</script>';
$poisoned['blocks'][0]['fields']['align'] = 'start';                     // editable: false
$poisoned['blocks'][1]['fields']['body'] = '<p onclick="steal()">Παλιό κείμενο'
    . '<script>fetch("//evil.gr")</script><a href="javascript:alert(1)">κακός</a></p>';
$poisoned['blocks'][$blockNo($poisoned, 'contact')]['fields']['email'] = 'palio@example.gr'; // editable: admin
$poisoned['blocks'][] = ['id' => 'ghost', 'type' => 'hero', 'fields' => ['heading' => 'Δεν υπάρχω']];
$poisonedName = 'home.20260101-000000-abcdef.yml';
file_put_contents($revDir . '/' . $poisonedName, Yaml::dump($poisoned, 6, 2));

$namesBefore = array_column(cms()->content->revisions('home'), 'file');

$restored = as_user($ADMIN, 'POST', [
    'action' => 'restore', 'csrf' => 'test-token', 'page' => 'home', 'revision' => $poisonedName,
]);
ok($restored->getStatusCode() === 303, 'the restore is accepted');

$after2 = Yaml::parseFile($file);
$hero2  = $after2['blocks'][0]['fields'];
$intro2 = $after2['blocks'][1]['fields'];

missing($hero2['heading'], '<script', 'a script tag in the revision is stripped on the way back in');
ok($hero2['heading'] === 'Παλιά επικεφαλίδα alert(1)', 'the restored text went through the same sanitiser as a save: ' . $hero2['heading']);
missing($intro2['body'], '<script', 'richtext from the revision is re-sanitised, not trusted');
missing($intro2['body'], 'onclick', 'and its attributes are stripped');
missing($intro2['body'], 'javascript:', 'and its hostile hrefs are dropped');
contains($intro2['body'], 'Παλιό κείμενο', 'while the legitimate text is restored');
ok($after2['title'] === 'Παλιός τίτλος', 'the title is restored, sanitised');

ok($after2['slug'] === '/', 'a revision cannot move the page — structure comes from the file, not the revision');
ok(count($after2['blocks']) === count($before['blocks']), 'nor add a block that is not in the file');
ok($hero2['align'] === 'center', 'an editable:false field is not overwritten by a revision either');
ok($after2['blocks'][$blockNo($after2, 'contact')]['fields']['email'] === 'palio@example.gr', 'but an editable:admin field is, because restore is an admin flow');

$html2 = cms()->renderPage(cms()->content->load('home'));
missing($html2, '<script>alert', 'and the restored page renders with nothing injected');
missing($html2, 'evil.gr', 'nor any laundered link');

// Not a count: snapshot() keeps only the last 10, and by this point in the run
// there are already 10, so the total cannot grow. Ask whether a *new* name
// appeared instead — and by name rather than by position, because two
// snapshots inside the same second sort on their random suffix.
ok(array_diff(array_column(cms()->content->revisions('home'), 'file'), $namesBefore) !== [],
    'the version being replaced was snapshotted first — a restore is undoable');

section('image_list: a gallery is bounded, and alt is optional in it');
$saveGallery = static fn (array $photos): array => [
    'action'   => 'save',
    'csrf'     => 'test-token',
    'page'     => 'home',
    'baseline' => (string) hash_file('sha256', $file),
    'blocks'   => ['gallery' => ['photos' => $photos]],
];
$photosOf = static function () use ($file, $blockNo): array {
    $page = Yaml::parseFile($file);

    return (array) $page['blocks'][$blockNo($page, 'gallery')]['fields']['photos'];
};
$realSrc = (string) $storedImage['src'];

// The Phase 10 open question, decided: thirty forced descriptions produce
// thirty junk strings, which a screen reader reads out instead of skipping.
$noAltGallery = admin_post($saveGallery([
    ['src' => $realSrc, 'alt' => ''],
    ['src' => $realSrc, 'alt' => 'Με περιγραφή'],
]));
ok($noAltGallery->getStatusCode() === 303, 'a gallery row with no description saves — unlike a standalone image');
ok(count($photosOf()) === 2, 'both rows landed');
ok($photosOf()[1]['alt'] === 'Με περιγραφή', 'and a description that was written is kept');

// Everything else a photo carries is still the server's.
ok(array_keys($photosOf()[0]) === ['src', 'alt', 'width', 'height'],
    'a row has exactly the image sub-keys: ' . implode(', ', array_keys($photosOf()[0])));
ok($photosOf()[0]['width'] === $storedImage['width'], 'with server-derived dimensions, as anywhere else');

// Reordering is a first-class gallery action. The same src is still the same
// server-measured image even when it moves to another row.
$beforeOrder = $photosOf();
admin_post($saveGallery(array_reverse($beforeOrder)));
$reordered = $photosOf();
ok($reordered[0]['width'] === $storedImage['width'] && $reordered[1]['width'] === $storedImage['width'],
    'reordering preserves dimensions by matching the stored src, not the old row number');

$hostileGallery = admin_post($saveGallery([
    ['src' => 'https://evil.gr/x.jpg', 'alt' => 'έξω'],           // outside media_bases
    ['src' => $realSrc, 'alt' => 'ok', 'width' => 99999, 'evil' => 'x'],
    ['src' => '', 'alt' => 'χωρίς αρχείο'],                        // no photo at all
]));
ok($hostileGallery->getStatusCode() === 303, 'the save is accepted — the payload is what gets refused');
$after = $photosOf();
ok(count($after) === 1, 'a row whose src was rejected is dropped, not stored blank: ' . count($after) . ' left');
ok($after[0]['src'] === $realSrc, 'leaving the one legitimate photo');
// Never the posted 99999: a wrong ratio reserves the wrong box, which is worse
// than reserving none. A src already on disk keeps its measured pair.
ok($after[0]['width'] !== 99999, 'a forged width is never stored');
ok($after[0]['width'] === $storedImage['width'],
    'the pair already on disk for that src survives a row change: ' . $after[0]['width']);
ok(!array_key_exists('evil', $after[0]), 'and an undeclared sub-key is dropped, exactly as at the top level');

// Bounded before the loop, so a huge post costs the cut and not 5 000 walks.
$flood = array_fill(0, 200, ['src' => $realSrc, 'alt' => '']);
ok(admin_post($saveGallery($flood))->getStatusCode() === 303, 'a 200-row post is accepted');
ok(count($photosOf()) === 30, 'and cut to the schema max, not stored: ' . count($photosOf()));

section('video_embed: a provider and an id, never HTML');
$saveVideo = static fn (mixed $embed): array => [
    'action'   => 'save',
    'csrf'     => 'test-token',
    'page'     => 'home',
    'baseline' => (string) hash_file('sha256', $file),
    'blocks'   => ['video' => ['embed' => $embed]],
];
$embedOf = static function () use ($file, $blockNo): array {
    $page = Yaml::parseFile($file);

    return (array) $page['blocks'][$blockNo($page, 'video')]['fields']['embed'];
};

admin_post($saveVideo('https://www.youtube.com/watch?v=abcdefghijk&t=90'));
ok($embedOf() === ['provider' => 'youtube', 'id' => 'abcdefghijk'],
    'a YouTube watch URL is parsed to a provider and an id, and the tracking parameter is dropped');

admin_post($saveVideo('https://youtu.be/abcdefghijk'));
ok($embedOf()['id'] === 'abcdefghijk', 'a short link resolves to the same id');

admin_post($saveVideo('https://vimeo.com/123456789'));
ok($embedOf() === ['provider' => 'vimeo', 'id' => '123456789'], 'and Vimeo to its own');

foreach ([
    '<iframe src="https://evil.gr"></iframe>'            => 'pasted iframe HTML',
    'javascript:alert(1)'                                 => 'a javascript: URL',
    'https://evil.gr/watch?v=abcdefghijk'                 => 'the right shape on the wrong host',
    'https://www.youtube.com/watch?v=../../etc/passwd'    => 'a traversal in the id position',
    'https://www.youtube.com/watch?v=abc"onload="x'       => 'an attribute break in the id',
] as $payload => $what) {
    admin_post($saveVideo($payload));
    ok($embedOf() === ['provider' => '', 'id' => ''], $what . ' stores nothing at all');
}

// The id charset *is* the allowlist, so nothing that reaches the template can
// break out of the attribute it is interpolated into.
admin_post($saveVideo('https://www.youtube.com/watch?v=abcdefghijk'));
$videoHtml = cms()->renderPage(cms()->content->load('home'));
contains($videoHtml, 'https://www.youtube-nocookie.com/embed/abcdefghijk', 'the facade points at the no-cookie host');
missing($videoHtml, '<iframe', 'and no iframe is on the page at all until the visitor clicks');

section('video_loop: an MP4 we host, and a poster that is a real image');
$saveLoop = static fn (array $loop): array => [
    'action'   => 'save',
    'csrf'     => 'test-token',
    'page'     => 'home',
    'baseline' => (string) hash_file('sha256', $file),
    'blocks'   => ['video' => ['loop' => $loop]],
];
$loopOf = static function () use ($file, $blockNo): array {
    $page = Yaml::parseFile($file);

    return (array) $page['blocks'][$blockNo($page, 'video')]['fields']['loop'];
};

admin_post($saveLoop(['src' => 'https://evil.gr/big.mp4', 'poster' => ['src' => '', 'alt' => '']]));
ok($loopOf()['src'] === '', 'a video src outside media_bases is rejected — the guard is not image-only');

admin_post($saveLoop([
    'src'    => '/uploads/2026/08/clip.mp4',
    'poster' => ['src' => $realSrc, 'alt' => 'Πρώτο καρέ'],
]));
ok($loopOf()['src'] === '/uploads/2026/08/clip.mp4', 'while one we host is stored');
ok($loopOf()['poster']['alt'] === 'Πρώτο καρέ', 'the poster keeps its description');
// Nothing was uploaded in this session and the stored poster had no src, so
// there is nothing to measure — and unknown is 0, never a guess.
ok($loopOf()['poster']['width'] === 0,
    'and a poster src that was typed rather than uploaded has honestly unknown dimensions');

section('An editor cannot flip the Turnstile toggle, or redirect the leads');
// The plan puts this case next to the forged `editable: admin` field from
// Phase 3, and for the same reason: a spam control the client can switch off is
// not a spam control, and a recipient the client can retype is a lead redirect.
// Both are refused on save, not merely locked in the form.
$contactFile = content_root() . '/pages/el/epikoinonia.yml';
copy($contactFile, $contactFile . '.form.bak');

$formBlock = static function () use ($contactFile, $blockNo): array {
    $page = Yaml::parseFile($contactFile);

    return (array) $page['blocks'][$blockNo($page, 'form')]['fields'];
};
$postForm = static fn (string $email, array $fields): array => [
    'action'   => 'save',
    'csrf'     => 'test-token',
    'page'     => 'epikoinonia',
    'baseline' => (string) hash_file('sha256', $contactFile),
    'blocks'   => ['form' => $fields],
];

$asEditor = as_user($EDITOR, 'POST', $postForm($EDITOR, [
    'heading'   => 'Επικοινωνία',
    'turnstile' => '1',
    'recipient' => 'attacker@evil.gr',
]));
ok($asEditor->getStatusCode() === 303, 'the editor\'s save is accepted — the two forged fields are what is refused');
ok($formBlock()['turnstile'] === false, 'an editor cannot switch Turnstile on by forging the request');
ok($formBlock()['recipient'] === '', 'nor point the leads at their own address');
ok($formBlock()['heading'] === 'Επικοινωνία', 'while the fields that are theirs to edit went through');

$asAdmin = as_user($ADMIN, 'POST', $postForm($ADMIN, [
    'heading'   => 'Επικοινωνία',
    'turnstile' => '1',
    'recipient' => 'leads@example-domain.com',
]));
ok($asAdmin->getStatusCode() === 303, 'an admin save is accepted');
ok($formBlock()['turnstile'] === true, 'and an admin can switch it on');
ok($formBlock()['recipient'] === 'leads@example-domain.com', 'and set the recipient');

// The panel shows the editor the control, locked — the same courtesy every
// other `editable: admin` field gets, over a field that silently vanishes.
$editorForm = (string) as_user($EDITOR, 'GET', ['action' => 'edit', 'page' => 'epikoinonia'])->getContent();
contains($editorForm, 'name="blocks[form][turnstile]"', 'the toggle is rendered for an editor');
contains($editorForm, cms()->lang->t('edit.admin_only'), 'marked admin-only');

rename($contactFile . '.form.bak', $contactFile);
array_map('unlink', glob(content_root() . '/.revisions/el/epikoinonia.*.yml') ?: []);

section('Preview is a render, and obeys every rule a save does');
// A new endpoint that takes a request body and returns HTML earns the same
// cases as the one that writes it. Preview writes nothing — which is exactly
// why it must not become a way to render what a save would refuse.
$previewFile = content_root() . '/pages/el/epikoinonia.yml';
$previewBefore = (string) file_get_contents($previewFile);

$noToken = admin_post(['action' => 'preview', 'page' => 'home', 'csrf' => 'wrong-token']);
ok($noToken->getStatusCode() === 400, 'a preview without the session token is refused');

// `recipient` is `editable: admin`. An editor forging it must see the stored
// address rendered back, not their own — a preview that showed the forged
// value would be a way to confirm a change the save is about to drop.
$editorPreview = (string) as_user($EDITOR, 'POST', [
    'action' => 'preview',
    'csrf'   => 'test-token',
    'page'   => 'epikoinonia',
    'blocks' => ['form' => [
        'heading'   => 'Δικό μου κείμενο',
        'recipient' => 'attacker@evil.gr',
    ]],
])->getContent();
contains($editorPreview, 'Δικό μου κείμενο', "an editor's own field previews");
missing($editorPreview, 'attacker@evil.gr', '...while a field they may not edit keeps the stored value');

// Undeclared keys are dropped by the same walk, so there is nothing for one to
// reach: a value the schema never named cannot appear in the render. The marker
// is deliberately unlike anything else on the page — an earlier case in this
// file stores `alert(1)` as legitimate *text* on home (:65), and a preview that
// renders it is the sanitiser working, not failing.
$undeclaredPreview = (string) admin_post([
    'action' => 'preview',
    'csrf'   => 'test-token',
    'page'   => 'home',
    'blocks' => ['hero' => [
        'heading' => 'Τίτλος',
        'onclick' => 'UNDECLARED-9f2c',
    ]],
])->getContent();
contains($undeclaredPreview, 'Τίτλος', 'a declared field previews');
missing($undeclaredPreview, 'UNDECLARED-9f2c', '...and an undeclared one is dropped, exactly as on save');

ok((string) file_get_contents($previewFile) === $previewBefore,
    'and after all of that, nothing was written to disk');

section('A global is locked down exactly like a page');
// The header and the footer are page files that render on every page. The
// rule does not relax because the file is shared — this is the same hostile
// save, aimed at `_header`.
$headerFile = content_root() . '/pages/el/_header.yml';
$headerBackup = $headerFile . '.lockdown.bak';
copy($headerFile, $headerBackup);

$hostileGlobal = admin_post([
    'action'   => 'save',
    'csrf'     => 'test-token',
    'page'     => '_header',
    'baseline' => (string) hash_file('sha256', $headerFile),
    'blocks'   => [
        'header' => [
            'logo'    => ['src' => '/uploads/2026/08/nope.jpg', 'alt' => 'Λογότυπο'],
            'type'    => 'hero',                    // retype the block
            'id'      => 'somethingelse',           // rename it
            'onclick' => 'alert(1)',                // a field the schema never declared
        ],
        'injected' => ['note' => 'a block that is not in the file'],
    ],
    'title' => 'Χακαρισμένος τίτλος',
]);
ok($hostileGlobal->getStatusCode() === 303, 'the save is accepted — the payload is what gets refused');

$storedHeader = Yaml::parseFile($headerFile);
ok(count($storedHeader['blocks']) === 1, 'no block was added from the request');
ok($storedHeader['blocks'][0]['type'] === 'site_header', 'the block was not retyped');
ok($storedHeader['blocks'][0]['id'] === 'header', 'nor renamed');
ok(!array_key_exists('onclick', $storedHeader['blocks'][0]['fields']), 'an undeclared field is dropped');
ok($storedHeader['title'] === 'Κεφαλίδα', 'and a global\'s title is not client-editable');
// The src guard is the same one: media_bases, not "anything that looks like a path".
ok($storedHeader['blocks'][0]['fields']['logo']['src'] === '/uploads/2026/08/nope.jpg',
    'a src inside media_bases is stored');

$offsite = admin_post([
    'action'   => 'save',
    'csrf'     => 'test-token',
    'page'     => '_header',
    'baseline' => (string) hash_file('sha256', $headerFile),
    'blocks'   => ['header' => ['logo' => ['src' => 'https://evil.gr/logo.png', 'alt' => 'x']]],
]);
ok($offsite->getStatusCode() === 303, 'an off-site logo save is not an error');
ok(Yaml::parseFile($headerFile)['blocks'][0]['fields']['logo']['src'] === '',
    'but the src is refused — a global is not a hole in the open-proxy guard');

rename($headerBackup, $headerFile);
array_map('unlink', glob(content_root() . '/.revisions/el/_header.*.yml') ?: []);

// restore
rename($backup, $file);
// Scoped to the fixture page: a suite run must never wipe real revision history.
array_map('unlink', glob(content_root() . '/.revisions/el/home.*.yml') ?: []);

summary();
