<?php

declare(strict_types=1);

namespace Dopamine\FlatCms;

use RuntimeException;
use Symfony\Component\Yaml\Yaml;

/**
 * Page storage. One YAML file per page, that is the whole database.
 *
 *   content/pages/el/home.yml
 *
 *   title: Αρχική
 *   slug: /
 *   blocks:
 *     - id: hero
 *       type: hero          <- structure. Never editable from the admin.
 *       fields:
 *         heading: "..."    <- content. This is all the client can change.
 *
 * The locale directory is there on a single-language site too, and from Phase 9
 * a second one beside it is a second language: one store per locale, each one
 * a plain instance of this class pointed at its own directory. Nothing here
 * knows about more than the language it holds — resolving which language a
 * request is in belongs to Cms, and this is what it resolves *to*.
 *
 * The filename is the page **id**, and the id is the translation identity —
 * `contact.yml` is the same page in `el/` and `en/` with different slugs. That
 * is why a `link` field stores the id and resolves the slug at render time.
 */
final class Content
{
    /**
     * Exactly the filename snapshot() writes: <id>.<Ymd>-<His>-<6 hex>.yml.
     *
     * Revision names arrive from the request, so they are matched against this
     * rather than merely screened for "..". A filename is not a path here and
     * never gets the chance to become one.
     */
    private const REVISION = '/^[a-z0-9_-]+\.\d{8}-\d{6}-[0-9a-f]{6}\.yml$/i';

    /**
     * The globals, in the order a person reads a page rather than the order the
     * filesystem hands them over. Two regions is the whole list, deliberately —
     * a third is out of scope, not merely unbuilt.
     */
    private const REGIONS = ['_header', '_footer'];

    /**
     * One glob-and-parse of pages/ per instance, keyed by the globals flag.
     * A render asks three times over — findBySlug(), nav(), and the link
     * picker's slug map — and sitemap() asks twice per locale on top of that.
     *
     * Per-instance only, and Cms::contentIn() builds one instance per locale
     * per request, so the cache lives exactly as long as the request does.
     *
     * @var array<string, list<array{id:string, title:string, slug:string,
     *                               nav:array<string,mixed>|null, noindex:bool, mtime:int}>>
     */
    private array $scanned = [];

    public function __construct(
        private readonly string $dir,
        private readonly string $locale,
        // Optional so a script that only reads content need not build one; the
        // panel always passes it, and it is the panel that shows these.
        private readonly ?Lang $lang = null,
    ) {
        // The locale is now a path segment, and from Phase 9 it arrives out of
        // a URL. Checked once, here, rather than at each of the six methods
        // that build a path out of it.
        self::assertSafeSegment($locale, 'locale');
    }

    /** One panel string, or the key when nobody handed us a catalogue. */
    private function say(string $key, string|int ...$args): string
    {
        return $this->lang?->t($key, ...$args) ?? $key;
    }

    /** Which language this store holds. */
    public function locale(): string
    {
        return $this->locale;
    }

    /** The directory this instance's pages live in. */
    public function pagesDir(): string
    {
        return $this->dir . '/pages/' . $this->locale;
    }

    /**
     * One path segment, or an exception. **The** guard, for every segment that
     * comes from outside and ends up in a filesystem path — a page id, a
     * locale, an R2 key part.
     *
     * It refuses rather than strips. An earlier version stripped, so `../home`
     * quietly resolved to `home` and a request that was plainly an attack was
     * answered with a page. Refusing says what happened, and a legitimate id
     * never notices the difference.
     */
    public static function assertSafeSegment(string $value, string $what = 'page id'): string
    {
        if (preg_match('/^[a-z0-9_-]+$/i', $value) !== 1) {
            throw new RuntimeException('Invalid ' . $what . '.');
        }

        return $value;
    }

    /**
     * A page id starting with `_` is a **global**: content that renders on
     * every page — the site header, the site footer — rather than at a URL of
     * its own.
     *
     * The prefix is the whole rule, and it means exactly one thing: **not
     * routable**. Everything else about a global is an ordinary page file, and
     * that is the point — load, save, transaction, baseline, snapshot,
     * revisions, restore, the locks and the schema walk already work on it. A
     * dedicated globals store would have been a second copy of all of them,
     * and therefore a second place for the save invariants to drift.
     *
     * This is the only copy of the rule. list() is the only place it is
     * applied, because list() is already the single feed for routing, the
     * sitemap, nav(), pageUrl() and the panel's page table.
     */
    public static function isGlobal(string $id): bool
    {
        return str_starts_with($id, '_');
    }

    /** A page id, or an exception. The only way an id becomes part of a path. */
    private function id(string $id): string
    {
        return self::assertSafeSegment($id);
    }

    /**
     * Where this locale's revisions live.
     *
     * Per locale, because `contact` is a different document in each language
     * and a shared directory would let the Greek history and the English one
     * overwrite each other's names — and let a restore put English copy into a
     * Greek page.
     */
    private function revisionsDir(): string
    {
        return $this->dir . '/.revisions/' . $this->locale;
    }

    private function file(string $id): string
    {
        return $this->pagesDir() . '/' . $this->id($id) . '.yml';
    }

    /**
     * The routable pages. Globals are not among them, which is what keeps them
     * out of routing, the sitemap, the menu, the link picker and the panel's
     * page table without a second check in any of those five places.
     *
     * @return list<array{id:string, title:string, slug:string,
     *                    nav:array<string,mixed>|null, noindex:bool, mtime:int}>
     */
    public function list(): array
    {
        return $this->scan(false);
    }

    /**
     * The globals, in the same shape — `_header`, `_footer`. Only the panel and
     * bin/doctor ask for these; the render path loads them by id.
     *
     * @return list<array{id:string, title:string, slug:string,
     *                    nav:array<string,mixed>|null, noindex:bool, mtime:int}>
     */
    public function globals(): array
    {
        $out = $this->scan(true);

        // scan() sorts by slug, which is right for pages and meaningless here:
        // a global has no address, so it falls back to /_footer and /_header and
        // sorts backwards from how the two regions sit on the page. A region
        // this list does not name sorts last rather than first, which is what
        // array_search()'s `false` would otherwise do.
        $rank = static fn (string $id): int
            => ($i = array_search($id, self::REGIONS, true)) === false ? PHP_INT_MAX : $i;

        usort($out, static fn (array $a, array $b): int => $rank($a['id']) <=> $rank($b['id']));

        return $out;
    }

    /**
     * @return list<array{id:string, title:string, slug:string,
     *                    nav:array<string,mixed>|null, noindex:bool, mtime:int}>
     */
    private function scan(bool $global): array
    {
        return $this->scanned[$global ? 'g' : 'p'] ??= $this->doScan($global);
    }

    /**
     * @return list<array{id:string, title:string, slug:string,
     *                    nav:array<string,mixed>|null, noindex:bool, mtime:int}>
     */
    private function doScan(bool $global): array
    {
        $out = [];
        foreach (glob($this->pagesDir() . '/*.yml') ?: [] as $file) {
            $id = basename($file, '.yml');
            if (self::isGlobal($id) !== $global) {
                continue;
            }

            $data = Yaml::parseFile($file) ?? [];
            $out[] = [
                'id'    => $id,
                'title' => (string) ($data['title'] ?? $id),
                'slug'  => (string) ($data['slug'] ?? '/' . $id),
                // Developer-owned, like every other structural key: the menu is
                // not something a client reorders from the panel.
                'nav'   => is_array($data['nav'] ?? null) ? $data['nav'] : null,
                // Both are here rather than in a second pass because the file
                // is already open: the sitemap needs to know which pages to
                // leave out and when each was last written, and load()ing every
                // page again to ask would parse the whole site twice.
                'noindex' => ($data['seo']['noindex'] ?? false) === true,
                'mtime'   => (int) filemtime($file),
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

        // The only write path. transaction() and restore() both land here, and
        // there is no delete() — so one line here is the whole invalidation,
        // rather than one per caller that could be forgotten.
        $this->scanned = [];
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
            throw new RuntimeException($this->say('err.page_missing'));
        }

        $lock = fopen($file, 'r');
        if ($lock === false || !flock($lock, LOCK_EX)) {
            throw new RuntimeException($this->say('err.locked'));
        }

        try {
            if ($baseline !== '' && !hash_equals((string) hash_file('sha256', $file), $baseline)) {
                throw new StaleContentException($this->say('err.stale'));
            }

            $page = $this->load($id);
            if ($page === null) {
                throw new RuntimeException($this->say('err.page_missing'));
            }

            // Mutate first: a refused save must not leave a revision behind,
            // and a client fixing three missing alts in a row would otherwise
            // push the last real version out of the history.
            $page = $mutate($page);

            $this->snapshot($id);
            $this->save($id, $page);
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

        $dir = $this->revisionsDir();
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            return;
        }

        // Second-resolution names collide when two saves land in the same
        // second — the earlier version was being overwritten and lost from the
        // history entirely.
        $new = sprintf('%s/%s.%s-%s.yml', $dir, $id, date('Ymd-His'), bin2hex(random_bytes(3)));
        copy($file, $new);

        // Pruning sorts by name, and names only carry seconds — so within one
        // second the order is the random suffix. Excluding the file just
        // written is what stops a burst of saves from deleting the snapshot it
        // has this moment taken, which would quietly make that one save the
        // one that cannot be undone.
        $existing = array_values(array_diff(glob($dir . '/' . $id . '.*.yml') ?: [], [$new]));
        sort($existing);
        foreach (array_slice($existing, 0, max(0, count($existing) + 1 - $keep)) as $old) {
            @unlink($old);
        }
    }

    /**
     * The revisions of a page, newest first.
     *
     * Names and timestamps only — never contents. Choosing a version to restore
     * does not require reading what is in it, so nothing is exposed here.
     *
     * @return list<array{file: string, at: string}>
     */
    public function revisions(string $id): array
    {
        $id = $this->id($id);

        $out = [];
        foreach (glob($this->revisionsDir() . '/' . $id . '.*.yml') ?: [] as $path) {
            $name = basename($path);
            if (preg_match(self::REVISION, $name) !== 1) {
                continue; // not written by snapshot(); not ours to offer
            }

            $out[] = ['file' => $name, 'at' => date('d/m/Y H:i:s', (int) filemtime($path))];
        }

        // The name carries the timestamp, so sorting it descending is newest
        // first — and does not depend on mtime, which a deploy can rewrite.
        usort($out, static fn (array $a, array $b): int => strcmp($b['file'], $a['file']));

        return $out;
    }

    /**
     * Read one revision of one page.
     *
     * $name is request input. It must match the exact shape snapshot() writes
     * *and* belong to this page, so neither a traversal nor another page's
     * history can be read through it.
     *
     * @return array<string, mixed>
     */
    public function revision(string $id, string $name): array
    {
        $id = $this->id($id);

        if (preg_match(self::REVISION, $name) !== 1 || !str_starts_with($name, $id . '.')) {
            throw new RuntimeException($this->say('err.revision_unknown'));
        }

        $path = $this->revisionsDir() . '/' . $name;
        if (!is_file($path)) {
            throw new RuntimeException($this->say('err.revision_missing'));
        }

        return (array) (Yaml::parseFile($path) ?? []);
    }

    /**
     * Restore a revision by *rebuilding* the page from it. Never copy() the old
     * file back into place.
     *
     * $rebuild is handed the parsed revision and the page as it stands right
     * now, and returns what to write. The caller walks the current schema and
     * re-runs the sanitiser inside it, because a revision is untrusted input
     * like any other: one taken before the allowlist tightened would otherwise
     * land on disk unsanitised and be rendered straight back out through |raw.
     *
     * Goes through transaction(), so the version being replaced is snapshotted
     * first — restoring the wrong revision is itself undoable.
     *
     * @param callable(array<string, mixed>, array<string, mixed>): array<string, mixed> $rebuild
     */
    public function restore(string $id, string $name, callable $rebuild): void
    {
        $revision = $this->revision($id, $name);

        // No baseline: a restore is a deliberate overwrite of whatever is
        // there, which is the one case where "the file changed" is not news.
        $this->transaction($id, '', static fn (array $page): array => $rebuild($revision, $page));
    }
}
