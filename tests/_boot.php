<?php
/**
 * Boots the config in an isolated process so the production guard can be
 * exercised with a real environment. Prints BOOTED, or the refusal.
 * Used by 06_production.php — not a test itself.
 */

declare(strict_types=1);

try {
    require dirname(__DIR__) . '/config.php';
    echo "BOOTED\n";
} catch (Throwable $e) {
    echo 'REFUSED: ' . $e->getMessage() . "\n";
}
