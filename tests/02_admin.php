<?php

declare(strict_types=1);

// A real request has emitted nothing before Admin::handle() runs. This CLI
// harness prints its own results as it goes, so open the session up front.
session_start();

require __DIR__ . '/lib.php';

use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;

putenv('AUTH_DEV_BYPASS=1');   // explicit, exactly as .ddev/config.yaml does it

function render(array $get): string
{
    return (string) admin_get($get)->getContent();
}

section('Page list');
$list = render([]);
contains($list, 'Αρχική', 'home page listed');
contains($list, 'Επικοινωνία', 'contact page listed');
contains($list, '?action=edit&amp;page=home', 'edit link points at the page');

section('Edit form is built from the schema');
$edit = render(['action' => 'edit', 'page' => 'home']);
contains($edit, 'name="blocks[hero][heading]"', 'field input named by block id and field name');
contains($edit, 'name="blocks[intro][body]"', 'richtext field present for the second block');
contains($edit, 'data-max="70"', 'max length exposed to the character counter');
contains($edit, 'name="csrf"', 'CSRF token embedded in the form');
contains($edit, 'Κεντρική ενότητα', 'component label from schema.yml shown as the card title');
contains($edit, 'Διαβάζεται φωναχτά σε όσους χρησιμοποιούν αναγνώστη οθόνης', 'field hint rendered');

section('A component built from schema.yml alone round-trips');
// faq is two files and no registration step: a list, a boolean and a richtext,
// with the panel, the save path and the template all derived from the schema.
contains($edit, 'name="blocks[faq][questions][0][question]"', 'the repeater names rows by index');
contains($edit, 'name="blocks[faq][questions][1][answer]"', 'and renders every stored row');
contains($edit, 'data-row-add="faq-questions"', 'with a control to add one');
contains($edit, 'data-max="20"', 'bounded by the schema max the save path also enforces');
contains($edit, 'Μπορεί ο πελάτης να χαλάσει', 'the item_label titles each row with its own question');
contains($edit, 'name="blocks[faq][open_first]" value="1"', 'the boolean is a checkbox');
contains($edit, 'type="hidden" name="blocks[faq][open_first]" value="0"', 'with a hidden partner, so unchecking really posts');

$_SESSION['csrf'] = 'the-real-token';
$faqFile = dirname(__DIR__) . '/content/pages/el/home.yml';
copy($faqFile, $faqFile . '.admin.bak');

$roundTrip = admin_post([
    'action'   => 'save',
    'csrf'     => 'the-real-token',
    'page'     => 'home',
    'baseline' => (string) hash_file('sha256', $faqFile),
    'blocks'   => ['faq' => [
        'heading'    => 'Ερωτήσεις',
        'open_first' => '0',
        'questions'  => [
            ['question' => 'Πόσο κοστίζει;', 'answer' => '<p>Λιγότερο <strong>απ\' ό,τι νομίζετε</strong>.</p>'],
            ['question' => 'Πότε παραδίδεται;', 'answer' => '<p>Σε δύο εβδομάδες.</p>'],
            ['question' => 'Τρίτη γραμμή, προστέθηκε τώρα', 'answer' => '<p>Νέα.</p>'],
        ],
    ]],
]);
ok($roundTrip->getStatusCode() === 303, 'a save through those exact field names is accepted');

$stored = \Symfony\Component\Yaml\Yaml::parseFile($faqFile);
$faqBlock = $stored['blocks'][array_search('faq', array_column($stored['blocks'], 'id'), true)]['fields'];
ok(count($faqBlock['questions']) === 3, 'the row the client added is on disk');
ok($faqBlock['questions'][2]['question'] === 'Τρίτη γραμμή, προστέθηκε τώρα', 'in the right slot, with its own values');
ok($faqBlock['open_first'] === false, 'and unchecking the box really stored false');

$rendered = cms()->renderPage(cms()->content->load('home'));
contains($rendered, '<summary>Πόσο κοστίζει;</summary>', 'and the template renders it back');
contains($rendered, "<strong>απ' ό,τι νομίζετε</strong>", 'with its richtext intact');
missing($rendered, '<details open>', 'open_first: false leaves the first row closed');

rename($faqFile . '.admin.bak', $faqFile);
array_map('unlink', glob(dirname(__DIR__) . '/content/.revisions/home.*.yml') ?: []);

section('SEO is a collapsed card an editor can ignore entirely');
// Collapsed matters: it is the one card on the form that is optional from top
// to bottom, and an open one pushes the client's actual copy below the fold.
ok((bool) preg_match('/<details class="card">\s*<summary>/', $edit), 'the seo block renders as a <details> card');
missing($edit, '<details class="card" open', 'which is collapsed — no `open` attribute anywhere on it');
ok(strpos($edit, 'name="seo[title]"') < strpos($edit, 'name="blocks[hero][heading]"'),
    'and it sits at the top of the form, above the content blocks');

foreach ([
    'seo[title]', 'seo[description]', 'seo[og_image][src]', 'seo[noindex]', 'seo[canonical]',
] as $field) {
    contains($edit, 'name="' . $field . '"', $field . ' has an input');
}
// The share image is declared decorative: the card already carries the title
// and the description as text, so asking the client to describe the banner
// beside them a second time buys "εικόνα" typed to clear a field.
missing($edit, 'name="seo[og_image][alt]"', 'the share image asks for no description of its own');
contains($edit, 'Κενό = η πρώτη εικόνα της σελίδας', 'and says what happens if it is left empty');
contains($edit, 'Κενό = το πρώτο κείμενο της σελίδας', 'as does the description');
contains($edit, 'δεν είναι κλείδωμα', 'and every hint says what the field does, not that it "helps SEO"');
contains($edit, 'type="hidden" name="seo[noindex]" value="0"',
    'noindex has the hidden partner every boolean gets, so unchecking really posts');

// The whole point of collapsed-and-optional: a save that never opened the card
// must go through. The og_image posts as an empty map, and alt is required only
// when src is set — an unconditional rule would make every page unsaveable.
$_SESSION['csrf'] = 'the-real-token';
$seoFile = dirname(__DIR__) . '/content/pages/el/home.yml';
copy($seoFile, $seoFile . '.seo.bak');

$ignored = admin_post([
    'action'   => 'save',
    'csrf'     => 'the-real-token',
    'page'     => 'home',
    'baseline' => (string) hash_file('sha256', $seoFile),
    'title'    => 'Αρχική',
    'seo'      => [
        'title'       => '',
        'description' => 'Δείγμα σελίδας για το FlatCMS.',
        'og_image'    => ['src' => '', 'alt' => ''],
        'noindex'     => '0',
        'canonical'   => '',
    ],
]);
ok($ignored->getStatusCode() === 303, 'a save that leaves every seo field alone is accepted');

$storedSeo = \Symfony\Component\Yaml\Yaml::parseFile($seoFile)['seo'];
ok(array_keys($storedSeo) === ['title', 'description', 'og_image', 'noindex', 'canonical'],
    'and writes the whole map, in schema order: ' . implode(', ', array_keys($storedSeo)));
ok($storedSeo['noindex'] === false, 'noindex lands as a real YAML bool');
ok($storedSeo['og_image'] === ['src' => '', 'alt' => '', 'width' => 0, 'height' => 0],
    'og_image is an empty image map, not an empty string — the shape a template can branch on');
ok($storedSeo['og_image']['alt'] === '', 'with an alt that is empty by declaration, never by the client typing one');

// A page file that has never carried a `seo:` key at all is the state every
// existing site is in the moment this ships.
$noSeo = \Symfony\Component\Yaml\Yaml::parseFile($seoFile);
unset($noSeo['seo']);
file_put_contents($seoFile, \Symfony\Component\Yaml\Yaml::dump($noSeo, 6, 2));
$legacy = render(['action' => 'edit', 'page' => 'home']);
contains($legacy, 'name="seo[title]"', 'a page with no seo: key still renders every input');
$adopted = admin_post([
    'action' => 'save', 'csrf' => 'the-real-token', 'page' => 'home',
    'baseline' => (string) hash_file('sha256', $seoFile),
    'seo' => ['description' => 'Γράφτηκε τώρα'],
]);
ok($adopted->getStatusCode() === 303, 'and saving it is accepted');
ok(\Symfony\Component\Yaml\Yaml::parseFile($seoFile)['seo']['description'] === 'Γράφτηκε τώρα',
    'the block is adopted on the first save rather than needing a migration');

rename($seoFile . '.seo.bak', $seoFile);
array_map('unlink', glob(dirname(__DIR__) . '/content/.revisions/home.*.yml') ?: []);

section('No structural controls exist in the UI');
missing($edit, 'Προσθήκη ενότητας', 'no "add component" button');
missing($edit, 'data-reorder', 'no reorder handles');
missing($edit, 'name="blocks[hero][type]"', 'component type is never an input');
missing($edit, 'name="slug"', 'slug is never an input');

section('Locked field is read-only in the UI as well as on save');
ok((bool) preg_match('/id="hero-align"[^>]*disabled/', $edit), 'locked select rendered disabled');
contains($edit, 'κλειδωμένο', 'locked badge shown to the editor');

section('CSRF is enforced');
$_SESSION['csrf'] = 'the-real-token';
$forged = admin_post(['action' => 'save', 'csrf' => 'forged', 'page' => 'home', 'blocks' => []]);
contains((string) $forged->getContent(), 'Η συνεδρία έληξε', 'save with a wrong CSRF token is rejected');
ok($forged->getStatusCode() === 400, 'and the rejection is a 400, not a page that merely looks like an error');

section('Uploads come off the request, not $_FILES');
// Phase 2 rewrote this path onto Request::$files, and it had no coverage at all
// — the first version called UploadedFile::getMimeType(), which fatals unless
// symfony/mime is installed. A real PNG, handed over as multipart would deliver it.
$png = sys_get_temp_dir() . '/dopamine-upload-' . bin2hex(random_bytes(4)) . '.png';
$im = imagecreatetruecolor(2, 2);
imagepng($im, $png);
imagedestroy($im);

$uploaded = admin(Request::create(
    '/admin.php',
    'POST',
    ['action' => 'upload', 'csrf' => 'the-real-token'],
    [],
    // $test: skip is_uploaded_file(), which only ever passes under a real SAPI.
    ['file' => new UploadedFile($png, 'Φωτό Πελάτη.PNG', 'image/png', null, true)]
));

$json = json_decode((string) $uploaded->getContent(), true);
ok($uploaded->getStatusCode() === 200, 'an upload off Request::$files is accepted');
ok(($json['ok'] ?? false) === true, 'and answers with JSON the form can swap the preview from');
ok(str_ends_with((string) ($json['url'] ?? ''), '.png'), 'the extension comes from the sniffed mime type: ' . ($json['url'] ?? '?'));
missing((string) ($json['url'] ?? ''), 'Φωτό', 'a non-ASCII client filename does not reach the key');
// Every filename a Greek client uploads slugifies to nothing, so this is the
// normal path, not an edge case: it must not produce a key like "-a1b2c3.png".
contains((string) ($json['url'] ?? ''), '/image-', 'and a name with nothing ASCII in it still gets a readable key');

// The URL put() returns must be the URL the bytes are actually reachable at.
// It was not: the R2 object-key prefix was applied to the local path too, so
// every upload landed a directory below the URL saved into the content file —
// preview fine, live page broken.
$url = (string) ($json['url'] ?? '');
// Uploads live in content/uploads/ now, not under the docroot: nginx aliases
// /uploads/ to them, so the returned URL is unchanged and the bytes are in the
// content repository where a git clone restores them along with the pages.
$onDisk = static fn (string $u): string
    => cms()->config['paths']['uploads'] . '/' . ltrim(substr($u, strlen('/uploads/')), '/');

$served = $onDisk($url);
ok(is_file($served), 'and the bytes are on disk at exactly the URL it returned: ' . $url);
ok(str_starts_with($served, dirname(__DIR__) . '/content/'), 'inside the content repository, not under public/');
ok(str_starts_with($url, '/uploads/'), 'which is under /uploads/, so config.media_bases accepts it on save');
ok(\Dopamine\FlatCms\Fields::mediaPath($url, cms()->fieldContext()['media_bases']) === $url,
    'and the src survives the save-time media guard rather than being blanked');

section('Uploads are normalized before they are stored');
// A landscape JPEG carrying Orientation=6 ("rotate 90° clockwise to display")
// and a GPS tag — which is exactly what arrives from a phone. Built here
// rather than committed, because a binary fixture nobody can read is a fixture
// nobody maintains.
$exif = (require __DIR__ . '/fixtures/exif_jpeg.php')(20, 10);
$phone = sys_get_temp_dir() . '/dopamine-phone-' . bin2hex(random_bytes(4)) . '.jpg';
file_put_contents($phone, $exif);

$before = @exif_read_data($phone);
ok((int) ($before['Orientation'] ?? 0) === 6, 'the fixture really does declare Orientation=6');
ok(isset($before['GPSLatitude']), 'and really does carry the coordinates of where it was taken');

$normalized = admin(Request::create(
    '/admin.php',
    'POST',
    ['action' => 'upload', 'csrf' => 'the-real-token'],
    [],
    ['file' => new UploadedFile($phone, 'IMG_4021.JPG', 'image/jpeg', null, true)]
));
$meta = json_decode((string) $normalized->getContent(), true);
$storedFile = $onDisk((string) ($meta['url'] ?? ''));

ok(($meta['ok'] ?? false) === true, 'the phone photo uploads');
ok(($meta['width'] ?? 0) === 10 && ($meta['height'] ?? 0) === 20,
    'and is stored rotated: a 20x10 source with Orientation=6 becomes '
    . ($meta['width'] ?? '?') . 'x' . ($meta['height'] ?? '?') . ', not 20x10 with a flag nobody honours');

$after = @exif_read_data($storedFile);
ok($after === false || !isset($after['GPSLatitude']), 'the GPS tag is gone — re-encoding is what strips it');
ok($after === false || !isset($after['Orientation']),
    'and so is the orientation flag, which would otherwise rotate the already-rotated pixels again');
ok(@getimagesize($storedFile)[0] === 10, 'the bytes on disk really are the rotated ones');

// Normalization runs even though 20x10 is far inside store_max_edge: "small
// enough to keep as-is" is not a reason to keep someone's home address in it.
ok(filesize($storedFile) > 0 && $storedFile !== $phone, 'a small image is re-encoded too, not passed through');

// The dimensions the save path will use are the server's own record of what it
// just wrote, and they are never read back off the request.
ok(($_SESSION['uploads'][$meta['url']] ?? null) === ['width' => 10, 'height' => 20],
    'the upload leaves a server-side record of the dimensions for the save to redeem');

@unlink($phone);
@unlink($storedFile);

section('AVIF is refused on the way in');
$avif = sys_get_temp_dir() . '/dopamine-avif-' . bin2hex(random_bytes(4)) . '.avif';
$im = imagecreatetruecolor(4, 4);
imageavif($im, $avif, 30);
imagedestroy($im);

$refused = admin(Request::create(
    '/admin.php',
    'POST',
    ['action' => 'upload', 'csrf' => 'the-real-token'],
    [],
    ['file' => new UploadedFile($avif, 'x.avif', 'image/avif', null, true)]
));
ok($refused->getStatusCode() === 400, 'an AVIF upload is refused: GD support for it is build-dependent');
contains((string) $refused->getContent(), 'JPG, PNG, WebP', 'and the client is told which formats are accepted');
@unlink($avif);

// A forged upload is refused at the CSRF gate, before anything is written.
$forgedUpload = admin(Request::create(
    '/admin.php',
    'POST',
    ['action' => 'upload', 'csrf' => 'nope'],
    [],
    ['file' => new UploadedFile($png, 'x.png', 'image/png', null, true)]
));
ok($forgedUpload->getStatusCode() === 400, 'an upload with a forged CSRF token is refused');

@unlink($png);
@unlink($served);

section('Auth blocks unauthenticated access when dev bypass is off');
// A production auth setup, built here rather than in a child process: the
// bypass off, cf_access on, and no Access JWT anywhere on the request.
$prod = require dirname(__DIR__) . '/config.php';
$prod['auth']['dev_bypass'] = false;
$prod['auth']['mode'] = 'cf_access';

$denied = admin_get(['action' => 'edit', 'page' => 'home'], $prod);
contains((string) $denied->getContent(), 'Cloudflare Access', 'request without a valid Access JWT is refused');
ok($denied->getStatusCode() === 403, 'and it is a real 403 — requireUser() throws, it does not echo and exit');
missing((string) $denied->getContent(), 'name="blocks[hero][heading]"', 'no edit form is rendered behind the refusal');

section('The panel is never cacheable');
ok($denied->headers->get('Cache-Control') === 'no-store, private', 'even a 403 carries no-store, private');
ok(admin_get([])->headers->get('Cache-Control') === 'no-store, private', 'and so does the page list');

summary();
