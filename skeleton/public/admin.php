<?php

declare(strict_types=1);

use Dopamine\FlatCms\Admin;
use Dopamine\FlatCms\Cms;
use Symfony\Component\HttpFoundation\Request;

require dirname(__DIR__) . '/vendor/autoload.php';

$cms = new Cms(require dirname(__DIR__) . '/config.php');

// Admin::route() already catches Throwable and renders admin/error.twig, but
// only once it is inside the try — a failure constructing the panel, or a fatal
// mid-render, escapes it. The panel is where an editor pastes odd things, so it
// gets the same last line of defence the public site has.
Dopamine\FlatCms\bootstrap_error_handler($cms);

// Admin::handle() sets no-store on every branch it can return — the panel must
// never be cached by Cloudflare, including the redirect after a save.
(new Admin($cms))->handle(Request::createFromGlobals())->send();
