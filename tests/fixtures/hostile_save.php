<?php
/**
 * One admin save, with a hostile POST body.
 *
 * Was tests/_do_save.php, which had to be a separate process because
 * Admin::handle() echoed and exited. It is now a plain request body, shared by
 * 03_lockdown.php (which asserts what landed on disk) and 04_hardening.php
 * (which asserts what did not).
 *
 * The CSRF token and the baseline are the caller's to supply: this save is
 * legitimate in every way except its payload, which is the whole point — a
 * request rejected at the door proves nothing about the schema walk.
 */

declare(strict_types=1);

return static fn (string $csrf, string $baseline): array => [
    'action'   => 'save',
    'csrf'     => $csrf,
    'page'     => 'home',
    'baseline' => $baseline,
    'title'    => '  Αρχική   σελίδα  <b>bold</b> ',

    'blocks' => [
        'hero' => [
            // legitimate edit
            'subheading' => 'Νέος υπότιτλος από τον πελάτη.',

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
    'slug' => '/hacked',
    'id'   => 'hacked',
];
