<?php

declare(strict_types=1);

use Dopamine\FlatCms\Admin;
use Dopamine\FlatCms\Cms;

require dirname(__DIR__) . '/vendor/autoload.php';

// The admin panel must never be cached by Cloudflare.
header('Cache-Control: no-store, private');

$cms = new Cms(require dirname(__DIR__) . '/config.php');

(new Admin($cms))->handle();
