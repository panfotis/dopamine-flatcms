<?php
/**
 * The cloudflared / DDEV-router case: the request genuinely arrives from
 * loopback, but the bypass is off. It must still be refused.
 */
declare(strict_types=1);

putenv('AUTH_DEV_BYPASS=0');
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$_GET = ['action' => 'edit', 'page' => 'home'];

require dirname(__DIR__) . '/public/admin.php';
