<?php
/**
 * Hits the derivative route in an isolated process with $_GET taken from argv,
 * and reports the status code it settled on plus the peak memory it needed.
 * Used by 06_production.php — not a test itself.
 *
 *   php _img_route.php 'src=/uploads/x.png&w=1337'
 */

declare(strict_types=1);

parse_str($argv[1] ?? '', $_GET);
$_SERVER['REQUEST_METHOD'] = 'GET';

$before = memory_get_peak_usage(true);

// The route exits on rejection, so report from a shutdown handler.
register_shutdown_function(static function () use ($before): void {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    fwrite(STDOUT, sprintf(
        "status=%d peak_growth=%d\n",
        http_response_code(),
        memory_get_peak_usage(true) - $before
    ));
});

ob_start();
require dirname(__DIR__) . '/public/img.php';
