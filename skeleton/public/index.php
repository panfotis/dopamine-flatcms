<?php

declare(strict_types=1);

use Dopamine\FlatCms\Cms;
use Dopamine\FlatCms\Site;
use Symfony\Component\HttpFoundation\Request;

require dirname(__DIR__) . '/vendor/autoload.php';

$cms = new Cms(require dirname(__DIR__) . '/config.php');

// A Twig error after a schema rename used to be a white page with a PHP fatal
// on a live client site. Now it is 500.twig, and the detail goes to the log
// where it belongs rather than to whoever happened to be reading.
Dopamine\FlatCms\bootstrap_error_handler($cms);

// Every routing decision lives in Site::handle(), which arrives by
// `composer update`. This file is deliberately too short to drift — the two
// copies of the 168 lines that used to be here already had.
(new Site($cms))->handle(Request::createFromGlobals())->send();
