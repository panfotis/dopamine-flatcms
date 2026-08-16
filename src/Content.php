<?php

declare(strict_types=1);

namespace Dopamine\FlatCms;

use RuntimeException;
use Symfony\Component\Yaml\Yaml;

/**
 * Page storage. One YAML file per page, that is the whole database.
 *
 *   content/pages/home.yml
 *
 *   title: Αρχική
 *   slug: /
 *   blocks:
 *     - id: hero
 *       type: hero          <- structure. Never editable from the admin.
 *       fields:
 *         heading: "..."    <- content. This is all the client can change.
 */
final class Content
{
    public function __construct(private readonly string $dir)
    {
    }

    private function file(string $id): string
    {
        $id = preg_replace('/[^a-z0-9_-]/i', '', $id) ?? '';
        if ($id === '') {
            throw new RuntimeException('Invalid page id.');
        }

        return $this->dir . '/pages/' . $id . '.yml';
    }

    /** @return list<array{id:string, title:string, slug:string}> */
    public function list(): array
    {
        $out = [];
        foreach (glob($this->dir . '/pages/*.yml') ?: [] as $file) {
            $id = basename($file, '.yml');
            $data = Yaml::parseFile($file) ?? [];
            $out[] = [
                'id'    => $id,
                'title' => (string) ($data['title'] ?? $id),
                'slug'  => (string) ($data['slug'] ?? '/' . $id),
            ];
        }

        usort($out, static fn (array $a, array $b): int => strcmp($a['slug'], $b['slug']));

        return $out;
    }

    /** @return array<string, mixed>|null */
    public function load(string $id): ?array
    {
        $file = $this->file($id);
        if (!is_file($file)) {
            return null;
        }

        $data = Yaml::parseFile($file) ?? [];
        $data['id']       = $id;
        $data['blocks'] ??= [];

        foreach ($data['blocks'] as $i => $block) {
            $data['blocks'][$i]['id']     = (string) ($block['id'] ?? ($block['type'] ?? 'block') . '-' . $i);
            $data['blocks'][$i]['fields'] = $block['fields'] ?? [];
        }

        return $data;
    }

    /** @return array<string, mixed>|null */
    public function findBySlug(string $slug): ?array
    {
        $slug = '/' . trim($slug, '/');
        foreach ($this->list() as $page) {
            if (rtrim($page['slug'], '/') === rtrim($slug, '/')) {
                return $this->load($page['id']);
            }
        }

        return null;
    }

    /**
     * Write the page back. Atomic: write to a temp file in the same directory
     * then rename, so a crash mid-save cannot leave a half-written page live.
     *
     * @param array<string, mixed> $data
     */
    public function save(string $id, array $data): void
    {
        $file = $this->file($id);
        unset($data['id']); // derived from the filename, never stored

        $yaml = "# Managed by Dopamine FlatCMS. Structure is edited here, content in /admin.\n"
            . Yaml::dump($data, 6, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK);

        $tmp = $file . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (file_put_contents($tmp, $yaml, LOCK_EX) === false) {
            throw new RuntimeException('Could not write ' . $tmp);
        }

        if (!rename($tmp, $file)) {
            @unlink($tmp);
            throw new RuntimeException('Could not replace ' . $file);
        }
    }

    /**
     * A fingerprint of the page as it is on disk right now. The edit form
     * carries this back on save so a stale submission can be refused instead of
     * silently overwriting someone else's work.
     */
    public function baseline(string $id): string
    {
        $file = $this->file($id);

        return is_file($file) ? (string) hash_file('sha256', $file) : '';
    }

    /**
     * Read-modify-write under an exclusive lock.
     *
     * $mutate receives the freshly loaded page and returns the modified one.
     * Everything — snapshot, load, mutate, write — happens inside the lock, so
     * two concurrent saves serialise instead of clobbering each other.
     *
     * @param callable(array<string, mixed>): array<string, mixed> $mutate
     */
    public function transaction(string $id, string $baseline, callable $mutate): void
    {
        $file = $this->file($id);
        if (!is_file($file)) {
            throw new RuntimeException('Η σελίδα δεν βρέθηκε.');
        }

        $lock = fopen($file, 'r');
        if ($lock === false || !flock($lock, LOCK_EX)) {
            throw new RuntimeException('Could not lock the page for editing. Try again.');
        }

        try {
            if ($baseline !== '' && !hash_equals((string) hash_file('sha256', $file), $baseline)) {
                throw new StaleContentException(
                    'Η σελίδα άλλαξε από αλλού όσο την επεξεργαζόσασταν.'
                );
            }

            $page = $this->load($id);
            if ($page === null) {
                throw new RuntimeException('Η σελίδα δεν βρέθηκε.');
            }

            $this->snapshot($id);
            $this->save($id, $mutate($page));
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** Keep the last N versions of a page so a bad client edit is recoverable. */
    public function snapshot(string $id, int $keep = 10): void
    {
        $file = $this->file($id);
        if (!is_file($file)) {
            return;
        }

        $dir = $this->dir . '/.revisions';
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            return;
        }

        // Second-resolution names collide when two saves land in the same
        // second — the earlier version was being overwritten and lost from the
        // history entirely.
        copy($file, sprintf('%s/%s.%s-%s.yml', $dir, $id, date('Ymd-His'), bin2hex(random_bytes(3))));

        $existing = glob($dir . '/' . $id . '.*.yml') ?: [];
        sort($existing);
        foreach (array_slice($existing, 0, max(0, count($existing) - $keep)) as $old) {
            @unlink($old);
        }
    }
}
