<?php

declare(strict_types=1);

namespace Dopamine\FlatCms;

/**
 * The local image-derivative contract.
 *
 * This is the whole of it: a pure function from an untrusted query string to
 * either a fully-resolved job description or null. It touches no filesystem,
 * opens no socket and decodes no image — which is the point. The derivative
 * route is the one endpoint on the site where an anonymous GET can make the
 * server spend hundreds of megabytes, so every parameter is validated against
 * a finite allowlist *before* anything expensive is reachable.
 *
 * Phase 4 writes the GD encoder behind this and may not widen the contract.
 * If it needs a seventh width, the width goes in config, not into a range check.
 */
final class Media
{
    /**
     * @param  array<string, mixed> $images config.images
     * @param  list<string>         $bases  Cms::fieldContext()['media_bases']
     * @param  array<string, mixed> $query  raw $_GET
     * @param  string               $accept raw Accept header, for format=auto
     * @return array{src: string, width: int, format: string, mime: string, max_age: int}|null
     */
    public static function spec(array $images, array $bases, array $query, string $accept = ''): ?array
    {
        $d = (array) ($images['derivatives'] ?? []);

        // Same guard as the save path: an src that is not media we host would
        // turn this route into an open image proxy on the client's domain.
        $src = Fields::mediaPath(is_string($query['src'] ?? null) ? $query['src'] : '', $bases);
        if ($src === '') {
            return null;
        }

        // Strict allowlist membership, not a range and not a cast: "640abc",
        // "0640" and " 640" are all rejected rather than quietly coerced, so
        // the cache key space stays exactly as large as the allowlist.
        $width = $query['w'] ?? null;
        if (!is_string($width) || !in_array((int) $width, (array) ($d['widths'] ?? []), true)
            || (string) (int) $width !== $width) {
            return null;
        }

        $format = is_string($query['f'] ?? null) ? $query['f'] : (string) ($d['default_format'] ?? 'auto');
        if (!in_array($format, (array) ($d['formats'] ?? []), true)) {
            return null;
        }

        if ($format === 'auto') {
            $format = str_contains($accept, 'image/webp') ? 'webp' : 'jpeg';
        }

        return [
            'src'     => $src,
            'width'   => (int) $width,
            'format'  => $format,
            'mime'    => 'image/' . $format,
            'max_age' => (int) ($d['cache_max_age'] ?? 0),
        ];
    }
}
