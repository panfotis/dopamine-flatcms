<?php

declare(strict_types=1);

namespace Dopamine\FlatCms;

/**
 * Edge cache control.
 *
 * The site sends `Cache-Control: s-maxage=<a year>` plus a `Cache-Tag` header,
 * so Cloudflare holds every page at the edge indefinitely and PHP is barely
 * ever hit. When the client saves, we purge just the tags that changed.
 *
 * Purge by tag used to be Enterprise-only; Cloudflare opened all purge methods
 * to every plan (including Free) in 2025, and purges land in ~150ms. For a
 * 5-page site 'everything' is equally fine and needs no header setup.
 */
final class Cloudflare
{
    /** @param array<string, mixed> $config */
    public function __construct(private readonly array $config)
    {
    }

    public function enabled(): bool
    {
        return (bool) ($this->config['purge_on_save'] ?? false)
            && ($this->config['zone_id'] ?? '') !== ''
            && ($this->config['api_token'] ?? '') !== '';
    }

    public static function tagFor(string $pageId): string
    {
        return 'page:' . $pageId;
    }

    /**
     * @param  list<string> $tags
     * @return array{ok: bool, message: string}
     */
    public function purge(array $tags): array
    {
        if (!$this->enabled()) {
            return ['ok' => true, 'message' => 'Purge skipped (not configured).'];
        }

        $payload = ($this->config['strategy'] ?? 'tag') === 'everything' || $tags === []
            ? ['purge_everything' => true]
            : ['tags' => array_values(array_unique($tags))];

        $ch = curl_init(sprintf(
            'https://api.cloudflare.com/client/v4/zones/%s/purge_cache',
            $this->config['zone_id']
        ));
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload, JSON_THROW_ON_ERROR),
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $this->config['api_token'],
                'Content-Type: application/json',
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
        ]);
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $data = is_string($body) ? json_decode($body, true) : null;
        if ($status === 200 && ($data['success'] ?? false)) {
            return ['ok' => true, 'message' => 'Edge cache purged.'];
        }

        // A failed purge must never fail the save — the content is already on
        // disk. Worst case the client waits for the TTL, so we report and move on.
        $msg = $data['errors'][0]['message'] ?? ('HTTP ' . $status);

        return ['ok' => false, 'message' => 'Saved, but cache purge failed: ' . $msg];
    }
}
