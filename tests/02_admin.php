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
contains($edit, 'Hero', 'component label from schema.yml shown as the card title');
contains($edit, cms()->lang->t('field.alt_hint'), 'field hint rendered');

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
$faqFile = content_root() . '/pages/el/home.yml';
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
array_map('unlink', glob(content_root() . '/.revisions/el/home.*.yml') ?: []);

section('SEO is a collapsed card an editor can ignore entirely');
// Collapsed matters: it is the one card on the form that is optional from top
// to bottom, and an open one pushes the client's actual copy below the fold.
// The summary carries the rail's jump anchor, so it is matched with its
// attributes rather than as a bare tag. What is pinned is unchanged: a
// <details class="card"> whose first child is the summary.
ok((bool) preg_match('/<details class="card">\s*<summary(?:\s[^>]*)?>/', $edit), 'the seo block renders as a <details> card');
missing($edit, '<details class="card" open', 'which is collapsed — no `open` attribute anywhere on it');
// Same reasoning as the collapsing, carried to its conclusion: the optional
// card belongs after the copy the client came here to change, not in front of
// it. The pairing still has to be asserted, only the direction has flipped.
ok(strpos($edit, 'name="seo[title]"') > strpos($edit, 'name="blocks[hero][heading]"'),
    'and it sits at the end of the form, below the content blocks');

foreach ([
    'seo[title]', 'seo[description]', 'seo[og_image][src]', 'seo[noindex]', 'seo[canonical]',
] as $field) {
    contains($edit, 'name="' . $field . '"', $field . ' has an input');
}
// The share image is declared decorative: the card already carries the title
// and the description as text, so asking the client to describe the banner
// beside them a second time buys "εικόνα" typed to clear a field.
missing($edit, 'name="seo[og_image][alt]"', 'the share image asks for no description of its own');
contains($edit, cms()->lang->t('seo.og_image_hint'), 'and says what happens if it is left empty');
contains($edit, cms()->lang->t('seo.description_hint'), 'as does the description');
contains($edit, cms()->lang->t('seo.noindex_hint'), 'and every hint says what the field does, not that it "helps SEO"');
contains($edit, 'type="hidden" name="seo[noindex]" value="0"',
    'noindex has the hidden partner every boolean gets, so unchecking really posts');

// The whole point of collapsed-and-optional: a save that never opened the card
// must go through. The og_image posts as an empty map, and alt is required only
// when src is set — an unconditional rule would make every page unsaveable.
$_SESSION['csrf'] = 'the-real-token';
$seoFile = content_root() . '/pages/el/home.yml';
copy($seoFile, $seoFile . '.seo.bak');
// Post back exactly what the file holds: this case is "the client opened the
// form and saved without touching the seo card", so a hardcoded sentence here
// would only ever test that someone kept two copies of it in step.
$storedSeo = cms()->content->load('home')['seo'] ?? [];

$ignored = admin_post([
    'action'   => 'save',
    'csrf'     => 'the-real-token',
    'page'     => 'home',
    'baseline' => (string) hash_file('sha256', $seoFile),
    'title'    => 'Αρχική',
    'seo'      => [
        'title'       => '',
        'description' => $storedSeo['description'] ?? '',
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
array_map('unlink', glob(content_root() . '/.revisions/el/home.*.yml') ?: []);

section('No structural controls exist in the UI');
missing($edit, 'Προσθήκη ενότητας', 'no "add component" button');
missing($edit, 'data-reorder', 'no reorder handles');
missing($edit, 'name="blocks[hero][type]"', 'component type is never an input');
missing($edit, 'name="slug"', 'slug is never an input');

section('Locked field is read-only in the UI as well as on save');
ok((bool) preg_match('/id="hero-align"[^>]*disabled/', $edit), 'locked select rendered disabled');
contains($edit, cms()->lang->t('edit.locked'), 'locked badge shown to the editor');
// A disabled control posts nothing, so "not sent" would be indistinguishable
// from "cleared" if the value did not come back some other way. The hidden
// partner is what keeps the stored value across a save the client never touched.
contains($edit, 'type="hidden" name="blocks[hero][align]" value="center"',
    'with a hidden partner, because a disabled select posts nothing at all');

section('Every field type renders its own control');
// One assertion per branch of the field() macro. The save path is covered in
// 03_lockdown; this is the other half — that the panel actually builds the
// input the save path expects, for every type a component can declare.

// richtext: a contenteditable div the client types into, mirrored into a hidden
// textarea that is what actually posts. Both halves, or the value never arrives.
contains($edit, 'data-rt-toolbar="intro-body"', 'richtext gets a formatting toolbar');
contains($edit, 'id="intro-body-rt"', 'and a contenteditable surface');
contains($edit, 'data-target="intro-body"', 'pointed at the input it mirrors into');
ok((bool) preg_match('/<textarea id="intro-body" name="blocks\[intro\]\[body\]" hidden>/', $edit),
    'which is a hidden textarea — the contenteditable div itself never posts');

// image: upload/clear controls, a hidden src the JS writes, and the alt input
// the save path demands whenever src is set.
contains($edit, 'data-upload="intro-image"', 'an image field has an upload button');
contains($edit, 'data-clear="intro-image"', 'and a way to remove what is there');
contains($edit, 'name="blocks[intro][image][src]"', 'the src is a hidden input, never typed');
contains($edit, 'maxlength="120"', 'and the alt input carries the schema max the save path also cuts to');
// hero's image is decorative: true, so alt="" is the correct markup and asking
// for one buys "εικόνα" typed to clear a field.
contains($edit, 'data-upload="hero-image"', 'a decorative image still uploads like any other');
missing($edit, 'name="blocks[hero][image][alt]"', 'but asks for no description at all');

// textarea
ok((bool) preg_match('/<textarea id="hero-subheading" name="blocks\[hero\]\[subheading\]"/', $edit),
    'a textarea field renders a textarea');
ok((bool) preg_match('/name="seo\[description\]"[^>]*placeholder="[^"]+"/', $edit),
    'and seo.description shows the line the head will publish if it is left empty');

// select: the stored value comes back chosen, or the client silently loses it.
ok((bool) preg_match('/<option value="center"\s+selected>/', $edit), 'a select marks the stored option selected');
ok((bool) preg_match('/<option value="start"\s*>/', $edit), 'and leaves the others alone');

// link: a page picker storing an id, so renaming a slug cannot leave a dead
// href — with a custom address beside it for somewhere this site does not own,
// and a target, because the picker alone left both with nowhere to live.
contains($edit, 'name="blocks[hero][cta_url][page]"', 'a link field renders a page picker');
contains($edit, '<option value="epikoinonia" selected>', 'preselecting the stored page');
contains($edit, cms()->lang->t('edit.no_page'), 'and offers an empty option, because a link is optional');
contains($edit, 'name="blocks[hero][cta_url][url]"', 'a custom address box sits beside the picker');
contains($edit, 'name="blocks[hero][cta_url][target]"', 'and a target select beside that');
contains($edit, '<option value="_blank"', 'whose options are the allowlist the save path enforces');
contains($edit, cms()->lang->t('field.target_blank'), 'shown by their catalogue labels, not their attribute values');

// list: the blank row the add button clones lives in a <template>, so it is not
// in the form until it is wanted — an empty row that posts is one to refuse.
contains($edit, '<template id="faq-questions-template">', 'a repeater carries a template for new rows');
contains($edit, 'name="blocks[faq][questions][__INDEX__][question]"',
    'whose inputs are numbered by a placeholder the script replaces on insert');
contains($edit, 'data-repeater="faq-questions" data-max="20"', 'and the row container states the schema ceiling');

// image_list
contains($edit, 'data-name="blocks[gallery][photos]" data-max="30"',
    'a gallery states its own max, not the built-in ceiling');
contains($edit, 'data-decorative=""', 'and whether its photos are decorative, which this one is not');

// url falls through to the plain text input — the {% else %} arm, which is also
// what an unrecognised type gets, matching Fields::sanitise()'s default.
$footerForm = render(['action' => 'edit', 'page' => '_footer']);
ok((bool) preg_match('/<input type="text" id="footer-links-0-url" name="blocks\[footer\]\[links\]\[0\]\[url\]"/', $footerForm),
    'a url field is a plain text input, inside a repeater row');
contains($footerForm, 'placeholder="https://instagram.com/…"', 'carrying the schema placeholder');

section('A locked field stays locked inside a repeater row');
// The one case no real component covers, and the one the field() macro's
// docblock names: a second copy of the widget chain would drift, and a row is
// exactly where a locked field would quietly stop being locked. repeater()
// passes `locked or not may_edit(subDef.editable, role)` per sub-field; this
// asserts that reaches the input, in both the stored rows and the blank one.
//
// `editable: false` rather than `admin` on purpose — false is closed to
// everyone, so the dev bypass's admin role cannot hide the lock.
$fixtureDir = dirname(__DIR__) . '/var/cache/test-theme-' . bin2hex(random_bytes(4));
mkdir($fixtureDir . '/components/locked_rows', 0775, true);
file_put_contents($fixtureDir . '/components/locked_rows/schema.yml', \Symfony\Component\Yaml\Yaml::dump([
    'label'  => 'Κλειδωμένες γραμμές',
    'fields' => ['rows' => [
        'type' => 'list', 'label' => 'Γραμμές', 'max' => 5, 'item_label' => 'label',
        'fields' => [
            'label' => ['type' => 'text', 'label' => 'Κείμενο', 'max' => 40],
            'code'  => ['type' => 'text', 'label' => 'Κωδικός', 'editable' => false],
        ],
    ]],
], 6, 2));

$lockedPage = content_root() . '/pages/el/tmp-locked-rows.yml';
file_put_contents($lockedPage, \Symfony\Component\Yaml\Yaml::dump([
    'title'  => 'Κλειδωμένες γραμμές',
    'slug'   => '/tmp-locked-rows',
    'blocks' => [[
        'id' => 'lr', 'type' => 'locked_rows',
        'fields' => ['rows' => [['label' => 'Πρώτη', 'code' => 'ABC']]],
    ]],
], 6, 2));

$lockedCfg = test_config();
// First theme layer wins, exactly as a site overrides a starter component.
$lockedCfg['paths']['theme'] = array_merge([$fixtureDir], (array) $lockedCfg['paths']['theme']);
$lockedForm = (string) admin_get(['action' => 'edit', 'page' => 'tmp-locked-rows'], $lockedCfg)->getContent();

contains($lockedForm, 'name="blocks[lr][rows][0][label]"', 'the editable field in the row renders');
ok(!preg_match('/id="lr-rows-0-label"[^>]*readonly/', $lockedForm), 'and is genuinely editable');
ok((bool) preg_match('/id="lr-rows-0-code"[^>]*readonly/', $lockedForm),
    'while the locked field beside it is read-only — inside the row, not merely at the top level');
ok((bool) preg_match('/id="lr-rows-__INDEX__-code"[^>]*readonly/', $lockedForm),
    'including in the blank row the add button clones, which is where a forged POST would be built from');

unlink($lockedPage);
array_map('unlink', glob($fixtureDir . '/components/locked_rows/*') ?: []);
@rmdir($fixtureDir . '/locked_rows');
@rmdir($fixtureDir);

section('CSRF is enforced');
$_SESSION['csrf'] = 'the-real-token';
$forged = admin_post(['action' => 'save', 'csrf' => 'forged', 'page' => 'home', 'blocks' => []]);
contains((string) $forged->getContent(), cms()->lang->t('err.session'), 'save with a wrong CSRF token is rejected');
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
ok(str_starts_with($served, content_root() . '/'), 'inside the content repository, not under public/');
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

section('A refused save comes back as the form, with the message on the field');
// The failure mode this replaces: one error page, the whole form gone, and
// everything the client typed with it. Every required field is checked in one
// pass, at every depth, so fixing one does not reveal the next on the save
// after that.
$_SESSION['csrf'] = 'the-real-token';
$badFile = content_root() . '/pages/el/home.yml';
copy($badFile, $badFile . '.err.bak');
$beforeBad = (string) file_get_contents($badFile);

$refused = admin_post([
    'action'   => 'save',
    'csrf'     => 'the-real-token',
    'page'     => 'home',
    'title'    => 'Νέος τίτλος που δεν πρέπει να χαθεί',
    'baseline' => (string) hash_file('sha256', $badFile),
    'blocks'   => [
        // Required, at the top level of a block.
        'hero'  => ['heading' => '   ', 'subheading' => 'Κείμενο που δεν πρέπει να χαθεί'],
        // Required, inside an image map.
        'intro' => ['image' => ['src' => '/uploads/2024/01/team-abc123.jpg', 'alt' => '']],
        // Required, inside a list row — and only the second row.
        'faq'   => ['questions' => [
            ['question' => 'Πόσο κοστίζει;', 'answer' => '<p>Ναι.</p>'],
            ['question' => '', 'answer' => '<p>Χωρίς ερώτηση.</p>'],
        ]],
    ],
]);
$body = (string) $refused->getContent();

ok($refused->getStatusCode() === 422, 'the save is refused');
ok((string) file_get_contents($badFile) === $beforeBad, 'and not one field of it reached the file');
ok(glob(content_root() . '/.revisions/el/home.*.yml') === [],
    'nor did it burn a revision — the history keeps the last version that was real');

contains($body, 'name="blocks[hero][heading]"', 'the client gets the form back, not an error page');
contains($body, 'value="Νέος τίτλος που δεν πρέπει να χαθεί"',
    'and the editable page title survives the refusal too');
contains($body, 'Κείμενο που δεν πρέπει να χαθεί', 'still holding what they typed in the fields that were fine');
contains($body, cms()->lang->t('flash.fields_need_filling_plural', 3),
    'told how many fields need attention — all of them, in one pass');
ok(substr_count($body, 'class="err"') === 3, 'with one inline message each, beside the box that caused it');
ok(substr_count($body, 'class="field has-error"') === 3, 'and each of those boxes marked');

// The path is what makes the message land in the right place, and it is built
// as the refusal unwinds — a row index and an image's alt included.
$field = static fn (string $name): bool => (bool) preg_match(
    '/name="' . preg_quote($name, '/') . '"[^>]*>\s*<p class="err"/',
    $body
);
ok($field('blocks[hero][heading]'), 'a required field at the top level of a block');
ok($field('blocks[intro][image][alt]'), 'an alt inside an image map');
ok($field('blocks[faq][questions][1][question]'), 'and a field inside the *second* row of a list, not the first');

rename($badFile . '.err.bak', $badFile);

section('Several refusals inside one block are all reported at once');
// The walk keeps going past a refused field, so two empty questions in the
// same list surface together — not one per save, a row at a time.
copy($badFile, $badFile . '.err.bak');
$beforeBad = (string) file_get_contents($badFile);

$refused = admin_post([
    'action'   => 'save',
    'csrf'     => 'the-real-token',
    'page'     => 'home',
    'baseline' => (string) hash_file('sha256', $badFile),
    'blocks'   => [
        'faq' => ['questions' => [
            ['question' => '', 'answer' => '<p>Χωρίς ερώτηση.</p>'],
            ['question' => '   ', 'answer' => '<p>Ούτε εδώ.</p>'],
        ]],
    ],
]);
$body = (string) $refused->getContent();

ok($refused->getStatusCode() === 422, 'the save is refused');
ok((string) file_get_contents($badFile) === $beforeBad, 'and the file is untouched');
ok(substr_count($body, 'class="err"') === 2, 'both rows carry an inline message in the same response');
contains($body, cms()->lang->t('flash.fields_need_filling_plural', 2), 'and the flash counts both');

$field = static fn (string $name): bool => (bool) preg_match(
    '/name="' . preg_quote($name, '/') . '"[^>]*>\s*<p class="err"/',
    $body
);
ok($field('blocks[faq][questions][0][question]'), 'the first empty question is marked');
ok($field('blocks[faq][questions][1][question]'), 'and so is the second, in the same pass');

rename($badFile . '.err.bak', $badFile);

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

section('Globals are edited in the panel, on the same screen as a page');
$index = render([]);
contains($index, cms()->lang->t('list.globals'), 'the page list carries a second table for the globals');
contains($index, '?action=edit&amp;page=_header', 'linking to the header');
contains($index, '?action=edit&amp;page=_footer', 'and to the footer');
// The array order is pinned in 04_hardening; this is the screen it exists for.
ok(strpos($index, 'page=_header') < strpos($index, 'page=_footer'),
    'the header is listed first, as it sits on the page');
missing($index, 'href="/_header"', 'with no "view" link, because a global has no address to visit');

$headerForm = render(['action' => 'edit', 'page' => '_header']);
contains($headerForm, 'name="blocks[header][logo][src]"', 'the header opens the ordinary edit form');
contains($headerForm, 'name="blocks[header][logo][alt]"', 'with the alt input the save path demands');
// It renders inside every page and has no head of its own, so there is nothing
// for the five SEO fields to reach. Admin::save() skips the same walk.
missing($headerForm, 'name="seo[title]"', 'and no SEO card at all');
missing($headerForm, 'name="title"', 'nor a page title, which for a global is a panel label');
missing($headerForm, 'Προβολή σελίδας', 'nor a link to a page that does not exist');

// Structure is still structure: the rule does not relax because the file is
// shared across every page.
missing($headerForm, 'Προσθήκη ενότητας', 'no "add component" button on a global either');
missing($headerForm, 'name="blocks[header][type]"', 'nor a way to retype its block');

$footerFile = content_root() . '/pages/el/_footer.yml';
$footerBackup = $footerFile . '.globals.bak';
copy($footerFile, $footerBackup);

$_SESSION['csrf'] = 'the-real-token';
$savedFooter = admin_post([
    'action' => 'save', 'csrf' => 'the-real-token', 'page' => '_footer',
    'baseline' => (string) hash_file('sha256', $footerFile),
    'blocks' => ['footer' => ['note' => 'Νέα διεύθυνση', 'links' => [
        ['label' => 'Instagram', 'url' => 'https://instagram.com/example'],
    ]]],
    'seo' => ['title' => 'δεν πρέπει να γραφτεί'],
]);
ok($savedFooter->getStatusCode() === 303, 'a save to a global is accepted');
$storedFooter = \Symfony\Component\Yaml\Yaml::parseFile($footerFile);
ok($storedFooter['blocks'][0]['fields']['note'] === 'Νέα διεύθυνση', 'and writes through the same schema walk');
ok($storedFooter['blocks'][0]['fields']['links'][0]['url'] === 'https://instagram.com/example',
    'including a row the client added to the list');
ok(!array_key_exists('seo', $storedFooter),
    'a posted seo map is never written into a global — the card is skipped on save, not merely hidden');
// The panel's label for it is developer-owned, like a page's slug.
ok($storedFooter['title'] === 'Υποσέλιδο', 'and its title is not a client-editable field');

rename($footerBackup, $footerFile);
array_map('unlink', glob(content_root() . '/.revisions/el/_footer.*.yml') ?: []);

section('A gallery is a grid, not thirty stacked cards');
$galleryForm = render(['action' => 'edit', 'page' => 'home']);
contains($galleryForm, 'data-gallery="gallery-photos"', 'the image_list renders as a grid');
contains($galleryForm, 'name="blocks[gallery][photos][0][src]"', 'with one hidden src per tile');
contains($galleryForm, 'name="blocks[gallery][photos][0][alt]"', 'and an inline description input');
contains($galleryForm, 'data-move="-1"', 'reorder is up/down buttons — keyboard-accessible without a library');
missing($galleryForm, 'draggable="true"', 'and there is no drag-and-drop to load a library for');
contains($galleryForm, 'multiple', 'the picker takes several photos at once');

section('A video embed is a URL box, and a loop is an upload');
contains($galleryForm, 'name="blocks[video][embed]"', 'the embed field is one input');
contains($galleryForm, 'value="https://www.youtube.com/watch?v=', 'showing the stored id back as a URL the client recognises');
contains($galleryForm, 'name="blocks[video][loop][src]"', 'the loop stores a path');
contains($galleryForm, 'name="blocks[video][loop][poster][alt]"',
    'and its poster is an ordinary image field, description and all');
contains($galleryForm, 'accept="video/mp4"', 'the loop picker takes MP4 only');

section('Video uploads are size- and container-checked');
$mp4 = sys_get_temp_dir() . '/dopamine-video-' . bin2hex(random_bytes(4)) . '.mp4';
// The smallest thing finfo will call video/mp4: an ftyp box is the structure
// the whole claim rests on, so it is checked directly as well.
file_put_contents($mp4, "\x00\x00\x00\x18ftypmp42\x00\x00\x00\x00mp42isom" . str_repeat("\x00", 64));

$upload = static fn (string $path, string $name, string $type): \Symfony\Component\HttpFoundation\Response
    => admin(Request::create('/admin.php', 'POST', ['action' => 'upload', 'csrf' => 'the-real-token'], [], [
        'file' => new UploadedFile($path, $name, $type, null, true),
    ]));

$_SESSION['csrf'] = 'the-real-token';
$video = $upload($mp4, 'clip.mp4', 'video/mp4');
$videoBody = json_decode((string) $video->getContent(), true);
ok(($videoBody['ok'] ?? false) === true, 'a real MP4 is accepted');
ok(($videoBody['video'] ?? false) === true, 'and reported as a video, so the panel does not paint it as a thumbnail');
ok(str_ends_with((string) ($videoBody['url'] ?? ''), '.mp4'), 'stored with an .mp4 name: ' . ($videoBody['url'] ?? ''));
ok(str_starts_with((string) ($videoBody['url'] ?? ''), '/uploads/'),
    'inside uploads, which is the only place a video src may point');

// The declared type is the client's to choose; the bytes are not.
$fake = sys_get_temp_dir() . '/dopamine-fake-' . bin2hex(random_bytes(4)) . '.mp4';
file_put_contents($fake, str_repeat('A', 512));
$fakeUp = $upload($fake, 'not-really.mp4', 'video/mp4');
ok($fakeUp->getStatusCode() === 400, 'a file that merely claims to be video/mp4 is refused');

$huge = sys_get_temp_dir() . '/dopamine-huge-' . bin2hex(random_bytes(4)) . '.mp4';
file_put_contents($huge, "\x00\x00\x00\x18ftypmp42" . str_repeat("\x00", 11 * 1024 * 1024));
$hugeUp = $upload($huge, 'huge.mp4', 'video/mp4');
ok($hugeUp->getStatusCode() === 400, 'and one over the 10 MB cap is refused');
contains((string) $hugeUp->getContent(), '10 MB', 'with a message that names the limit');

array_map('unlink', array_filter([$mp4, $fake, $huge], 'is_file'));
array_map('unlink', glob(content_root() . '/uploads/*/*/clip-*.mp4') ?: []);

section('The panel speaks its own language, chosen per site');
// English is the *source* language and the default: a distributable package
// whose default is Greek is a fork waiting to happen.
ok(cms()->lang->locale() === 'en', 'ADMIN_LOCALE defaults to English');
contains($list, 'Pick a page', 'and the panel renders in it');
contains($list, '<html lang="en">', 'declaring that language to a screen reader');

$greekPanel = require dirname(__DIR__) . '/config.php';
$greekPanel['admin_locale'] = 'el';
$inGreek = (string) admin(Request::create('/admin.php', 'GET'), $greekPanel)->getContent();
contains($inGreek, 'Επιλέξτε σελίδα', 'switching ADMIN_LOCALE switches the panel');
contains($inGreek, '<html lang="el">', 'and the document language with it');
missing($inGreek, 'Pick a page', 'with nothing left in the source language');

// A typo must show English, not take the panel down.
$typo = require dirname(__DIR__) . '/config.php';
$typo['admin_locale'] = 'not-a-locale';
$fellBack = admin(Request::create('/admin.php', 'GET'), $typo);
ok($fellBack->getStatusCode() === 200, 'an unrecognised ADMIN_LOCALE still serves');
contains((string) $fellBack->getContent(), 'Pick a page', 'in the source language');

// The panel's language and the *site's* are different questions. A component's
// own label is written by the developer in whatever language the site is, and
// must survive the panel being English. The locked_rows fixture above carries
// a Greek label for exactly this check.
contains($lockedForm, 'Κλειδωμένες γραμμές', "a component's own Greek label is not translated away");

// The catalogue is complete, or the panel shows English where it is not — and
// that is a thing to know before a client does.
$el = new \Dopamine\FlatCms\Lang(dirname(__DIR__) . '/lang', 'el');
ok($el->missing() === [], 'the Greek catalogue translates every key: ' . implode(', ', $el->missing()));
ok($el->t('no.such.key') === 'no.such.key', 'and an unknown key renders as itself rather than blank');

// A site adds a string for a component it wrote, or rewords one the engine
// ships, by dropping a catalogue in its own root with *only* those keys in it.
// First-file-wins would mean copying ~90 strings and then maintaining them —
// which nobody does, and the copy silently misses every key a later engine
// release adds. So the layers merge, key by key, site last.
$siteLang = sys_get_temp_dir() . '/flatcms-lang-' . bin2hex(random_bytes(4));
mkdir($siteLang, 0775, true);
file_put_contents(
    $siteLang . '/el.php',
    "<?php\n\nreturn [\n    'edit.save' => 'Καταχώρηση',\n    'car.label' => 'Επιλέξτε αυτοκίνητο',\n];\n"
);

$layered = new \Dopamine\FlatCms\Lang([$siteLang, dirname(__DIR__) . '/lang'], 'el');
ok($layered->t('edit.save') === 'Καταχώρηση', 'a site catalogue rewords one engine string');
ok($layered->t('car.label') === 'Επιλέξτε αυτοκίνητο', 'and adds one of its own');
ok($layered->t('edit.revisions') === $el->t('edit.revisions'),
    'while every key it does not mention still comes from the engine');
ok($layered->missing() === [], 'so the catalogue is not reported as fallen behind');

// The engine layer alone must be unaffected — the static cache is keyed by the
// roots as well as the language, or one instance would serve another's merge.
ok($el->t('edit.save') !== 'Καταχώρηση', 'and the engine catalogue is not polluted by it');

unlink($siteLang . '/el.php');
rmdir($siteLang);

section('Preview renders the form without saving it');
// What someone wants before pressing Save is "what will this look like", and
// the honest answer is the real page built from the real values — through the
// same walk the save path uses, so nothing can be previewed that a save would
// have refused.
$_SESSION['csrf'] = 'the-real-token';
$homeFile = content_root() . '/pages/el/home.yml';
$before = (string) file_get_contents($homeFile);

$previewForm = render(['action' => 'edit', 'page' => 'home']);
contains($previewForm, 'value="preview"', 'the edit screen offers a preview button');
contains($previewForm, 'formtarget="previewframe"', '...which posts into the dialog rather than navigating away');

// The action rides on the submitter and only there. A hidden action input
// alongside the buttons is not a style choice: both values then land in the
// payload in tree order and PHP keeps the last, which was the hidden input's —
// so the preview button performed a real save and the dialog showed the 303's
// edit screen. Found by hand, because a test that posts `action` as a plain
// param cannot produce the duplicate.
missing($previewForm, '<input type="hidden" name="action"', 'no hidden action input to outvote the submitter');
contains($previewForm, 'name="action" value="save"', 'the save button carries its own action instead');

$previewed = admin_post([
    'action' => 'preview',
    'csrf'   => 'the-real-token',
    'page'   => 'home',
    'blocks' => ['hero' => ['heading' => 'Δεν σώθηκε ποτέ']],
]);
ok($previewed->getStatusCode() === 200, 'a preview renders');
$previewHtml = (string) $previewed->getContent();
contains($previewHtml, 'Δεν σώθηκε ποτέ', 'showing the value that was typed, not the one on disk');
contains($previewHtml, '<footer>', 'as the whole page, header and footer included');
ok((string) file_get_contents($homeFile) === $before, 'and the file on disk is untouched');

// The same walk means the same refusals. Richtext is sanitised by the
// allowlist here exactly as it is on the way to disk.
$hostilePreview = (string) admin_post([
    'action' => 'preview',
    'csrf'   => 'the-real-token',
    'page'   => 'home',
    'blocks' => ['intro' => ['body' => '<p>Καλό</p><script>alert(1)</script>']],
])->getContent();
contains($hostilePreview, 'Καλό', 'a richtext value previews');
missing($hostilePreview, '<script>alert(1)</script>', '...with the script stripped by the same sanitiser as a save');

// A global renders inside every page and has none of its own, so there is
// nothing to point a preview at. Refused rather than rendered blank.
ok(admin_post(['action' => 'preview', 'csrf' => 'the-real-token', 'page' => '_header'])
    ->getStatusCode() === 400, 'a global has no page of its own to preview');
$headerForm2 = render(['action' => 'edit', 'page' => '_header']);
missing($headerForm2, 'value="preview"', '...so the button is not offered for one');

section('The panel edits one language at a time');
$greek = render([]);
contains($greek, 'Ελληνικά', 'the page list offers the configured languages');
contains($greek, '?locale=en', 'and a way into the other one');
contains($greek, '<code>/epikoinonia</code>', 'listing the Greek slugs');

$english = render(['locale' => 'en']);
contains($english, '<code>/contact</code>', 'while the English screen lists the English slugs');
contains($english, 'Contact', 'and the English titles');
missing($english, '<code>/epikoinonia</code>', 'never the other language\'s');

$enEdit = render(['action' => 'edit', 'page' => 'epikoinonia', 'locale' => 'en']);
contains($enEdit, 'We usually answer within one working day', 'the edit form opens the English file');
contains($enEdit, 'name="locale" value="en"',
    'and carries the language on the form, so the save cannot land in the wrong file');
contains($enEdit, 'href="/en/contact"', 'with a preview link at the prefixed URL');

// The forged case: the field decides where the write goes, so it has to be the
// field the form actually posts and not a guess made from the id.
$enFile = content_root() . '/pages/en/epikoinonia.yml';
$elFile = content_root() . '/pages/el/epikoinonia.yml';
copy($enFile, $enFile . '.i18n.bak');
$elBefore = (string) file_get_contents($elFile);

$_SESSION['csrf'] = 'the-real-token';
$enSave = admin_post([
    'action' => 'save', 'csrf' => 'the-real-token', 'page' => 'epikoinonia', 'locale' => 'en',
    'baseline' => (string) hash_file('sha256', $enFile),
    'blocks' => ['details' => ['heading' => 'Reach us']],
]);
ok($enSave->getStatusCode() === 303, 'an English save is accepted');
ok(\Symfony\Component\Yaml\Yaml::parseFile($enFile)['blocks'][1]['fields']['heading'] === 'Reach us',
    'and lands in the English file');
ok((string) file_get_contents($elFile) === $elBefore, 'while the Greek file is untouched, byte for byte');
contains((string) $enSave->headers->get('Location'), 'locale=en', 'and the redirect keeps the editor in that language');

rename($enFile . '.i18n.bak', $enFile);
array_map('unlink', glob(content_root() . '/.revisions/en/epikoinonia.*.yml') ?: []);

section('The panel says which pages are not translated yet');
$orphanFile = content_root() . '/pages/el/tmp-untranslated.yml';
file_put_contents($orphanFile, \Symfony\Component\Yaml\Yaml::dump([
    'title' => 'Χωρίς μετάφραση', 'slug' => '/xoris', 'blocks' => [],
], 6, 2));

contains(render([]), cms()->lang->t('list.missing_in', 'English'), 'a page the other language lacks is flagged');
// Adding a page is adding a file — page creation stays developer-only — so the
// English screen reports it rather than offering a button.
contains(render(['locale' => 'en']), 'tmp-untranslated', 'and the English list names what it is missing');

unlink($orphanFile);

section('The settings screen lists how the install is configured');
$settings = (string) admin_get(['action' => 'settings'])->getContent();
contains($settings, cms()->lang->t('settings.title'), 'the screen renders');
// The version the public generator meta deliberately withholds is stated
// here, top of page — "ask the panel, not the public HTML".
ok(preg_match('#Dopamine FlatCMS <code>[^<]+</code>#', $settings) === 1,
    'the installed engine version is named at the top of the screen');
missing($settings, 'Dopamine FlatCMS <code>unknown</code>', 'and it is a real version, not the fallback');
contains($settings, 'base_url', 'a nested key is named');
contains($settings, 'derivatives', 'and so is a key three levels down');
contains($settings, '320, 640', 'a list of scalars is one line, not one row per value');
// Nothing on this screen may offer to change anything: config.php is the
// developer's file, and a form here would be a promise the engine cannot keep.
missing($settings, '<form', 'and nothing on it is editable');

section('An unknown locale is refused, not silently defaulted');
$badLocale = admin_get(['locale' => '../../etc']);
ok($badLocale->getStatusCode() === 400, 'a crafted locale is a refusal');
missing((string) $badLocale->getContent(), 'Αρχική', 'and no page list is rendered behind it');

summary();
