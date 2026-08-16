<?php

declare(strict_types=1);

namespace Dopamine\FlatCms;

use RuntimeException;
use Throwable;

/**
 * The admin panel.
 *
 * The whole design rule is in save(): the client can change **values**, never
 * **structure**. Blocks are read from the file on disk, and for each block we
 * walk the component's schema and pull only those field names out of $_POST.
 *
 * Consequences, all deliberate:
 *   - a client cannot add, delete, reorder or retype a component
 *   - a field you mark `editable: false` is rendered read-only AND ignored on
 *     save, so a forged POST cannot set it either
 *   - a field name that is not in schema.yml is dropped, even if posted
 *
 * That is what makes this safe to hand to a non-technical client: the worst
 * they can do is write bad copy, and revisions cover that.
 */
final class Admin
{
    public function __construct(private readonly Cms $cms)
    {
    }

    public function handle(): void
    {
        // Start the session before anything can emit output, so the CSRF token
        // is always available no matter which branch we take below.
        $this->csrf();

        $user = $this->cms->auth->requireUser();
        $action = (string) ($_POST['action'] ?? $_GET['action'] ?? 'list');

        try {
            match ($action) {
                'edit'   => $this->edit((string) ($_GET['page'] ?? '')),
                'save'   => $this->save(),
                'upload' => $this->upload(),
                default  => $this->list($user),
            };
        } catch (Throwable $e) {
            http_response_code(400);
            error_log('[dopamine-flatcms] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());

            echo $this->cms->twig->render('admin/error.twig', [
                'message' => $this->safeMessage($e),
                'user'    => $user,
            ]);
        }
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

    private function list(string $user): void
    {
        echo $this->cms->twig->render('admin/list.twig', [
            'pages'  => $this->cms->content->list(),
            'user'   => $user,
            'notice' => $_GET['ok'] ?? null,
            'warn'   => $_GET['warn'] ?? null,
        ]);
    }

    /**
     * @param array<string, mixed> $opts notice, warn, conflict, posted
     */
    private function edit(string $id, array $opts = []): void
    {
        $page = $this->cms->content->load($id);
        if ($page === null) {
            throw new RuntimeException('Η σελίδα δεν βρέθηκε.');
        }

        $user = (string) $this->cms->auth->user();
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
        $heldBy = $this->cms->locks->heldByOther($id, $user);
        $this->cms->locks->touch($id, $user);

        echo $this->cms->twig->render('admin/edit.twig', [
            'page'     => $page,
            'blocks'   => $blocks,
            'csrf'     => $this->csrf(),
            // Always the CURRENT hash: after a conflict the editor is now
            // looking at the other person's version, so saving again is a
            // deliberate overwrite rather than a stale one.
            'baseline' => $this->cms->content->baseline($id),
            'user'     => $user,
            'notice'   => $opts['notice'] ?? null,
            'warn'     => $opts['warn'] ?? null,
            'conflict' => $opts['conflict'] ?? null,
            'held_by'  => $heldBy,
        ]);
    }

    /**
     * Walk a component's schema and produce a clean value map from posted
     * input. Shared by save() and the conflict re-render so both apply exactly
     * the same rules.
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
        bool $enforceRequired = true
    ): array {
        $clean = [];

        foreach ($schema['fields'] as $name => $def) {
            $current = $stored[$name] ?? $def['default'] ?? '';

            if ($def['editable'] !== true || !array_key_exists($name, $in)) {
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

    private function save(): void
    {
        $this->checkCsrf();

        $id = (string) ($_POST['page'] ?? '');
        $posted = (array) ($_POST['blocks'] ?? []);
        $context = $this->cms->fieldContext();

        // The whole read-modify-write runs under an exclusive lock on the page
        // file, and refuses to proceed if the file changed since the form was
        // rendered. Without both, two tabs (or one double-clicked submit)
        // silently discard each other's edits — atomic writes prevent a torn
        // file, they do not prevent lost updates.
        try {
            $this->cms->content->transaction(
                $id,
                (string) ($_POST['baseline'] ?? ''),
                function (array $page) use ($posted, $context): array {
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
                            $context
                        );
                    }

                    // Page title is the one non-block field a client may edit.
                    if (isset($_POST['title'])) {
                        $page['title'] = Fields::sanitise(['type' => 'text', 'max' => 120], $_POST['title']);
                    }

                    return $page;
                }
            );
        } catch (StaleContentException $e) {
            // Refusing the write is correct. Throwing away what they typed is
            // not — re-render the form holding their values against the new
            // version of the page, and let them save again deliberately.
            $this->edit($id, [
                'posted'   => $posted,
                'conflict' => $e->getMessage(),
            ]);
            return;
        }

        $this->cms->locks->release($id, (string) $this->cms->auth->user());

        // Purge the page itself and the `site` tag, which covers everything
        // that renders cross-page data — navigation, language alternates and
        // the sitemap all embed values owned by other pages.
        $purge = $this->cms->cf->purge([Cloudflare::tagFor($id), 'site']);

        $this->redirect('?action=edit&page=' . urlencode($id)
            . ($purge['ok'] ? '&ok=' . urlencode('Οι αλλαγές αποθηκεύτηκαν.')
                            : '&warn=' . urlencode($purge['message'])));
    }

    /**
     * Image upload. Returns JSON so the form can swap the preview without a
     * page reload. Originals are downscaled before storage — a client will
     * absolutely upload a 9 MB photo straight off their phone.
     */
    private function upload(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        $this->checkCsrf();

        $file = $_FILES['file'] ?? null;
        if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new RuntimeException('Η μεταφόρτωση απέτυχε.');
        }

        $cfg = $this->cms->config['images'];
        if ($file['size'] > $cfg['max_upload']) {
            throw new RuntimeException('Το αρχείο είναι πολύ μεγάλο.');
        }

        $mime = (string) (new \finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
        if (!in_array($mime, $cfg['allowed'], true)) {
            throw new RuntimeException('Επιτρέπονται μόνο εικόνες (JPG, PNG, WebP, AVIF).');
        }

        $bytes = (string) file_get_contents($file['tmp_name']);

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
        $slug = strtolower(preg_replace('/[^a-z0-9]+/i', '-', pathinfo((string) $file['name'], PATHINFO_FILENAME)) ?? 'image');
        $key = date('Y/m/') . trim($slug, '-') . '-' . bin2hex(random_bytes(3)) . '.' . $ext;

        $url = $this->cms->r2->put($key, $bytes, $mime);

        echo json_encode([
            'ok'      => true,
            'url'     => $url,
            'preview' => $this->cms->imageUrl($url, 400),
        ], JSON_THROW_ON_ERROR);
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

    private function csrf(): string
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start([
                'cookie_httponly' => true,
                'cookie_samesite' => 'Lax',
                // The panel is only ever reached over HTTPS in production;
                // locally there is no session worth stealing.
                'cookie_secure'   => ($_SERVER['HTTPS'] ?? '') !== ''
                    || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https',
            ]);
        }
        return $_SESSION['csrf'] ??= bin2hex(random_bytes(16));
    }

    private function checkCsrf(): void
    {
        $token = (string) ($_POST['csrf'] ?? '');
        if (!hash_equals($this->csrf(), $token)) {
            throw new RuntimeException('Η συνεδρία έληξε. Ανανεώστε τη σελίδα και δοκιμάστε ξανά.');
        }
    }

    private function redirect(string $to): void
    {
        header('Location: ' . $to, true, 303);
        exit;
    }
}
