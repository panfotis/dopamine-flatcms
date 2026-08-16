<?php

declare(strict_types=1);

use Dopamine\FlatCms\Admin;
use Dopamine\FlatCms\Cms;
use Symfony\Component\HttpFoundation\Request;

require dirname(__DIR__) . '/vendor/autoload.php';

$cms = new Cms(require dirname(__DIR__) . '/config.php');

// Admin::handle() sets no-store on every branch it can return — the panel must
// never be cached by Cloudflare, including the redirect after a save.
(new Admin($cms))->handle(Request::createFromGlobals())->send();
