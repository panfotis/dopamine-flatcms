<?php
/**
 * Router for PHP's built-in server: `php -S localhost:8080 -t public public/router.php`
 * Serves real files as-is, everything else goes through index.php.
 * Nginx/Apache do this natively in production — see README.
 */

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/';
$file = __DIR__ . $path;

if ($path !== '/' && is_file($file)) {
    return false;
}

require __DIR__ . '/index.php';
