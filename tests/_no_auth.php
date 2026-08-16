<?php

declare(strict_types=1);

use Dopamine\FlatCms\Admin;
use Dopamine\FlatCms\Cms;

require dirname(__DIR__) . '/vendor/autoload.php';

$config = require dirname(__DIR__) . '/config.php';
$config['auth']['dev_bypass'] = false;   // production setting
$config['auth']['mode'] = 'cf_access';

putenv('AUTH_DEV_BYPASS=0');
$_GET = ['action' => 'edit', 'page' => 'home'];

(new Admin(new Cms($config)))->handle();
