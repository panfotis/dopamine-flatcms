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

    /**
     * The one real lock in the system, and the only thing in this class that
     * actually blocks.
     *
     * It exists for the hourly content backup. That job runs `git add -A` over
     * content/ and commits whatever it finds, so without it a commit can land
     * mid-save: a page file renamed into place but its revision not yet
     * written, or an upload's bytes present and the page that references them
     * not. The recovery point would then be a state the CMS never produced.
     *
     * Mutations take it **shared** — they already serialise against each other
     * per page, and two saves to different pages have no reason to queue. The
     * backup takes it **exclusive**: it waits for whatever is in flight and
     * holds off whatever arrives while it commits, which is a few hundred
     * milliseconds once an hour.
     *
     * Returns the release callable rather than a handle, so the caller's only
     * option is to release it:
     *
     *     $release = $locks->holdContent();
     *     try { ... } finally { $release(); }
     *
     * @param  bool $exclusive true for the backup, false for a mutation
     * @return callable(): void
     */
    public function holdContent(bool $exclusive = false): callable
    {
        if (!is_dir($this->dir) && !@mkdir($this->dir, 0775, true) && !is_dir($this->dir)) {
            return static function (): void {
            };
        }

        $handle = @fopen($this->dir . '/content.lock', 'c');
        if ($handle === false || !flock($handle, $exclusive ? LOCK_EX : LOCK_SH)) {
            // Never fail a client's save because the lock file is unwritable.
            // The backup is the side that must not proceed blind, and it checks
            // its own acquisition; a save that ran unlocked is at worst a commit
            // one interval later.
            if ($handle !== false) {
                fclose($handle);
            }

            return static function (): void {
            };
        }

        return static function () use ($handle): void {
            flock($handle, LOCK_UN);
            fclose($handle);
        };
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
