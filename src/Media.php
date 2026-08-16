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
 * encode() is the GD side, written behind that contract and not widening it:
 * everything it does starts from a spec() that already said yes. If it needs a
 * seventh width, the width goes in config, not into a range check.
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

    /**
     * Produce the derivative a spec() asks for, and hand back where it landed.
     *
     * Everything expensive lives behind this call and nothing here re-decides
     * what spec() already decided. The order matters and is the whole defence:
     * identify the source, look for a hit, check the *declared* dimensions
     * against the memory budget, and only then let GD near the bytes.
     *
     * @param  array<string, mixed>                                             $config the whole config
     * @param  array{src: string, width: int, format: string, mime: string}     $spec
     * @return array{path: string, mime: string}|null
     */
    public static function encode(array $config, array $spec): ?array
    {
        $d = (array) ($config['images']['derivatives'] ?? []);
        $dir = rtrim((string) $config['paths']['cache'], '/') . '/images';

        $source = self::source($config, $spec['src']);
        if ($source === null) {
            return null;
        }

        [$bytes, $hash] = $source;

        $size = @getimagesizefromstring($bytes);
        if ($size === false || !in_array($size['mime'] ?? '', (array) ($d['decodable'] ?? []), true)) {
            return null;
        }

        // PNG in, PNG out: the fallback for a browser without WebP has to keep
        // alpha or every logo on the site grows a black box. Everything else
        // falls back to JPEG.
        // ponytail: a WebP source carrying alpha falls back to JPEG and loses
        // it. Detect the VP8L alpha bit here if a client ever uploads one.
        $format = $spec['format'] === 'jpeg' && $size['mime'] === 'image/png' ? 'png' : $spec['format'];

        $path = sprintf('%s/%s-%d.%s', $dir, $hash, $spec['width'], $format);
        $mime = 'image/' . $format;

        if (is_file($path)) {
            return ['path' => $path, 'mime' => $mime];
        }

        // Never upscale. picture.twig already declines to ask, but the route is
        // reachable directly and encoding a 600px source at 2048 spends real
        // CPU and disk to lose detail.
        $width = min($spec['width'], (int) $size[0]);
        $height = max(1, (int) round($size[1] * ($width / max(1, $size[0]))));

        // The budget is spent on two buffers, not one: GD holds the decoded
        // source *and* the decoded destination at the same time. Checking
        // `pixels x 4` alone lets a source that only just fits blow up on the
        // copy. Read off the header, so an image too big to handle costs a
        // stat and never a decode.
        if ((($size[0] * $size[1]) + ($width * $height)) * 4 > (int) ($d['memory_budget'] ?? 0)) {
            return null;
        }

        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            return null;
        }

        // One encode per key. Two concurrent misses for the same derivative
        // serialise here, and the loser finds the file already written rather
        // than encoding the same bytes a second time.
        $lock = fopen($path . '.lock', 'c');
        if ($lock === false || !flock($lock, LOCK_EX)) {
            return null;
        }

        try {
            if (!is_file($path) && !self::write($path, $bytes, $width, $height, $format)) {
                return null;
            }
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
            @unlink($path . '.lock');
        }

        self::prune($dir, (int) ($d['cache_max_bytes'] ?? 0));

        return ['path' => $path, 'mime' => $mime];
    }

    /**
     * Decode, scale, encode, and appear at $path in one step or not at all.
     *
     * The rename is what makes it atomic: a reader either finds nothing and
     * encodes, or finds a whole file. A half-written derivative served with a
     * year of cache headers is not a transient error, it is a permanent one.
     */
    private static function write(string $path, string $bytes, int $width, int $height, string $format): bool
    {
        $img = @imagecreatefromstring($bytes);
        if ($img === false) {
            return false;
        }

        try {
            // Before the scale, not after: imagescale() composites onto its new
            // canvas, and a source still in blending mode arrives there flattened
            // against black. Setting the flags on the result cannot undo that.
            imagealphablending($img, false);
            imagesavealpha($img, true);

            $out = imagescale($img, $width, $height);
            if ($out === false) {
                return false;
            }

            imagealphablending($out, false);
            imagesavealpha($out, true);

            $tmp = $path . '.' . bin2hex(random_bytes(4)) . '.tmp';
            $written = match ($format) {
                'png'  => imagepng($out, $tmp, 6),
                'webp' => imagewebp($out, $tmp, 82),
                default => imagejpeg($out, $tmp, 82),
            };
            imagedestroy($out);

            if (!$written || !rename($tmp, $path)) {
                @unlink($tmp);
                return false;
            }

            return true;
        } finally {
            imagedestroy($img);
        }
    }

    /**
     * The source adapters, in the order config names them: a file beneath the
     * uploads directory, or the exact object key beneath the configured R2
     * base. A URL the user supplied is never fetched — src has already been
     * through the same media-base guard the save path uses, and anything that
     * matches neither adapter is simply absent.
     *
     * @param  array<string, mixed> $config
     * @return array{0: string, 1: string}|null bytes and their content hash
     */
    private static function source(array $config, string $src): ?array
    {
        $d = (array) ($config['images']['derivatives'] ?? []);
        $sources = (array) ($d['sources'] ?? []);

        if (in_array('uploads', $sources, true)) {
            foreach ((array) ($config['media_bases'] ?? []) as $base) {
                if (!str_starts_with((string) $base, '/') || !str_starts_with($src, (string) $base)) {
                    continue;
                }

                // realpath containment on top of the "no .." guard: a symlink
                // planted under uploads/ must not read the rest of the disk.
                $root = realpath((string) $config['paths']['uploads']);
                $file = realpath($root . '/' . substr($src, strlen((string) $base)));
                if ($root === false || $file === false || !str_starts_with($file, $root . '/')) {
                    return null;
                }

                return is_file($file)
                    ? [(string) file_get_contents($file), (string) hash_file('sha256', $file)]
                    : null;
            }
        }

        $r2 = rtrim((string) ($config['r2']['public_base'] ?? ''), '/');
        if (in_array('r2', $sources, true) && $r2 !== '' && str_starts_with($src, $r2 . '/')) {
            $bytes = self::fetch($src, (int) ($config['images']['max_upload'] ?? 0));

            return $bytes === null ? null : [$bytes, hash('sha256', $bytes)];
        }

        return null;
    }

    /**
     * Read one R2 object over HTTPS.
     *
     * Redirects off, HTTPS only, a timeout and a byte cap: this runs on an
     * anonymous GET, and a fetch that follows a redirect is a fetch whose
     * destination the media-base guard never saw. The cap is the upload limit,
     * because nothing larger can have been stored through this CMS.
     */
    private static function fetch(string $url, int $cap): ?string
    {
        $body = '';
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS_STR  => 'https',
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_WRITEFUNCTION  => static function ($_, string $chunk) use (&$body, $cap): int {
                $body .= $chunk;

                // Returning short of the chunk length aborts the transfer,
                // which is the only way to stop curl mid-body.
                return ($cap > 0 && strlen($body) > $cap) ? 0 : strlen($chunk);
            },
        ]);
        curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return ($status === 200 && $body !== '' && ($cap <= 0 || strlen($body) <= $cap)) ? $body : null;
    }

    /**
     * Keep the derivative cache under its disk ceiling, oldest first.
     *
     * Scoped to the derivative directory and to files this class writes, so it
     * can never reach an original: a derivative is reproducible from its
     * source, an upload is not.
     */
    private static function prune(string $dir, int $ceiling): void
    {
        if ($ceiling <= 0) {
            return;
        }

        $files = [];
        $total = 0;
        foreach (glob($dir . '/*') ?: [] as $file) {
            if (str_ends_with($file, '.lock') || str_ends_with($file, '.tmp')) {
                continue;
            }
            $files[$file] = (int) filemtime($file);
            $total += (int) filesize($file);
        }

        if ($total <= $ceiling) {
            return;
        }

        asort($files);
        foreach (array_keys($files) as $file) {
            $total -= (int) @filesize($file);
            @unlink($file);
            if ($total <= $ceiling) {
                return;
            }
        }
    }
}
