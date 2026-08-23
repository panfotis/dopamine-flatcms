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
    /**
     * The actions that write to content/. Everything here runs under the
     * site-wide content lock so the backup job cannot commit a partial write.
     */
    private const MUTATIONS = ['save', 'upload', 'restore'];

    /**
     * Config keys whose value the settings screen must never print. See
     * redact(): named rather than listed by path, so it stays right when
     * config.php grows a credential nobody remembers to add here.
     */
    private const SECRET_KEY = '/secret|token|key|dsn|pass|aud/i';

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

            // Which language this screen is editing, before any flow reads a
            // page. Unknown values are refused by useLocale() rather than
            // silently falling back, because "the save went to the wrong
            // language" is not a failure anyone notices in time.
            $this->cms->useLocale($this->localeOf($request));

            // Every flow that writes to content/ holds the site-wide lock for
            // as long as it writes, so the hourly backup can never commit a
            // half-applied mutation. Taken here rather than in each flow: one
            // place to add an action to, and no way to add one that forgets.
            if (in_array($action, self::MUTATIONS, true)) {
                $release = $this->cms->locks->holdContent();

                try {
                    return $this->dispatch($request, $user, $action);
                } finally {
                    $release();
                }
            }

            return $this->dispatch($request, $user, $action);
        } catch (AccessDeniedException $e) {
            // Both the unauthenticated case and a role refusal land here, so an
            // editor forging ?action=restore gets the same 403 as a stranger —
            // not a 400 that reads like the request was merely malformed.
            return $this->html($this->cms->renderAdmin('@admin/denied.twig', [
                'message' => $e->getMessage(),
                'user'    => $user,
            ]), 403);
        } catch (Throwable $e) {
            error_log('[dopamine-flatcms] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());

            return $this->html($this->cms->renderAdmin('@admin/error.twig', [
                'message' => $this->safeMessage($e),
                'user'    => $user,
            ]), 400);
        }
    }

    /**
     * The language the panel is working in: the request's, or the default.
     *
     * A form carries it as a hidden field so a save lands in the language the
     * editor was looking at, and every link in the panel carries it as a query
     * parameter for the same reason.
     */
    private function localeOf(Request $request): string
    {
        return (string) $request->request->get(
            'locale',
            $request->query->get('locale', $this->cms->defaultLocale())
        );
    }

    /** A lock marker names one document, and a document is a page *in a language*. */
    private function lockKey(string $id): string
    {
        return $this->cms->locale() . '-' . $id;
    }

    /** The `&locale=` suffix panel links carry, or nothing on a single-language site. */
    private function localeQuery(): string
    {
        return count($this->cms->locales()) > 1
            ? '&locale=' . urlencode($this->cms->locale())
            : '';
    }

    /** @param array{email: string, role: string} $user */
    private function dispatch(Request $request, array $user, string $action): Response
    {
        return match ($action) {
            'edit'      => $this->edit($request, $user, (string) $request->query->get('page', '')),
            'save'      => $this->save($request, $user),
            'preview'   => $this->preview($request, $user),
            'upload'    => $this->upload($request),
            'revisions' => $this->revisions($request, $user),
            'restore'   => $this->restore($request, $user),
            'settings'  => $this->settings($user),
            'submissions' => $this->submissions($request, $user),
            'submission'  => $this->submission($request, $user),
            'submission_delete' => $this->submissionDelete($request, $user),
            'submission_retry'  => $this->submissionRetry($request, $user),
            default     => $this->list($request, $user),
        };
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

            throw new AccessDeniedException($this->cms->lang->t('err.admin_required'));
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

        return $this->cms->lang->t('err.generic');
    }

    /**
     * The constraints every schema walk in this class runs under.
     *
     * `uploads` is the server's own record of what this session uploaded, and
     * it is the only place an image's width/height may come from. Keeping it
     * out of Cms::fieldContext() is deliberate: the session belongs to the
     * panel, and the render path has no business carrying one.
     *
     * @return array<string, mixed>
     */
    private function context(): array
    {
        return ['uploads' => (array) ($_SESSION['uploads'] ?? [])] + $this->cms->fieldContext();
    }

    /** @param array{email: string, role: string} $user */
    private function list(Request $request, array $user): Response
    {
        $pages = $this->cms->content->list();

        // Which page ids each other language has, so a row can say "not
        // translated yet" instead of the editor finding out by not finding it.
        // This is the payoff of filename-as-identity: the answer is a directory
        // listing, not a key stored inside every file.
        $elsewhere = [];
        foreach (array_keys($this->cms->locales()) as $code) {
            if ($code !== $this->cms->locale()) {
                $elsewhere[$code] = array_column($this->cms->contentIn($code)->list(), 'id');
            }
        }

        foreach ($pages as $i => $page) {
            $pages[$i]['missing'] = array_values(array_keys(array_filter(
                $elsewhere,
                static fn (array $ids): bool => !in_array($page['id'], $ids, true)
            )));
        }

        // Pages another language has and this one does not. Adding one is
        // adding a file — page creation stays developer-only — so this is a
        // notice, not an action.
        $untranslated = array_values(array_diff(
            array_unique(array_merge(...array_values($elsewhere) ?: [[]])),
            array_column($pages, 'id')
        ));

        return $this->html($this->cms->renderAdmin('@admin/list.twig', [
            'pages'  => $pages,
            // The header and the footer: the same edit screen, reached from a
            // second short table rather than mixed in among pages that have a
            // URL to visit.
            'globals' => $this->cms->content->globals(),
            'locales' => $this->cms->locales(),
            'locale'  => $this->cms->locale(),
            'untranslated' => $untranslated,
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
            throw new RuntimeException($this->cms->lang->t('err.page_missing'));
        }

        $posted = (array) ($opts['posted'] ?? []);
        $context = $this->context();

        // Blocks and SEO already survive a refused/stale save. The title is the
        // one editable value outside both maps, so preserve it explicitly too.
        if (array_key_exists('posted_title', $opts) && $opts['posted_title'] !== null) {
            $page['title'] = Fields::sanitise(
                ['type' => 'text', 'max' => 120],
                $opts['posted_title']
            );
        }

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

        // The page's own SEO, filled in from the schema so a page file that has
        // never carried a `seo:` key still renders every input.
        $seoFields = Components::seoFields();
        $seo = $this->cms->withDefaults(['fields' => $seoFields], (array) ($page['seo'] ?? []));

        // What the head will publish if these are left empty, shown as the
        // input's placeholder. Without it an empty box reads as "this page has
        // no description", which is the opposite of what is true — and an
        // editor cannot decide whether to improve on something they cannot see.
        $seoFields['description']['placeholder'] = $this->cms->seo($page)['description'];

        // Same rule as a block after a conflict: what the editor typed comes
        // back rather than being discarded, through the same sanitiser.
        if (isset($opts['posted_seo'])) {
            $seo = $this->cleanValues(
                $this->seoSchema(),
                $seo,
                (array) $opts['posted_seo'],
                $context,
                $user['role'],
                false
            );
        }

        // Advisory only — see Locks. Read before touching, or you always find
        // yourself.
        // Keyed by language too: `contact` in Greek and `contact` in English
        // are two documents, and one marker for both would warn about a
        // collision that is not happening.
        $heldBy = $this->cms->locks->heldByOther($this->lockKey($id), $user['email']);
        $this->cms->locks->touch($this->lockKey($id), $user['email']);

        // A `link` field is a page picker, so the form needs the page list —
        // and the ids separately, so it can flag a stored id that no longer
        // resolves instead of offering a dead option.
        $pages = $this->cms->content->list();

        return $this->html($this->cms->renderAdmin('@admin/edit.twig', [
            'page'     => $page,
            // A global — the header, the footer — has no URL to visit and no
            // `seo:` to fill in, so the form drops both. Passed as a flag
            // rather than inferred from an empty slug in the template: the rule
            // has one implementation, in Content, and this is it being asked.
            'global'   => Content::isGlobal($id),
            // Carried as a hidden field on the form and on every link, so a
            // save lands in the language the editor was looking at.
            'locale'   => $this->cms->locale(),
            'locales'  => $this->cms->locales(),
            'blocks'   => $blocks,
            'seo'      => $seo,
            'seo_fields' => $seoFields,
            'pages'    => $pages,
            'page_ids' => array_column($pages, 'id'),
            'csrf'     => $this->csrf($request),
            // Always the CURRENT hash: after a conflict the editor is now
            // looking at the other person's version, so saving again is a
            // deliberate overwrite rather than a stale one.
            'baseline' => $this->cms->content->baseline($id),
            'user'     => $user,
            // save() and restore() land here via a 303 carrying the flash in
            // the query string, exactly like the list action reads it.
            'notice'   => $opts['notice'] ?? $request->query->get('ok'),
            'warn'     => $opts['warn'] ?? $request->query->get('warn'),
            'conflict' => $opts['conflict'] ?? null,
            // Field name -> message, looked up by the input renderer.
            'errors'   => (array) ($opts['errors'] ?? []),
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
     * The walk itself lives in Fields::map(), because an image's src/alt pair
     * and a list's rows need exactly this walk applied to *their* schemas too.
     * This method is now only the top-level framing: which role is asking, and
     * which component to name if something required comes back empty.
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
        return Fields::map($schema['fields'], $in, $stored, [
            'role'    => $role,
            'section' => (string) ($schema['label'] ?? ''),
            'require' => $enforceRequired,
        ] + $context);
    }

    /**
     * `seo` in the shape cleanValues() takes a component in.
     *
     * It is not a component — no template, never repeats — but it is a field
     * map, and running it through the same walk is what gives it `editable:
     * admin` on the canonical, undeclared keys dropped, and og_image's
     * dimensions server-derived, without a line of code that is only about SEO.
     *
     * @return array<string, mixed>
     */
    private function seoSchema(): array
    {
        return ['label' => 'SEO', 'fields' => Components::seoFields()];
    }

    /** @param array{email: string, role: string} $user */
    private function save(Request $request, array $user): Response
    {
        $this->checkCsrf($request);

        $id = (string) $request->request->get('page', '');
        $posted = (array) $request->request->all('blocks');
        $postedSeo = (array) $request->request->all('seo');
        $postedTitle = $request->request->has('title')
            ? Fields::sanitise(['type' => 'text', 'max' => 120], $request->request->get('title'))
            : null;
        $context = $this->context();

        // Field name -> message, filled in by the walk below and handed to the
        // form so the message lands beside the box that caused it. Every block
        // is walked even after one refuses, because an editor who fixes one
        // missing alt and is only then told about a second has been made to
        // save twice to learn what was wrong once. Within a block it is still
        // the first refusal — Fields::map() throws — which is one message per
        // card rather than all of them; worth revisiting only if a component
        // ever declares enough required fields for that to bite.
        $errors = [];

        // The whole read-modify-write runs under an exclusive lock on the page
        // file, and refuses to proceed if the file changed since the form was
        // rendered. Without both, two tabs (or one double-clicked submit)
        // silently discard each other's edits — atomic writes prevent a torn
        // file, they do not prevent lost updates.
        try {
            $this->cms->content->transaction(
                $id,
                (string) $request->request->get('baseline', ''),
                function (array $page) use ($id, $user, $posted, $postedSeo, $postedTitle, $context, &$errors): array {
                    foreach ($page['blocks'] as $i => $block) {
                        $schema = $this->cms->components->get((string) $block['type']);
                        if ($schema === null) {
                            continue; // unknown component: leave its stored values alone
                        }

                        // Schema-driven, not input-driven. The result is rebuilt
                        // from the schema key set, so a field removed from
                        // schema.yml disappears from the file on the next save
                        // instead of lingering unsanitised forever.
                        try {
                            $page['blocks'][$i]['fields'] = $this->cleanValues(
                                $schema,
                                (array) ($block['fields'] ?? []),
                                (array) ($posted[$block['id']] ?? []),
                                $context,
                                $user['role']
                            );
                        } catch (ValidationException $e) {
                            $errors[$e->field('blocks[' . $block['id'] . ']')] = $e->getMessage();
                        }
                    }

                    // The page's own SEO, through the identical walk. A card the
                    // editor never opened still posts every input, and every
                    // field in it is optional — so an ignored card writes the
                    // stored values straight back rather than refusing a save.
                    //
                    // A global has no head of its own: it renders inside every
                    // page and its `seo:` would be read by nobody. Skipped so
                    // the walk cannot write an inert five-key map into
                    // _header.yml that a later reader would take for a setting.
                    if (!Content::isGlobal($id)) {
                        try {
                            $page['seo'] = $this->cleanValues(
                                $this->seoSchema(),
                                (array) ($page['seo'] ?? []),
                                $postedSeo,
                                $context,
                                $user['role']
                            );
                        } catch (ValidationException $e) {
                            $errors[$e->field('seo')] = $e->getMessage();
                        }
                    }

                    // Page title is the one non-block field a client may edit.
                    // A global's title is not one: it is the label the panel
                    // lists it under, developer-owned like a slug, and the form
                    // does not offer it — so a posted one was forged.
                    if ($postedTitle !== null && !Content::isGlobal($id)) {
                        $page['title'] = $postedTitle;
                    }

                    // Nothing is written while a single field is refused: a
                    // half-saved page is not a lesser failure than a refused
                    // one, it is a worse one. Thrown from inside the
                    // transaction so the file is never touched.
                    if ($errors !== []) {
                        throw new ValidationException($this->cms->lang->t('flash.nothing_saved'));
                    }

                    return $page;
                }
            );
        } catch (ValidationException $e) {
            // The form comes back with what they typed and a message on every
            // field that needs one — the values are not theirs to lose.
            $response = $this->edit($request, $user, $id, [
                'posted'     => $posted,
                'posted_seo' => $postedSeo,
                'posted_title' => $postedTitle,
                'errors'     => $errors,
            ]);
            $response->setStatusCode(422);

            return $response;
        } catch (StaleContentException $e) {
            // Refusing the write is correct. Throwing away what they typed is
            // not — re-render the form holding their values against the new
            // version of the page, and let them save again deliberately.
            return $this->edit($request, $user, $id, [
                'posted'     => $posted,
                'posted_seo' => $postedSeo,
                'posted_title' => $postedTitle,
                'conflict'   => $e->getMessage(),
            ]);
        }

        $this->cms->locks->release($this->lockKey($id), $user['email']);

        // The upload records are redeemed by the save that stores them: their
        // dimensions are now on disk beside the src, which is where the next
        // save reads them from. Clearing them here is what makes the token
        // one-time rather than a growing pile of session state.
        unset($_SESSION['uploads']);

        // Purge the page itself and the `site` tag, which covers everything
        // that renders cross-page data — navigation, language alternates and
        // the sitemap all embed values owned by other pages.
        $purge = $this->cms->cf->purge([Cloudflare::tagFor($id), 'site']);

        return new RedirectResponse('?action=edit&page=' . urlencode($id) . $this->localeQuery()
            . ($purge['ok'] ? '&ok=' . urlencode($this->cms->lang->t('flash.saved'))
                            : '&warn=' . urlencode($purge['message'])), 303);
    }

    /**
     * The page as it would render if this form were saved — without saving it.
     *
     * The same walk save() runs, minus the transaction: values come off the
     * request, go through Fields::map() exactly as they would on the way to
     * disk, and are handed to renderPage(). That equivalence is the whole
     * safety argument — richtext is sanitised by the same allowlist, a field
     * the role may not edit keeps its stored value, and an undeclared key is
     * dropped. There is no way to paint something a save would have refused.
     *
     * **Not in MUTATIONS, deliberately.** It writes nothing, takes no content
     * lock and snapshots nothing. A read-only render holding the site-wide lock
     * would block the hourly backup for as long as someone had a preview open.
     *
     * `enforceRequired: false`, because half a page is exactly what someone
     * wants to look at before deciding whether to finish it.
     *
     * @param array{email: string, role: string} $user
     */
    private function preview(Request $request, array $user): Response
    {
        $this->checkCsrf($request);

        $id = (string) $request->request->get('page', '');
        $page = $this->cms->content->load($id);
        if ($page === null) {
            throw new RuntimeException($this->cms->lang->t('err.page_missing'));
        }

        // A global has no address and no document of its own — it renders
        // inside every page. Previewing one means rendering some other page
        // with it, which is a different feature; the button is not offered.
        if (Content::isGlobal($id)) {
            throw new RuntimeException($this->cms->lang->t('err.preview_global'));
        }

        $posted = (array) $request->request->all('blocks');
        $context = $this->context();

        foreach ($page['blocks'] as $i => $block) {
            $schema = $this->cms->components->get((string) $block['type']);
            if ($schema === null) {
                continue;
            }

            $page['blocks'][$i]['fields'] = $this->cleanValues(
                $schema,
                (array) ($block['fields'] ?? []),
                (array) ($posted[$block['id']] ?? []),
                $context,
                $user['role'],
                false
            );
        }

        // The head is part of what someone is previewing — a changed title or
        // description shows up in the tab and in the share card.
        $page['seo'] = $this->cleanValues(
            $this->seoSchema(),
            (array) ($page['seo'] ?? []),
            (array) $request->request->all('seo'),
            $context,
            $user['role'],
            false
        );

        if ($request->request->has('title')) {
            $page['title'] = Fields::sanitise(['type' => 'text', 'max' => 120], $request->request->get('title'));
        }

        return $this->html($this->cms->renderPage($page));
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
            throw new RuntimeException($this->cms->lang->t('err.page_missing'));
        }

        return $this->html($this->cms->renderAdmin('@admin/revisions.twig', [
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
        $context = $this->context();

        $this->cms->content->restore(
            $id,
            (string) $request->request->get('revision', ''),
            function (array $revision, array $page) use ($id, $user, $context): array {
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

                // A revision's SEO is untrusted input like the rest of it: the
                // canonical in a file written last month has never been through
                // the current URL rule, and it goes into a <link> in the head.
                // Same exemption as save(): a global has no head to reach.
                if (!Content::isGlobal($id)) {
                    $page['seo'] = $this->cleanValues(
                        $this->seoSchema(),
                        (array) ($page['seo'] ?? []),
                        (array) ($revision['seo'] ?? []),
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

        return new RedirectResponse('?action=edit&page=' . urlencode($id) . $this->localeQuery()
            . '&ok=' . urlencode($this->cms->lang->t('flash.restored')), 303);
    }

    /**
     * What this install is configured as. Admin only, and read-only.
     *
     * Read-only is the feature, not a shortcut: `config.php` is the developer's
     * file and most of it resolves from the environment, so a value edited here
     * would either be overwritten on the next boot or mean the panel writing
     * PHP to disk. This screen answers "what is live" — bin/doctor's view
     * without the shell — and deliberately nothing else. Not in MUTATIONS: it
     * writes nothing, so it has no business taking the content lock.
     *
     * @param array{email: string, role: string} $user
     */
    private function settings(array $user): Response
    {
        $this->requireAdmin($user);

        return $this->html($this->cms->renderAdmin('@admin/settings.twig', [
            // The absolute paths under `paths` are shown as-is, unlike anywhere
            // else in the panel — safeMessage() hides them precisely because it
            // cannot know who is reading. Here it can: which shared/ directory
            // the running release is pointed at is the thing you open this
            // screen to check, and only an admin can reach it.
            'sections' => $this->redact($this->cms->config),
            'user'     => $user,
        ]));
    }

    /**
     * Credentials are never rendered — not truncated, not fingerprinted, only
     * "set" or "not set".
     *
     * Matching on the key *name* rather than an allowlist of known paths is the
     * point: a secret added to config.php later is masked without anyone
     * remembering to come back here. A false positive costs one dull row on an
     * admin-only page; a false negative publishes an API token.
     *
     * @param  array<string, mixed> $config
     * @return array<string, mixed>
     */
    private function redact(array $config): array
    {
        foreach ($config as $key => $value) {
            if (is_array($value)) {
                $config[$key] = $this->redact($value);
                continue;
            }

            // Strings only. A credential is a string, and matching on the name
            // alone would mask `auth.dev_bypass` — the one flag on this screen
            // whose value someone urgently needs to read — as "set".
            if (is_string($value) && preg_match(self::SECRET_KEY, (string) $key) === 1) {
                $config[$key] = $this->cms->lang->t($value === '' ? 'settings.unset' : 'settings.set');
            }
        }

        return $config;
    }

    /**
     * Form submissions, admin only.
     *
     * Not in MUTATIONS, and deliberately: these live in var/, not content/, so
     * the site-wide content lock — which exists so the hourly `git add -A` over
     * content/ cannot capture a half-applied save — has nothing to protect
     * here. Adding them to it would be cargo cult.
     *
     * @param array{email: string, role: string} $user
     */
    private function submissions(Request $request, array $user): Response
    {
        $this->requireAdmin($user);

        return $this->html($this->cms->renderAdmin('@admin/submissions.twig', [
            'submissions' => $this->cms->submissions->all(),
            'csrf'   => $this->csrf($request),
            'user'   => $user,
            'notice' => $request->query->get('ok'),
            'warn'   => $request->query->get('warn'),
            'retain' => (int) $this->cms->config['form']['retain_months'],
        ]));
    }

    /** @param array{email: string, role: string} $user */
    private function submission(Request $request, array $user): Response
    {
        $this->requireAdmin($user);

        $record = $this->cms->submissions->get(
            (string) $request->query->get('month', ''),
            (string) $request->query->get('id', '')
        );
        if ($record === null) {
            throw new RuntimeException($this->cms->lang->t('err.submission_missing'));
        }

        return $this->html($this->cms->renderAdmin('@admin/submission.twig', [
            'record' => $record,
            'max_attempts' => max(1, (int) $this->cms->config['form']['max_attempts']),
            'csrf'   => $this->csrf($request),
            'user'   => $user,
        ]));
    }

    /**
     * Erase one submission. Admin only, CSRF-protected.
     *
     * The erasure path a privacy notice promises has to be a button someone can
     * actually press, or it is a sentence.
     *
     * @param array{email: string, role: string} $user
     */
    private function submissionDelete(Request $request, array $user): Response
    {
        $this->requireAdmin($user);
        $this->checkCsrf($request);

        $this->cms->submissions->delete(
            (string) $request->request->get('month', ''),
            (string) $request->request->get('id', '')
        );

        return new RedirectResponse('?action=submissions&ok=' . urlencode($this->cms->lang->t('sub.deleted')), 303);
    }

    /**
     * Try one unsent submission again, by hand.
     *
     * A record flagged `review` is left alone here as well as by the cron: an
     * ambiguous transport error may already have been delivered, and a client
     * getting the same lead five times is its own kind of broken.
     *
     * @param array{email: string, role: string} $user
     */
    private function submissionRetry(Request $request, array $user): Response
    {
        $this->requireAdmin($user);
        $this->checkCsrf($request);

        $month = (string) $request->request->get('month', '');
        $id = (string) $request->request->get('id', '');
        $record = $this->cms->submissions->get($month, $id);
        if ($record === null) {
            throw new RuntimeException($this->cms->lang->t('err.submission_missing'));
        }

        if (($record['status'] ?? '') !== Submissions::UNSENT
            || (int) ($record['attempts'] ?? 0) >= max(1, (int) $this->cms->config['form']['max_attempts'])) {
            return new RedirectResponse(
                '?action=submissions&warn=' . urlencode($this->cms->lang->t('sub.not_retryable')),
                303
            );
        }

        $sent = (new Form($this->cms))->deliver($record, $this->recipientFor($record));

        return new RedirectResponse('?action=submissions&' . ($sent
            ? 'ok=' . urlencode($this->cms->lang->t('sub.sent_ok'))
            : 'warn=' . urlencode($this->cms->lang->t('sub.not_sent'))), 303);
    }

    /**
     * The block values the submission came from, so a retry mails the same
     * recipient the original would have.
     *
     * Read back off the page rather than stored on the record: the client may
     * legitimately have changed the recipient since, and the current answer is
     * the right one.
     *
     * @param  array<string, mixed> $record
     * @return array<string, mixed>
     */
    private function recipientFor(array $record): array
    {
        $this->cms->useLocale((string) ($record['locale'] ?? $this->cms->defaultLocale()));
        $page = $this->cms->content->load((string) ($record['page'] ?? ''));
        if ($page === null) {
            return [];
        }

        $block = (new Form($this->cms))->blockOn($page);
        $schema = $block === null ? null : $this->cms->components->get((string) $block['type']);

        return $schema === null ? [] : $this->cms->withDefaults($schema, $block['fields']);
    }

    /**
     * Image upload. Returns JSON so the form can swap the preview without a
     * page reload. Originals are normalized before storage — a client will
     * absolutely upload a 9 MB photo straight off their phone.
     */
    private function upload(Request $request): Response
    {
        $this->checkCsrf($request);

        $file = $request->files->get('file');
        if (!$file instanceof UploadedFile || !$file->isValid()) {
            throw new RuntimeException($this->cms->lang->t('up.failed'));
        }

        $cfg = $this->cms->config['images'];

        // The client-supplied Content-Type is not evidence of anything, so sniff
        // the bytes. Deliberately not UploadedFile::getMimeType(), which needs
        // symfony/mime — one more dependency to do what ext-fileinfo already does.
        $mime = (string) (new \finfo(FILEINFO_MIME_TYPE))->file($file->getPathname());

        // A background loop is the one non-image upload the panel accepts, and
        // it takes a different route entirely: no GD, no derivatives, a much
        // harder size cap, and MP4 only. Everything a video needs guarding
        // against is size and type, and both are checked before a byte moves.
        if (str_starts_with($mime, 'video/')) {
            return $this->uploadVideo($file, $mime);
        }

        if ($file->getSize() > $cfg['max_upload']) {
            throw new RuntimeException($this->cms->lang->t('up.too_large'));
        }

        if (!in_array($mime, $cfg['allowed'], true)) {
            throw new RuntimeException($this->cms->lang->t('up.images_only'));
        }

        $bytes = (string) file_get_contents($file->getPathname());

        // Check the declared dimensions before handing anything to GD. A
        // crafted 30000x30000 PNG is a few hundred KB on disk and roughly
        // 3.6 GB once decoded, which takes the worker out.
        $size = @getimagesizefromstring($bytes);
        if ($size === false) {
            throw new RuntimeException($this->cms->lang->t('up.not_an_image'));
        }
        if (($size[0] * $size[1]) > (int) $cfg['max_pixels']) {
            throw new RuntimeException($this->cms->lang->t('up.too_many_pixels'));
        }

        $scale = (int) $cfg['store_max_edge'] > 0
            ? min(1, (int) $cfg['store_max_edge'] / max($size[0], $size[1]))
            : 1;
        $storedWidth = max(1, (int) round($size[0] * $scale));
        $storedHeight = max(1, (int) round($size[1] * $scale));
        $pixelBytes = (($size[0] * $size[1]) + ($storedWidth * $storedHeight)) * 4;
        if ($pixelBytes > (int) $cfg['upload_memory_budget']) {
            throw new RuntimeException($this->cms->lang->t('up.too_many_pixels'));
        }

        [$bytes, $width, $height] = $this->normalize($bytes, (int) $cfg['store_max_edge'], $mime);

        $ext = match ($mime) {
            'image/png'  => 'png',
            'image/webp' => 'webp',
            default      => 'jpg',
        };
        $name = pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);
        // A Greek filename slugifies to nothing at all, which is the normal
        // case here, not the edge case — so the fallback has to be on the empty
        // result, not on preg_replace() returning null (which it never does).
        $slug = trim(strtolower(preg_replace('/[^a-z0-9]+/i', '-', $name) ?? ''), '-');
        $key = date('Y/m/') . ($slug ?: 'image') . '-' . bin2hex(random_bytes(3)) . '.' . $ext;

        $url = $this->cms->r2->put($key, $bytes, $mime);

        // The server's record of what it just wrote. The save path reads the
        // dimensions from here and never from the request body, so a forged
        // width is not rejected — it is never consulted. Redeemed and cleared
        // by the save that stores this src.
        $_SESSION['uploads'][$url] = ['width' => $width, 'height' => $height];

        return new JsonResponse([
            'ok'      => true,
            'url'     => $url,
            'width'   => $width,
            'height'  => $height,
            'preview' => $this->cms->imageUrl($url, 320),
        ]);
    }

    /**
     * A short background loop: MP4 in, MP4 out, nothing in between.
     *
     * No transcoding and no renditions — that is a pipeline, not a feature, and
     * the plan says so. What is left is the two things that actually bite: a
     * hard size cap, because a client will otherwise upload a two-minute phone
     * video and put it behind their hero; and a real container check, because
     * the extension and the declared type are both the client's to choose.
     *
     * The bytes are stored untouched, so unlike an image there is no metadata
     * strip. That is stated rather than assumed: an MP4 can carry GPS in a
     * `moov` atom, and the honest mitigation is that these are produced
     * deliberately as background footage, not shot on the client's phone at
     * home. Say so in the panel hint rather than pretend GD-style scrubbing
     * happened.
     */
    private function uploadVideo(UploadedFile $file, string $mime): Response
    {
        $max = (int) ($this->cms->config['video']['max_upload'] ?? 10 * 1024 * 1024);
        if ($file->getSize() > $max) {
            throw new RuntimeException($this->cms->lang->t('up.video_too_large', intdiv($max, 1024 * 1024)));
        }

        if ($mime !== 'video/mp4') {
            throw new RuntimeException($this->cms->lang->t('up.mp4_only'));
        }

        $bytes = (string) file_get_contents($file->getPathname());

        // finfo says "video/mp4" from the ftyp box; check the box itself too,
        // because that is the one structure the whole claim rests on.
        if (substr($bytes, 4, 4) !== 'ftyp') {
            throw new RuntimeException($this->cms->lang->t('up.not_an_mp4'));
        }

        $name = pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);
        $slug = trim(strtolower(preg_replace('/[^a-z0-9]+/i', '-', $name) ?? ''), '-');
        $key = date('Y/m/') . ($slug ?: 'video') . '-' . bin2hex(random_bytes(3)) . '.mp4';

        $url = $this->cms->r2->put($key, $bytes, $mime);

        return new JsonResponse(['ok' => true, 'url' => $url, 'video' => true]);
    }

    /**
     * Re-encode an upload to the bytes we are willing to store, and report the
     * intrinsic dimensions of the result.
     *
     * It runs even when the image is already inside `store_max_edge`: "small
     * enough" is not a reason to keep EXIF, and a phone photo carries the GPS
     * coordinates of the client's house in it. Re-encoding through GD drops
     * every metadata block there is, which is the cheapest possible way to be
     * sure — but it also drops the orientation flag, so that has to be applied
     * to the pixels first or half the portraits on the site come out sideways.
     *
     * @return array{0: string, 1: int, 2: int} bytes, width, height
     */
    private function normalize(string $bytes, int $maxEdge, string $mime): array
    {
        $img = @imagecreatefromstring($bytes);
        if ($img === false) {
            throw new RuntimeException($this->cms->lang->t('up.not_an_image'));
        }

        try {
            $img = $this->orient($img, $bytes, $mime);

            // PNG and WebP may carry alpha and the site's images sit on light
            // and dark backgrounds both; flattening a logo here is a bug the
            // client sees and cannot fix. Set before the scale — imagescale()
            // composites onto a fresh canvas, and a source still in blending
            // mode arrives there flattened against black.
            imagealphablending($img, false);
            imagesavealpha($img, true);

            $w = imagesx($img);
            $h = imagesy($img);

            if ($maxEdge > 0 && max($w, $h) > $maxEdge) {
                $scale = $maxEdge / max($w, $h);
                $resized = imagescale($img, max(1, (int) round($w * $scale)), max(1, (int) round($h * $scale)));
                if ($resized !== false) {
                    imagedestroy($img);
                    $img = $resized;
                    imagealphablending($img, false);
                    imagesavealpha($img, true);
                }
            }

            ob_start();
            match ($mime) {
                'image/png'  => imagepng($img, null, 6),
                'image/webp' => imagewebp($img, null, 88),
                default      => imagejpeg($img, null, 88),
            };

            return [(string) ob_get_clean(), imagesx($img), imagesy($img)];
        } finally {
            imagedestroy($img);
        }
    }

    /**
     * Apply the EXIF orientation flag to the pixels.
     *
     * Only JPEG carries one in practice, and normally nobody would need to:
     * browsers honour the tag on a plain <img> by themselves. We have to,
     * because normalize() re-encodes through GD to strip EXIF — the point being
     * the GPS coordinates of the client's house — and that throws the
     * orientation tag away with everything else. Rotate first or the photo is
     * sideways forever, in the stored original and in every derivative GD
     * scales from it.
     *
     * ext-exif is declared in composer.json, so this cannot be missing on a box
     * that installed cleanly. The guard stays because Composer resolves under
     * the CLI php and the site runs under php-fpm, which can be a different
     * build: a sideways photo is a bug, a 500 on upload is an outage.
     *
     * @param \GdImage $img
     */
    private function orient(\GdImage $img, string $bytes, string $mime): \GdImage
    {
        if ($mime !== 'image/jpeg' || !function_exists('exif_read_data')) {
            return $img;
        }

        $exif = @exif_read_data('data://image/jpeg;base64,' . base64_encode($bytes));
        $rotate = match ((int) ($exif['Orientation'] ?? 1)) {
            3, 4 => 180,
            5, 6 => -90,
            7, 8 => 90,
            default => 0,
        };
        $flip = in_array((int) ($exif['Orientation'] ?? 1), [2, 4, 5, 7], true);

        if ($rotate !== 0) {
            $turned = imagerotate($img, $rotate, 0);
            if ($turned !== false) {
                imagedestroy($img);
                $img = $turned;
            }
        }
        if ($flip) {
            imageflip($img, IMG_FLIP_HORIZONTAL);
        }

        return $img;
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
            throw new RuntimeException($this->cms->lang->t('err.session'));
        }
    }
}
