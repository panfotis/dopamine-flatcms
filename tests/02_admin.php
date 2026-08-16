<?php

declare(strict_types=1);

// A real request has emitted nothing before Admin::handle() runs. This CLI
// harness prints its own results as it goes, so open the session up front.
session_start();

require __DIR__ . '/lib.php';

use Dopamine\FlatCms\Admin;

putenv('AUTH_DEV_BYPASS=1');   // explicit, exactly as .ddev/config.yaml does it

function render(array $get): string
{
    $_GET = $get;
    $_POST = [];
    ob_start();
    (new Admin(cms()))->handle();
    return (string) ob_get_clean();
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
exec(sprintf('php %s 2>&1', escapeshellarg(__DIR__ . '/_bad_csrf.php')), $out);
contains(implode("\n", $out), 'Η συνεδρία έληξε', 'save with a wrong CSRF token is rejected');

section('Auth blocks unauthenticated access when dev bypass is off');
exec(sprintf('php %s 2>&1', escapeshellarg(__DIR__ . '/_no_auth.php')), $out2);
contains(implode("\n", $out2), 'Cloudflare Access', 'request without a valid Access JWT is refused');

summary();
