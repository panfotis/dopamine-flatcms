<?php

declare(strict_types=1);

session_start();
$_SESSION['csrf'] = 'the-real-token';
putenv('AUTH_DEV_BYPASS=1');
$_POST = ['action' => 'save', 'csrf' => 'forged', 'page' => 'home', 'blocks' => []];

require dirname(__DIR__) . '/public/admin.php';
