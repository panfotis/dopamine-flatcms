<?php
/** Triggers an internal error and prints whatever the client would see. */
declare(strict_types=1);

session_start();
$_SESSION['csrf'] = 'test-token';
putenv('AUTH_DEV_BYPASS=1');
$_POST = [
    'action'   => 'save',
    'csrf'     => 'test-token',
    'page'     => 'no-such-page',
    'baseline' => '',
    'blocks'   => [],
];

require dirname(__DIR__) . '/public/admin.php';
