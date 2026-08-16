<?php

declare(strict_types=1);

namespace Dopamine\FlatCms;

use RuntimeException;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * The admin panel.
 *
 * The whole design rule is in save(): the client can change **values**, never
 * **structure**. Blocks are read from the file on disk, and for each block we
 * walk the component's schema and pull only those field names off the Request.
 *
 * Consequences, all deliberate:
 *   - a client cannot add, delete, reorder or retype a component
 *   - a field you mark `editable: false` is rendered read-only AND ignored on
 *     save, so a forged POST cannot set it either; `editable: admin` is the
 *     same thing for everyone who is not an admin
 *   - a field name that is not in schema.yml is dropped, even if posted
 *   - restoring a revision runs this same walk, so an old file cannot bring
 *     back structure, or values the current sanitiser would reject
 *
 * That is what makes this safe to hand to a non-technical client: the worst
 * they can do is write bad copy, and revisions cover that.
 */
final class Admin
{
    public function __construct(private readonly Cms $cms)
    {
    }

    public function handle(Request $request): Response
    {
        // Start the session before anything else, so the CSRF token is always
        // available no matter which branch we take below.
        $this->csrf($request);

        $response = $this->route($request);

        // The panel must never be cached by Cloudflare — on every branch,
        // including the redirect after a save.
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }

    private function route(Request $request): Response
    {
        $user = null;

        try {
            $user = $this->cms->auth->requireUser($request);
            $action = (string) $request->request->get('action', $request->query->get('action', 'list'));

            return match ($action) {
                'edit'      => $this->edit($request, $user, (string) $request->query->get('page', '')),
                'save'      => $this->save($request, $user),
                'upload'    => $this->upload($request),
                'revisions' => $this->revisions($request, $user),
                'restore'   => $this->restore($request, $user),
                default     => $this->list($request, $user),
            };
        } catch (AccessDeniedException $e) {
            // Both the unauthenticated case and a role refusal land here, so an
            // editor forging ?action=restore gets the same 403 as a stranger —
            // not a 400 that reads like the request was merely malformed.
            return $this->html($this->cms->twig->render('admin/denied.twig', [
                'message' => $e->getMessage(),
                'user'    => $user,
            ]), 403);
        } catch (Throwable $e) {
            error_log('[dopamine-flatcms] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());

            return $this->html($this->cms->twig->render('admin/error.twig', [
                'message' => $this->safeMessage($e),
                'user'    => $user,
            ]), 400);
        }
    }

    /**
     * Mutating flows that are not the client's to run at all.
     *
     * @param array{email: string, role: string} $user
     */
    private function requireAdmin(array $user): void
    {
        if ($user['role'] !== 'admin') {
            // An editor reaching here typed or forged the action; the panel
            // never offers it to them. Worth a line in the log either way.
            error_log('[dopamine-flatcms] admin-only action refused for '
                . $user['email'] . ' (role: ' . $user['role'] . ')');

            throw new AccessDeniedException(
                'Αυτή η ενέργεια απαιτεί ρόλο διαχειριστή.'
            );
        }
    }

    private function html(string $body, int $status = 200): Response
    {
        return new Response($body, $status, ['Content-Type' => 'text/html; charset=utf-8']);
    }

    /**
     * Validation problems are written for the client and are safe to show.
     * Anything else may carry absolute filesystem paths or library internals,
     * so it goes to the log and the client gets a generic line.
     */
    private function safeMessage(Throwable $e): string
    {
        if ($e instanceof RuntimeException && !str_contains($e->getMessage(), DIRECTORY_SEPARATOR)) {
            return $e->getMessage();
        }

        return 'Παρουσιάστηκε σφάλμα και η ενέργεια δεν ολοκληρώθηκε. Δοκιμάστε ξανά.';
    }

    /** @param array{email: string, role: string} $user */
    private function list(Request $request, array $user): Response
    {
        return $this->html($this->cms->twig->render('admin/list.twig', [
            'pages'  => $this->cms->content->list(),
            'user'   => $user,
            'notice' => $request->query->get('ok'),
            'warn'   => $request->query->get('warn'),
        ]));
    }

    /**
     * @param array{email: string, role: string} $user
     * @param array<string, mixed>               $opts notice, warn, conflict, posted
     */
    private function edit(Request $request, array $user, string $id, array $opts = []): Response
    {
        $page = $this->cms->content->load($id);
        if ($page === null) {
            throw new RuntimeException('Η σελίδα δεν βρέθηκε.');
        }

        $posted = (array) ($opts['posted'] ?? []);
        $context = $this->cms->fieldContext();

        // Decorate each block with its schema so the template can build the form.
        $blocks = [];
        foreach ($page['blocks'] as $block) {
            $type = (string) $block['type'];
            $schema = $this->cms->components->get($type);
            $values = $this->cms->withDefaults($schema ?? ['fields' => []], $block['fields']);

            // After a conflict we re-render with what the editor actually typed
            // rather than discarding it. It goes through the same sanitiser
            // first — this is reflected input, and the richtext preview renders
            // with |raw.
            if ($schema !== null && isset($posted[$block['id']])) {
                $values = $this->cleanValues(
                    $schema,
                    $values,
                    (array) $posted[$block['id']],
                    $context,
                    $user['role'],
                    false
                );
            }

            $blocks[] = [
                'id'      => $block['id'],
                'type'    => $type,
                'label'   => $schema['label'] ?? $type,
                'hint'    => $schema['description'] ?? null,
                'missing' => $schema === null,
                'fields'  => $schema === null ? [] : $schema['fields'],
                'values'  => $values,
            ];
        }

        // Advisory only — see Locks. Read before touching, or you always find
        // yourself.
        $heldBy = $this->cms->locks->heldByOther($id, $user['email']);
        $this->cms->locks->touch($id, $user['email']);

        return $this->html($this->cms->twig->render('admin/edit.twig', [
            'page'     => $page,
            'blocks'   => $blocks,
            'csrf'     => $this->csrf($request),
            // Always the CURRENT hash: after a conflict the editor is now
            // looking at the other person's version, so saving again is a
            // deliberate overwrite rather than a stale one.
            'baseline' => $this->cms->content->baseline($id),
            'user'     => $user,
            'notice'   => $opts['notice'] ?? null,
            'warn'     => $opts['warn'] ?? null,
            'conflict' => $opts['conflict'] ?? null,
            'held_by'  => $heldBy,
        ]));
    }

    /**
     * Walk a component's schema and produce a clean value map from posted
     * input. Shared by save(), restore() and the conflict re-render, so all
     * three apply exactly the same rules.
     *
     * $role is what makes `editable: admin` real rather than decorative: a
     * field the role may not edit keeps its stored value no matter what
     * arrived, whether that input came from a form, a forged POST or an old
     * revision.
     *
     * @param  array<string, mixed> $schema
     * @param  array<string, mixed> $stored
     * @param  array<string, mixed> $in
     * @param  array<string, mixed> $context
     * @return array<string, mixed>
     */
    private function cleanValues(
        array $schema,
        array $stored,
        array $in,
        array $context,
        string $role,
        bool $enforceRequired = true
    ): array {
        $clean = [];

        foreach ($schema['fields'] as $name => $def) {
            $current = $stored[$name] ?? $def['default'] ?? '';

            if (!Components::mayEdit($def['editable'], $role) || !array_key_exists($name, $in)) {
                $clean[$name] = $current;
                continue;
            }

            $value = Fields::sanitise($def, $in[$name], $context);

            if ($enforceRequired && $def['required'] && $value === '') {
                throw new RuntimeException(sprintf(
                    'Το πεδίο «%s» στην ενότητα «%s» δεν μπορεί να είναι κενό.',
                    $def['label'],
                    $schema['label']
                ));
            }

            $clean[$name] = $value;
        }

        return $clean;
    }

    /** @param array{email: string, role: string} $user */
    private function save(Request $request, array $user): Response
    {
        $this->checkCsrf($request);

        $id = (string) $request->request->get('page', '');
        $posted = (array) $request->request->all('blocks');
        $context = $this->cms->fieldContext();

        // The whole read-modify-write runs under an exclusive lock on the page
        // file, and refuses to proceed if the file changed since the form was
        // rendered. Without both, two tabs (or one double-clicked submit)
        // silently discard each other's edits — atomic writes prevent a torn
        // file, they do not prevent lost updates.
        try {
            $this->cms->content->transaction(
                $id,
                (string) $request->request->get('baseline', ''),
                function (array $page) use ($request, $user, $posted, $context): array {
                    foreach ($page['blocks'] as $i => $block) {
                        $schema = $this->cms->components->get((string) $block['type']);
                        if ($schema === null) {
                            continue; // unknown component: leave its stored values alone
                        }

                        // Schema-driven, not input-driven. The result is rebuilt
                        // from the schema key set, so a field removed from
                        // schema.yml disappears from the file on the next save
                        // instead of lingering unsanitised forever.
                        $page['blocks'][$i]['fields'] = $this->cleanValues(
                            $schema,
                            (array) ($block['fields'] ?? []),
                            (array) ($posted[$block['id']] ?? []),
                            $context,
                            $user['role']
                        );
                    }

                    // Page title is the one non-block field a client may edit.
                    if ($request->request->has('title')) {
                        $page['title'] = Fields::sanitise(
                            ['type' => 'text', 'max' => 120],
                            $request->request->get('title')
                        );
                    }

                    return $page;
                }
            );
        } catch (StaleContentException $e) {
            // Refusing the write is correct. Throwing away what they typed is
            // not — re-render the form holding their values against the new
            // version of the page, and let them save again deliberately.
            return $this->edit($request, $user, $id, [
                'posted'   => $posted,
                'conflict' => $e->getMessage(),
            ]);
        }

        $this->cms->locks->release($id, $user['email']);

        // Purge the page itself and the `site` tag, which covers everything
        // that renders cross-page data — navigation, language alternates and
        // the sitemap all embed values owned by other pages.
        $purge = $this->cms->cf->purge([Cloudflare::tagFor($id), 'site']);

        return new RedirectResponse('?action=edit&page=' . urlencode($id)
            . ($purge['ok'] ? '&ok=' . urlencode('Οι αλλαγές αποθηκεύτηκαν.')
                            : '&warn=' . urlencode($purge['message'])), 303);
    }

    /**
     * The versions of a page, admin only.
     *
     * Names and dates, never contents: picking a version to restore does not
     * require reading it, so nothing from an old revision is put on screen.
     *
     * @param array{email: string, role: string} $user
     */
    private function revisions(Request $request, array $user): Response
    {
        $this->requireAdmin($user);

        $id = (string) $request->query->get('page', '');
        $page = $this->cms->content->load($id);
        if ($page === null) {
            throw new RuntimeException('Η σελίδα δεν βρέθηκε.');
        }

        return $this->html($this->cms->twig->render('admin/revisions.twig', [
            'page'      => $page,
            'revisions' => $this->cms->content->revisions($id),
            'csrf'      => $this->csrf($request),
            'user'      => $user,
        ]));
    }

    /**
     * Put an old version back, admin only, CSRF-protected.
     *
     * The revision supplies **values**; the file on disk still supplies the
     * structure. That is the same rule as an ordinary save, and it is why this
     * rebuilds rather than copying the old file into place: every value goes
     * back through the current schema walk and the current sanitiser. A
     * revision written before the allowlist tightened is untrusted input, and
     * templates render richtext with |raw.
     *
     * @param array{email: string, role: string} $user
     */
    private function restore(Request $request, array $user): Response
    {
        $this->requireAdmin($user);
        $this->checkCsrf($request);

        $id = (string) $request->request->get('page', '');
        $context = $this->cms->fieldContext();

        $this->cms->content->restore(
            $id,
            (string) $request->request->get('revision', ''),
            function (array $revision, array $page) use ($user, $context): array {
                // Index the revision's blocks by id, which is the shape
                // cleanValues() expects — exactly what a POST body would be.
                $values = [];
                foreach ((array) ($revision['blocks'] ?? []) as $i => $block) {
                    $key = (string) ($block['id'] ?? ($block['type'] ?? 'block') . '-' . $i);
                    $values[$key] = (array) ($block['fields'] ?? []);
                }

                foreach ($page['blocks'] as $i => $block) {
                    $schema = $this->cms->components->get((string) $block['type']);
                    if ($schema === null) {
                        continue;
                    }

                    // Walks the *current* page's blocks, so a revision cannot
                    // resurrect a component the developer has since deleted,
                    // reorder anything, or retype a block.
                    $page['blocks'][$i]['fields'] = $this->cleanValues(
                        $schema,
                        (array) ($block['fields'] ?? []),
                        (array) ($values[$block['id']] ?? []),
                        $context,
                        $user['role']
                    );
                }

                if (isset($revision['title'])) {
                    $page['title'] = Fields::sanitise(
                        ['type' => 'text', 'max' => 120],
                        $revision['title']
                    );
                }

                return $page;
            }
        );

        $this->cms->cf->purge([Cloudflare::tagFor($id), 'site']);

        return new RedirectResponse('?action=edit&page=' . urlencode($id)
            . '&ok=' . urlencode('Η παλαιότερη έκδοση επαναφέρθηκε.'), 303);
    }

    /**
     * Image upload. Returns JSON so the form can swap the preview without a
     * page reload. Originals are downscaled before storage — a client will
     * absolutely upload a 9 MB photo straight off their phone.
     */
    private function upload(Request $request): Response
    {
        $this->checkCsrf($request);

        $file = $request->files->get('file');
        if (!$file instanceof UploadedFile || !$file->isValid()) {
            throw new RuntimeException('Η μεταφόρτωση απέτυχε.');
        }

        $cfg = $this->cms->config['images'];
        if ($file->getSize() > $cfg['max_upload']) {
            throw new RuntimeException('Το αρχείο είναι πολύ μεγάλο.');
        }

        // The client-supplied Content-Type is not evidence of anything, so sniff
        // the bytes. Deliberately not UploadedFile::getMimeType(), which needs
        // symfony/mime — one more dependency to do what ext-fileinfo already does.
        $mime = (string) (new \finfo(FILEINFO_MIME_TYPE))->file($file->getPathname());
        if (!in_array($mime, $cfg['allowed'], true)) {
            throw new RuntimeException('Επιτρέπονται μόνο εικόνες (JPG, PNG, WebP, AVIF).');
        }

        $bytes = (string) file_get_contents($file->getPathname());

        // Check the declared dimensions before handing anything to GD. A
        // crafted 30000x30000 PNG is a few hundred KB on disk and roughly
        // 3.6 GB once decoded, which takes the worker out.
        $size = @getimagesizefromstring($bytes);
        if ($size === false) {
            throw new RuntimeException('Το αρχείο δεν είναι έγκυρη εικόνα.');
        }
        if (($size[0] * $size[1]) > (int) $cfg['max_pixels']) {
            throw new RuntimeException('Οι διαστάσεις της εικόνας είναι υπερβολικά μεγάλες.');
        }

        if ($cfg['store_max_edge'] > 0) {
            $bytes = $this->downscale($bytes, (int) $cfg['store_max_edge'], $mime);
        }

        $ext = match ($mime) {
            'image/png'  => 'png',
            'image/webp' => 'webp',
            'image/avif' => 'avif',
            default      => 'jpg',
        };
        $name = pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);
        // A Greek filename slugifies to nothing at all, which is the normal
        // case here, not the edge case — so the fallback has to be on the empty
        // result, not on preg_replace() returning null (which it never does).
        $slug = trim(strtolower(preg_replace('/[^a-z0-9]+/i', '-', $name) ?? ''), '-');
        $key = date('Y/m/') . ($slug ?: 'image') . '-' . bin2hex(random_bytes(3)) . '.' . $ext;

        $url = $this->cms->r2->put($key, $bytes, $mime);

        return new JsonResponse([
            'ok'      => true,
            'url'     => $url,
            'preview' => $this->cms->imageUrl($url, 400),
        ]);
    }

    private function downscale(string $bytes, int $maxEdge, string $mime): string
    {
        if (!function_exists('imagecreatefromstring')) {
            return $bytes;
        }

        $img = @imagecreatefromstring($bytes);
        if ($img === false) {
            return $bytes;
        }

        $w = imagesx($img);
        $h = imagesy($img);
        if (max($w, $h) <= $maxEdge) {
            imagedestroy($img);
            return $bytes;
        }

        $scale = $maxEdge / max($w, $h);
        $resized = imagescale($img, (int) round($w * $scale), (int) round($h * $scale));
        imagedestroy($img);
        if ($resized === false) {
            return $bytes;
        }

        ob_start();
        match ($mime) {
            'image/png'  => imagepng($resized, null, 6),
            'image/webp' => imagewebp($resized, null, 88),
            default      => imagejpeg($resized, null, 88),
        };
        imagedestroy($resized);

        return (string) ob_get_clean();
    }

    private function csrf(Request $request): string
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start([
                'cookie_httponly' => true,
                'cookie_samesite' => 'Lax',
                // PHP's session cache limiter emits its own Cache-Control. Now
                // that the Response carries one, that is a second, different
                // value on the same header — leave the policy in one place.
                'cache_limiter'   => '',
                // The panel is only ever reached over HTTPS in production;
                // locally there is no session worth stealing. X-Forwarded-Proto
                // is read explicitly rather than through isSecure(), which
                // ignores it until trusted proxies are configured — and behind
                // cloudflared that header is the only signal there is.
                'cookie_secure'   => $request->isSecure()
                    || $request->headers->get('X-Forwarded-Proto') === 'https',
            ]);
        }
        return $_SESSION['csrf'] ??= bin2hex(random_bytes(16));
    }

    private function checkCsrf(Request $request): void
    {
        $token = (string) $request->request->get('csrf', '');
        if (!hash_equals($this->csrf($request), $token)) {
            throw new RuntimeException('Η συνεδρία έληξε. Ανανεώστε τη σελίδα και δοκιμάστε ξανά.');
        }
    }
}
