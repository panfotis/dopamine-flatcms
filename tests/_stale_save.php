<?php
/**
 * A save whose baseline is deliberately wrong. Prints the HTML the editor
 * would see, so the test can assert their work came back.
 */
declare(strict_types=1);

session_start();
$_SESSION['csrf'] = 'test-token';
putenv('AUTH_DEV_BYPASS=1');

$_POST = [
    'action'   => 'save',
    'csrf'     => 'test-token',
    'page'     => 'home',
    'baseline' => str_repeat('0', 64),   // stale by construction
    'blocks'   => [
        'hero' => [
            'subheading' => 'Κείμενο που δεν πρέπει να χαθεί<script>alert(1)</script>',
        ],
    ],
];

require dirname(__DIR__) . '/public/admin.php';
