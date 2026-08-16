<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

$GLOBALS['__fails'] = 0;
$GLOBALS['__passes'] = 0;

function ok(bool $cond, string $label): void
{
    if ($cond) {
        $GLOBALS['__passes']++;
        echo "  \033[32m✓\033[0m {$label}\n";
    } else {
        $GLOBALS['__fails']++;
        echo "  \033[31m✗ {$label}\033[0m\n";
    }
}

function contains(string $haystack, string $needle, string $label): void
{
    ok(str_contains($haystack, $needle), $label);
}

function missing(string $haystack, string $needle, string $label): void
{
    ok(!str_contains($haystack, $needle), $label);
}

function section(string $name): void
{
    echo "\n\033[1m{$name}\033[0m\n";
}

function summary(): void
{
    $f = $GLOBALS['__fails'];
    $p = $GLOBALS['__passes'];
    echo "\n" . ($f === 0
        ? "\033[32mAll {$p} checks passed.\033[0m\n"
        : "\033[31m{$f} of " . ($p + $f) . " checks FAILED.\033[0m\n");
    exit($f === 0 ? 0 : 1);
}

function cms(): \Dopamine\FlatCms\Cms
{
    return new \Dopamine\FlatCms\Cms(require dirname(__DIR__) . '/config.php');
}
