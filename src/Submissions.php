<?php

declare(strict_types=1);

namespace Dopamine\FlatCms;

use RuntimeException;

/**
 * Form submissions on disk: one JSON file per submission.
 *
 *   var/submissions/2026-08/9f3c1a7d2b4e6058.json
 *
 * **One file per submission is deliberate at this volume.** Deleting one is an
 * unlink, a delivery-status update is an atomic replace, and neither can race
 * the other — where an append-or-rewrite of a shared monthly JSONL file makes
 * "delete this record" and "mark that one sent" two writers of the same bytes.
 * A brochure site gets a few of these a week; the file count is not a problem
 * worth trading correctness for.
 *
 * **var/, not content/.** An earlier draft put them under content/ and told you
 * to push content/ to a git remote hourly — which replicates visitors' names,
 * emails and messages to a remote with no retention limit and no erasure path.
 * They are gitignored, admin-only, and pruned on a schedule.
 *
 * The id is random and never derived from anything the visitor sent: a filename
 * built from an email address is a directory listing that leaks the list.
 */
final class Submissions
{
    /** Exactly what store() writes. Names arrive from the request. */
    private const ID = '/^[0-9a-f]{16}$/';
    private const MONTH = '/^\d{4}-\d{2}$/';

    /** Delivery states. `review` is the one that must never retry blindly. */
    public const SENT = 'sent';
    public const UNSENT = 'unsent';
    public const REVIEW = 'review';

    public function __construct(private readonly string $dir)
    {
    }

    /**
     * Write one submission, atomically, and hand back its record.
     *
     * `x` mode rather than a temp file and a rename: the id is the filename and
     * the id is random, so the only thing that can already exist is a collision,
     * and failing loudly on one beats overwriting a stranger's message.
     *
     * @param  array<string, string> $values
     * @return array<string, mixed>
     */
    public function store(string $page, string $locale, array $values, string $ipHash): array
    {
        $month = date('Y-m');
        $dir = $this->dir . '/' . $month;
        if (!is_dir($dir) && !@mkdir($dir, 0770, true) && !is_dir($dir)) {
            throw new RuntimeException('Could not create the submissions directory.');
        }

        $record = [
            'id'      => bin2hex(random_bytes(8)),
            'month'   => $month,
            'at'      => date('c'),
            'page'    => $page,
            'locale'  => $locale,
            'values'  => $values,
            'status'  => self::UNSENT,
            'attempts' => 0,
            'error'   => '',
            // The hash only. The raw address is what the rate limiter needs for
            // ten minutes and what nobody needs for twelve months, and a
            // submission file is the copy that gets backed up off-host.
            'ip_hash' => $ipHash,
        ];

        $handle = @fopen($dir . '/' . $record['id'] . '.json', 'x');
        if ($handle === false) {
            throw new RuntimeException('Could not create the submission file.');
        }

        fwrite($handle, json_encode($record, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        fclose($handle);

        return $record;
    }

    /**
     * The path for one id, or an exception. The only place an id becomes a path.
     *
     * Both segments are matched against the exact shape store() writes rather
     * than screened for "..", for the same reason Content::REVISION is: a
     * filename is not a path here and never gets the chance to become one.
     */
    private function file(string $month, string $id): string
    {
        if (preg_match(self::MONTH, $month) !== 1 || preg_match(self::ID, $id) !== 1) {
            throw new RuntimeException('Άγνωστη καταχώρηση.');
        }

        return $this->dir . '/' . $month . '/' . $id . '.json';
    }

    /** @return array<string, mixed>|null */
    public function get(string $month, string $id): ?array
    {
        $file = $this->file($month, $id);
        if (!is_file($file)) {
            return null;
        }

        $data = json_decode((string) file_get_contents($file), true);

        return is_array($data) ? $data : null;
    }

    /**
     * Every submission, newest first.
     *
     * A brochure site's whole history is a few hundred records, so this reads
     * them all rather than paginating. If a site ever proves otherwise, the
     * month directories are already the shard.
     *
     * @return list<array<string, mixed>>
     */
    public function all(): array
    {
        $out = [];
        foreach (glob($this->dir . '/*/*.json') ?: [] as $file) {
            $data = json_decode((string) file_get_contents($file), true);
            if (is_array($data) && isset($data['id'], $data['at'])) {
                $out[] = $data;
            }
        }

        usort($out, static fn (array $a, array $b): int => strcmp((string) $b['at'], (string) $a['at']));

        return $out;
    }

    /**
     * Replace one record's delivery state, atomically.
     *
     * Temp-file-and-rename, so a reader never sees half a record and a crash
     * mid-write cannot destroy the submission itself — which is the copy that
     * exists precisely because delivery might have failed.
     *
     * @param array<string, mixed> $changes
     */
    public function update(string $month, string $id, array $changes): void
    {
        $record = $this->get($month, $id);
        if ($record === null) {
            return;
        }

        $file = $this->file($month, $id);
        $tmp = $file . '.' . bin2hex(random_bytes(4)) . '.tmp';

        file_put_contents(
            $tmp,
            json_encode($changes + $record, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            LOCK_EX
        );

        if (!rename($tmp, $file)) {
            @unlink($tmp);
        }
    }

    public function delete(string $month, string $id): bool
    {
        $file = $this->file($month, $id);

        return is_file($file) && unlink($file);
    }

    /**
     * Delete everything older than $months. The GDPR retention rule, run from
     * cron — a retention policy nobody enforces is a sentence in a privacy
     * notice, not a control.
     *
     * Whole month directories, because that is the granularity the path
     * carries and "delete the month that is entirely past the window" needs no
     * per-record date parsing to get wrong.
     *
     * @return list<string> the months removed
     */
    public function prune(int $months): array
    {
        $cutoff = date('Y-m', strtotime('-' . max(1, $months) . ' months'));

        $gone = [];
        foreach (glob($this->dir . '/*', GLOB_ONLYDIR) ?: [] as $path) {
            $month = basename($path);
            if (preg_match(self::MONTH, $month) !== 1 || $month >= $cutoff) {
                continue;
            }

            array_map('unlink', glob($path . '/*.json') ?: []);
            @rmdir($path);
            $gone[] = $month;
        }

        return $gone;
    }
}
