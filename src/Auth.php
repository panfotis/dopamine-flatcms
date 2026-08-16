<?php

declare(strict_types=1);

namespace Dopamine\FlatCms;

use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Yaml\Yaml;
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
    /** The roles that exist. A `role:` outside this set is not a role. */
    public const ROLES = ['admin', 'editor'];

    /** @var array<string, string>|null email => role, lazily read from disk */
    private ?array $rolesCache = null;

    /** @param array<string, mixed> $config */
    public function __construct(
        private readonly array $config,
        private readonly string $cacheDir,
    ) {
    }

    /**
     * Who is making this request, and what may they do?
     *
     * Two separate questions, and null unless both answer. Cloudflare Access
     * establishes the address; config/roles.yml decides whether that address
     * has any business here. An authenticated stranger is still a stranger.
     *
     * @return array{email: string, role: string}|null
     */
    public function user(Request $request): ?array
    {
        try {
            return $this->requireUser($request);
        } catch (AccessDeniedException) {
            return null;
        }
    }

    /** @return array{email: string, role: string} */
    public function requireUser(Request $request): array
    {
        if ($this->bypassed()) {
            // Nothing was authenticated, so there is no address to look up in
            // roles.yml. Admin is the only coherent answer: the bypass means
            // "skip auth entirely", and APP_ENV=prod refuses to boot with it on.
            return ['email' => 'dev@localhost', 'role' => 'admin'];
        }

        $email = $this->email($request);
        if ($email === null) {
            throw new AccessDeniedException(
                'This page is protected by Cloudflare Access. '
                . 'Open it through the site domain, not the origin server.'
            );
        }

        $role = $this->roles()[strtolower($email)] ?? null;
        if ($role === null) {
            // Deliberately not an implicit editor role. Whoever administers the
            // Access application can add a login; only this repository can add
            // a person to the panel. The message says so, because the reader
            // authenticated perfectly well and "try logging in again" is
            // useless advice to them.
            throw new AccessDeniedException(
                'Ο λογαριασμός σας αναγνωρίστηκε αλλά δεν έχει πρόσβαση σε αυτό το site. '
                . 'Ζητήστε από τον διαχειριστή να σας προσθέσει.'
            );
        }

        return ['email' => $email, 'role' => $role];
    }

    /**
     * The bypass is an explicit, deliberate configuration choice. It is never
     * inferred from anything in the request — REMOTE_ADDR in particular is
     * worthless here, because any local reverse proxy (DDEV's router,
     * cloudflared, nginx -> php-fpm) makes every request look like it came
     * from loopback.
     */
    private function bypassed(): bool
    {
        return (string) ($this->config['mode'] ?? 'cf_access') === 'none'
            || $this->config['dev_bypass'] === true;
    }

    /**
     * The address Cloudflare Access signed for, or null. Authentication only —
     * this says nothing about whether the address may use the panel.
     */
    private function email(Request $request): ?string
    {
        $token = $this->token($request);
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

    /**
     * The authorisation allowlist, keyed by lowercased email.
     *
     * A row naming a role we do not have is dropped rather than interpreted:
     * `role: administrator` must lock somebody out, never let them in.
     *
     * @return array<string, string>
     */
    private function roles(): array
    {
        if ($this->rolesCache !== null) {
            return $this->rolesCache;
        }

        $file = (string) ($this->config['roles_file'] ?? '');
        $rows = is_file($file) ? (Yaml::parseFile($file) ?? []) : [];

        $out = [];
        foreach ((array) $rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $email = strtolower(trim((string) ($row['email'] ?? '')));
            $role  = (string) ($row['role'] ?? '');

            if ($email !== '' && in_array($role, self::ROLES, true)) {
                $out[$email] = $role;
            }
        }

        return $this->rolesCache = $out;
    }

    private function token(Request $request): ?string
    {
        $t = $request->headers->get('Cf-Access-Jwt-Assertion')
            ?? $request->cookies->get('CF_Authorization');

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
