<?php

declare(strict_types=1);

namespace Dopamine\FlatCms;

/**
 * Advisory "who is in this page" markers.
 *
 * Deliberately NOT a lock: nothing is ever blocked, and a stale marker cannot
 * strand a page. The baseline check in Content::transaction() is what actually
 * guarantees correctness; this exists purely so two people rarely reach that
 * check at all, because being told up front beats being told after you typed
 * four paragraphs.
 *
 * One small JSON file per page under var/locks/, refreshed while the form is
 * open and deleted on save. Anything older than TTL is ignored.
 */
final class Locks
{
    private const TTL = 600; // 10 minutes

    public function __construct(private readonly string $dir)
    {
    }

    private function file(string $pageId): string
    {
        return $this->dir . '/' . preg_replace('/[^a-z0-9_-]/i', '', $pageId) . '.json';
    }

    /** Record that $user is editing $pageId, refreshing any existing marker. */
    public function touch(string $pageId, string $user): void
    {
        if (!is_dir($this->dir) && !@mkdir($this->dir, 0775, true) && !is_dir($this->dir)) {
            return;
        }

        @file_put_contents(
            $this->file($pageId),
            json_encode(['user' => $user, 'at' => time()], JSON_THROW_ON_ERROR),
            LOCK_EX
        );
    }

    /**
     * Someone other than $user who was here within the TTL, or null.
     *
     * @return array{user: string, minutes: int}|null
     */
    public function heldByOther(string $pageId, string $user): ?array
    {
        $file = $this->file($pageId);
        if (!is_file($file)) {
            return null;
        }

        $data = json_decode((string) @file_get_contents($file), true);
        if (!is_array($data) || !isset($data['user'], $data['at'])) {
            return null;
        }

        $age = time() - (int) $data['at'];
        if ($age > self::TTL || (string) $data['user'] === $user) {
            return null;
        }

        return [
            'user'    => (string) $data['user'],
            'minutes' => intdiv($age, 60),
        ];
    }

    public function release(string $pageId, string $user): void
    {
        $file = $this->file($pageId);
        if (!is_file($file)) {
            return;
        }

        $data = json_decode((string) @file_get_contents($file), true);
        if (is_array($data) && (string) ($data['user'] ?? '') === $user) {
            @unlink($file);
        }
    }
}
