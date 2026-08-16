<?php

declare(strict_types=1);

namespace Dopamine\FlatCms;

use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Throwable;

/**
 * Authentication, or rather: the absence of it.
 *
 * Cloudflare Access sits in front of /admin.php and does the whole login
 * (email one-time PIN, Google, whatever you configure — free up to 50 users).
 * By the time a request reaches PHP, Cloudflare has already authenticated the
 * user and signed a JWT into the `Cf-Access-Jwt-Assertion` header.
 *
 * Our only job is to verify that signature, so that someone who finds the
 * origin IP cannot bypass Cloudflare and hit /admin.php directly.
 *
 * That means: no password storage, no session handling, no reset flow,
 * no brute-force protection, no 2FA to build. This class is the entire
 * auth layer.
 */
final class Auth
{
    /** @param array<string, mixed> $config */
    public function __construct(
        private readonly array $config,
        private readonly string $cacheDir,
    ) {
    }

    public function user(): ?string
    {
        $mode = (string) ($this->config['mode'] ?? 'cf_access');

        // The bypass is an explicit, deliberate configuration choice. It is
        // never inferred from anything in the request — REMOTE_ADDR in
        // particular is worthless here, because any local reverse proxy
        // (DDEV's router, cloudflared, nginx -> php-fpm) makes every request
        // look like it came from loopback.
        if ($mode === 'none' || $this->config['dev_bypass'] === true) {
            return 'dev@localhost';
        }

        $token = $this->token();
        if ($token === null) {
            return null;
        }

        try {
            $keys = JWK::parseKeySet($this->certs());
            $claims = (array) JWT::decode($token, $keys);
        } catch (Throwable) {
            return null; // bad signature, expired, unknown kid
        }

        // JWT::decode already checked exp/nbf/iss signature. Audience is on us:
        // without this check a token minted for a *different* Access app in the
        // same Cloudflare account would be accepted here.
        $aud = (array) ($claims['aud'] ?? []);
        $expected = (string) ($this->config['aud'] ?? '');
        if ($expected === '' || !in_array($expected, $aud, true)) {
            return null;
        }

        $issuer = 'https://' . trim((string) $this->config['team_domain'], '/');
        if (($claims['iss'] ?? '') !== $issuer) {
            return null;
        }

        return (string) ($claims['email'] ?? 'unknown');
    }

    public function requireUser(): string
    {
        $user = $this->user();
        if ($user !== null) {
            return $user;
        }

        http_response_code(403);
        header('Content-Type: text/html; charset=utf-8');
        echo '<h1>403</h1><p>This page is protected by Cloudflare Access. '
            . 'Open it through the site domain, not the origin server.</p>';
        exit;
    }

    private function token(): ?string
    {
        $t = $_SERVER['HTTP_CF_ACCESS_JWT_ASSERTION'] ?? $_COOKIE['CF_Authorization'] ?? null;
        return is_string($t) && $t !== '' ? $t : null;
    }

    /**
     * Cloudflare's public keys, cached for an hour. They rotate, so do not
     * hardcode them.
     *
     * @return array<string, mixed>
     */
    private function certs(): array
    {
        $file = $this->cacheDir . '/access-certs.json';
        if (is_file($file) && (time() - filemtime($file)) < 3600) {
            $cached = json_decode((string) file_get_contents($file), true);
            if (is_array($cached)) {
                return $cached;
            }
        }

        $url = 'https://' . trim((string) $this->config['team_domain'], '/') . '/cdn-cgi/access/certs';
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
        ]);
        $body = curl_exec($ch);
        curl_close($ch);

        $data = is_string($body) ? json_decode($body, true) : null;
        if (!is_array($data) || empty($data['keys'])) {
            // Serve a stale cache rather than locking the client out over a blip.
            if (is_file($file)) {
                return (array) json_decode((string) file_get_contents($file), true);
            }
            return ['keys' => []];
        }

        if (!is_dir($this->cacheDir)) {
            @mkdir($this->cacheDir, 0775, true);
        }
        @file_put_contents($file, json_encode($data), LOCK_EX);

        return $data;
    }
}
