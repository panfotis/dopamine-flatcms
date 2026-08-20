<?php

declare(strict_types=1);

namespace Dopamine\FlatCms;

use RuntimeException;

/**
 * Uploads to Cloudflare R2.
 *
 * R2 speaks the S3 API, so this is a hand-rolled SigV4 PUT — about 60 lines,
 * versus pulling in the whole aws-sdk-php (~15 MB) for one operation.
 *
 * Why R2 rather than the server's disk:
 *   - zero egress fees, so client sites with heavy imagery cost nothing to serve
 *   - media survives moving the site between servers
 *   - one media backend shared across every client install
 *
 * If r2.enabled is false everything falls back to content/uploads/ — committed
 * to git with the pages, so one clone restores the site whole — and the CMS
 * works exactly the same. That is the default; R2 is for sites that outgrow it.
 */
final class R2
{
    /**
     * The URL prefix the local fallback is served under.
     *
     * Fixed by convention, and the convention is asserted elsewhere: nginx
     * publishes paths.uploads here (in production it aliases
     * shared/content/uploads/), and config.media_bases must list it or every
     * stored src is rejected on save.
     */
    private const LOCAL_BASE = '/uploads/';

    /** @param array<string, mixed> $config */
    public function __construct(
        private readonly array $config,
        private readonly string $localFallbackDir,
    ) {
    }

    public function enabled(): bool
    {
        return (bool) ($this->config['enabled'] ?? false)
            && ($this->config['account_id'] ?? '') !== ''
            && ($this->config['bucket'] ?? '') !== '';
    }

    /**
     * @return string The stored path/URL to put in the content file.
     */
    public function put(string $key, string $bytes, string $contentType): string
    {
        $key = ltrim($key, '/');

        if (!$this->enabled()) {
            // No prefix here. `prefix` is an R2 *object key* concern; locally
            // the same job is already done by the directory itself, which the
            // web server publishes at LOCAL_BASE. Applying both nested every
            // upload one directory deeper than the URL this returns, so a
            // client uploaded an image, saw the preview, saved, and got a
            // broken image on the live page.
            $dest = $this->localFallbackDir . '/' . $key;
            $dir = dirname($dest);
            if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
                throw new RuntimeException('Cannot create ' . $dir);
            }
            if (file_put_contents($dest, $bytes, LOCK_EX) === false) {
                throw new RuntimeException('Cannot write ' . $dest);
            }

            return self::LOCAL_BASE . $key;
        }

        $key = (string) ($this->config['prefix'] ?? '') . $key;

        $host = $this->config['account_id'] . '.r2.cloudflarestorage.com';
        $path = '/' . $this->config['bucket'] . '/' . $key;
        $headers = $this->sign('PUT', $host, $path, $bytes, $contentType);

        $ch = curl_init('https://' . $host . $path);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => 'PUT',
            CURLOPT_POSTFIELDS     => $bytes,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 60,
        ]);
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($status < 200 || $status >= 300) {
            throw new RuntimeException(sprintf('R2 upload failed (%d): %s', $status, $err ?: (string) $body));
        }

        $base = rtrim((string) ($this->config['public_base'] ?? ''), '/');

        return $base !== '' ? $base . '/' . $key : '/' . $key;
    }

    /**
     * AWS Signature Version 4, single-chunk payload.
     *
     * @return list<string>
     */
    private function sign(string $method, string $host, string $path, string $payload, string $contentType): array
    {
        $region  = 'auto';
        $service = 's3';
        $now     = gmdate('Ymd\THis\Z');
        $date    = substr($now, 0, 8);
        $hash    = hash('sha256', $payload);

        // Path must be URI-encoded segment by segment, but slashes stay slashes.
        $canonicalPath = implode('/', array_map(
            static fn (string $s): string => rawurlencode($s),
            explode('/', $path)
        ));

        $canonicalHeaders = "content-type:{$contentType}\n"
            . "host:{$host}\n"
            . "x-amz-content-sha256:{$hash}\n"
            . "x-amz-date:{$now}\n";
        $signedHeaders = 'content-type;host;x-amz-content-sha256;x-amz-date';

        $canonicalRequest = implode("\n", [
            $method,
            $canonicalPath,
            '',                 // no query string
            $canonicalHeaders,
            $signedHeaders,
            $hash,
        ]);

        $scope = "{$date}/{$region}/{$service}/aws4_request";
        $toSign = implode("\n", [
            'AWS4-HMAC-SHA256',
            $now,
            $scope,
            hash('sha256', $canonicalRequest),
        ]);

        $k = hash_hmac('sha256', $date, 'AWS4' . $this->config['secret_key'], true);
        $k = hash_hmac('sha256', $region, $k, true);
        $k = hash_hmac('sha256', $service, $k, true);
        $k = hash_hmac('sha256', 'aws4_request', $k, true);
        $signature = hash_hmac('sha256', $toSign, $k);

        return [
            'Content-Type: ' . $contentType,
            'x-amz-content-sha256: ' . $hash,
            'x-amz-date: ' . $now,
            sprintf(
                'Authorization: AWS4-HMAC-SHA256 Credential=%s/%s, SignedHeaders=%s, Signature=%s',
                $this->config['access_key'],
                $scope,
                $signedHeaders,
                $signature
            ),
        ];
    }
}
