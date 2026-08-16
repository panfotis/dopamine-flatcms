<?php

/**
 * The last line of defence on a live client site.
 *
 * Before this, a Twig error after a schema rename — a component template
 * referencing a field somebody deleted — was a white page with a PHP fatal
 * printed on it, on a domain the client shows people. The fatal named absolute
 * paths and library internals, and nothing logged it, so the first anyone knew
 * was the client phoning.
 *
 * Not a class: it installs process-level handlers, which is not something an
 * object should own, and Cms is the container.
 */

declare(strict_types=1);

namespace Dopamine\FlatCms;

use Throwable;

/**
 * Render 500.twig for anything that escapes, and log the detail.
 *
 * The shutdown handler is the other half: set_exception_handler never sees a
 * fatal (an out-of-memory, a call to a method on null in a template's compiled
 * output), and a fatal is exactly the failure that leaves a half-rendered page
 * with a stack trace appended to it.
 */
function bootstrap_error_handler(Cms $cms): void
{
    $render = static function (Throwable|array $problem) use ($cms): void {
        $what = $problem instanceof Throwable
            ? $problem->getMessage() . ' @ ' . $problem->getFile() . ':' . $problem->getLine()
            : ($problem['message'] ?? '') . ' @ ' . ($problem['file'] ?? '') . ':' . ($problem['line'] ?? '');

        error_log('[dopamine-flatcms] ' . $what);

        // Whatever was already buffered is part of a page we are abandoning.
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: text/html; charset=utf-8');
            header('Cache-Control: no-store');
        }

        try {
            echo $cms->twig->render('500.twig');
        } catch (Throwable) {
            // The error page itself is broken. Say so in plain text rather than
            // recursing into the handler that is already handling an error.
            echo "<!DOCTYPE html><title>500</title><h1>500</h1>\n";
        }
    };

    set_exception_handler($render);

    register_shutdown_function(static function () use ($render): void {
        $last = error_get_last();
        if ($last !== null && (($last['type'] ?? 0) & (E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR)) !== 0) {
            $render($last);
        }
    });
}
