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
contains($edit, 'Για άτομα που χρησιμοποιούν αναγνώστη οθόνης', 'field hint rendered');

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
$served = dirname(__DIR__) . '/public' . $url;
ok(is_file($served), 'and the bytes are on disk at exactly the URL it returned: ' . $url);
ok(str_starts_with($url, '/uploads/'), 'which is under /uploads/, so config.media_bases accepts it on save');
ok(\Dopamine\FlatCms\Fields::mediaPath($url, cms()->fieldContext()['media_bases']) === $url,
    'and the src survives the save-time media guard rather than being blanked');

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
