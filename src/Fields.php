<?php

declare(strict_types=1);

namespace Dopamine\FlatCms;

use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerAction;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;

/**
 * The field types that cover a brochure site.
 *
 * Every value the client submits goes through sanitise() before it is written
 * to disk. In particular richtext is whitelisted down to a handful of inline
 * tags, so a paste from Word cannot smuggle <style>, <font> or a <script> into
 * the page and wreck the design.
 *
 * Two of those types — `image` and `list` — hold maps rather than strings, so
 * they need the schema walk applied to their contents as well as to
 * themselves. map() is that walk, and it is the *only* one: the top level, an
 * image's src/alt pair and a list's rows all reach their schema through it, so
 * "undeclared keys are dropped" and "editable is enforced" are one rule with
 * one implementation rather than one per nesting depth.
 */
final class Fields
{
    public const TYPES = [
        'text', 'textarea', 'richtext', 'image', 'link', 'url', 'select', 'boolean', 'list',
        'image_list', 'video_embed', 'video_loop',
    ];

    /**
     * A list with no `max` in its schema is a developer typo, not a licence to
     * store an unbounded repeater. 20 rows is the plan's own example and far
     * more than a FAQ or a team grid needs.
     */
    private const LIST_MAX = 20;

    /**
     * The same guard for a gallery, with the ceiling a gallery actually needs.
     * A client uploading a wedding album is a different product; 60 photos is
     * already more than a brochure site should be serving on one page.
     */
    private const GALLERY_MAX = 60;

    /**
     * The two providers, and the *whole* of what is accepted from a paste.
     *
     * A pattern that matched the host and then took the rest of the URL on
     * trust is how a path segment ends up inside an attribute. The capture is
     * the id charset itself, so what is stored can only ever be an id.
     *
     * @var array<string, list<string>>
     */
    private const PROVIDERS = [
        'youtube' => [
            '#^https?://(?:www\.)?youtube\.com/watch\?(?:[^&]*&)*v=([A-Za-z0-9_-]{6,20})#i',
            '#^https?://(?:www\.)?youtube\.com/embed/([A-Za-z0-9_-]{6,20})#i',
            '#^https?://youtu\.be/([A-Za-z0-9_-]{6,20})#i',
        ],
        'vimeo' => [
            '#^https?://(?:www\.)?vimeo\.com/(\d{6,12})#i',
            '#^https?://player\.vimeo\.com/video/(\d{6,12})#i',
        ],
    ];

    /**
     * The sub-schema of every `image` field, whatever the component calls it.
     *
     * It is built in rather than declared per component precisely so it cannot
     * drift: `text_image` used to carry a separate `image_alt` beside its
     * image and `hero` carried none at all, which is how "add an image, forget
     * the alt" happened in the first place.
     *
     * Public so the panel can build the same two inputs it validates against;
     * a component copies it, it never declares its own.
     */
    public const IMAGE = [
        'src' => ['type' => 'media', 'label' => 'field.image'],
        'alt' => [
            'type'  => 'text',
            'max'   => 120,
            // Keys, not sentences: Components::normalise() resolves them
            // through the panel catalogue, so the one built-in field map is
            // translated by the same mechanism as everything else.
            'label' => 'field.alt',
            'hint'  => 'field.alt_hint',
        ],
    ];

    /**
     * The sub-schema of every `link` field, built in for the same reason IMAGE
     * is: a component declares `type: link` and gets the whole control.
     *
     * A link is a map rather than a string because a destination is three
     * decisions, not one: *which* page, or failing that which address, and
     * whether it opens where the visitor already is. Storing only the page id
     * — which is what this shipped as — meant a client who wanted to point a
     * button at an Instagram profile had to ask a developer for a second field
     * beside it, and `target` had nowhere to live at all.
     *
     * `page` wins over `url` and clears it on save. Two destinations in one
     * field is a value nobody can read back, and picking a page is the
     * deliberate act: the id survives a slug rename, a typed URL does not.
     *
     * `page` is an internal type — `pageId()` under a name a component cannot
     * declare — exactly as `media` is inside IMAGE.
     */
    public const LINK = [
        'page' => ['type' => 'page', 'label' => 'field.link_page'],
        'url'  => [
            'type'  => 'url',
            'label' => 'field.link_url',
            'hint'  => 'field.link_url_hint',
        ],
        'target' => [
            'type'    => 'select',
            'label'   => 'field.link_target',
            'default' => '_self',
            // An allowlist, and the reason `target` is a select rather than a
            // text field: the value is written straight into an attribute.
            'options' => [
                '_self'   => 'field.target_self',
                '_blank'  => 'field.target_blank',
                '_parent' => 'field.target_parent',
                '_top'    => 'field.target_top',
            ],
        ],
    ];

    /**
     * The page-level `seo` map, built in for the same reason IMAGE is: every
     * page on every site carries the same five keys, and the panel builds the
     * inputs the save path validates against rather than a parallel set.
     *
     * It is not a component — no template, never repeats — but it *is* a field
     * map, so it goes through Components::normalise() and Fields::map() like
     * any other. That it lives in the per-locale page file is what makes
     * per-language SEO free rather than a second mechanism to keep in step.
     *
     * `canonical` is `editable: admin` deliberately. Every other field here
     * costs a client a worse search result if they get it wrong; a canonical
     * pointing at the wrong URL deindexes the page, silently, for weeks.
     *
     * Every hint says what the field *does*, in the terms of what the client
     * will see happen. "Βοηθά το SEO" is the hint that gets a field left empty
     * on twenty sites, because it does not tell anyone what to type.
     */
    public const SEO = [
        'title' => [
            'type'  => 'text',
            'max'   => 60,
            'label' => 'seo.title',
            'hint'  => 'seo.title_hint',
        ],
        'description' => [
            'type'  => 'textarea',
            'max'   => 155,
            'label' => 'seo.description',
            'hint'  => 'seo.description_hint',
        ],
        'og_image' => [
            'type'  => 'image',
            'label' => 'seo.og_image',
            // Decorative, so no alt input and no og:image:alt. The share card
            // already carries the title and the description as text, and this
            // image is a banner beside them — asking the client to describe it
            // a second time buys "εικόνα" typed to clear a field.
            'decorative' => true,
            'hint'  => 'seo.og_image_hint',
        ],
        'noindex' => [
            'type'  => 'boolean',
            'label' => 'seo.noindex',
            'hint'  => 'seo.noindex_hint',
        ],
        'canonical' => [
            'type'     => 'url',
            'editable' => 'admin',
            'label'    => 'seo.canonical',
            'hint'     => 'seo.canonical_hint',
        ],
    ];

    /** Tags a client is allowed to produce in a richtext field. */
    private const ALLOWED_TAGS = ['p', 'br', 'strong', 'b', 'em', 'i', 'u', 'ul', 'ol', 'li'];

    /**
     * Elements whose text content is code rather than prose, and which the
     * sanitiser will not remove for us — see rich().
     */
    private const CODE_ELEMENTS = ['style', 'script', 'template', 'noscript'];

    /**
     * Hard ceiling on a richtext value, independent of any `max` in the schema.
     * Without it an 8 MB paste is stored and then copied into ten revisions.
     */
    private const RICHTEXT_LIMIT = 100_000;

    /**
     * Walk a field map over a value map. **The** schema walk, at every depth.
     *
     * Whatever arrives that the schema does not declare is not copied, and
     * whatever the schema declares that did not arrive keeps what is on disk.
     * That is the product rule — structure is developer-owned — and running it
     * inside image and list as well as at the top level is what stops a nested
     * value from being the one place it does not hold.
     *
     * @param  array<string, array<string, mixed>> $fields schema.yml, normalised
     * @param  array<string, mixed>                $in     untrusted input
     * @param  array<string, mixed>                $stored what is on disk now
     * @param  array<string, mixed>                $context media_bases, role,
     *                                                      section, require,
     *                                                      uploads
     * @return array<string, mixed>
     */
    public static function map(array $fields, array $in, array $stored, array $context): array
    {
        $out = [];
        $refused = [];

        foreach ($fields as $name => $def) {
            $current = $stored[$name] ?? $def['default'] ?? self::blank((string) ($def['type'] ?? 'text'));

            // A field the role may not edit, or simply did not send, keeps its
            // stored value — whether that input came from a form, a forged POST
            // or an old revision, and whether it sits at the top level or
            // inside a list row.
            if (!Components::mayEdit($def['editable'] ?? true, (string) ($context['role'] ?? ''))
                || !array_key_exists($name, $in)) {
                $out[$name] = $current;
                continue;
            }

            // Whatever depth the refusal came from, this frame knows one more
            // segment of the path to the input that caused it — which is what
            // lets the panel put the message beside the box instead of on an
            // error page with the form gone. The walk carries on past a
            // refusal so every empty required field is marked in one round
            // trip; nothing is written anyway while a single one stands.
            try {
                $out[$name] = self::sanitise($def, $in[$name], ['stored' => $current] + $context);
                self::demand($def, $out[$name], $context);
            } catch (ValidationException $e) {
                $refused[] = $e->under($name);
                $out[$name] = $current;
            }
        }

        if ($refused !== []) {
            throw ValidationException::merge($refused);
        }

        return $out;
    }

    /**
     * The one required check. Called from map() at every depth, so an image's
     * alt is refused by the same machinery that refuses an empty heading —
     * there is no second validation system to keep in step with this one.
     *
     * @param array<string, mixed> $def
     * @param array<string, mixed> $context
     */
    public static function demand(array $def, mixed $value, array $context, string $name = ''): void
    {
        if (($context['require'] ?? true) !== true || ($def['required'] ?? false) !== true) {
            return;
        }

        if ($value === '' || $value === [] || $value === false) {
            // The catalogue arrives in $context like every other runtime
            // constraint — media_bases, role, uploads. A label that is a
            // built-in key resolves; one a component wrote itself falls back to
            // the literal, which is exactly what a per-site label should do.
            $lang = $context['lang'] ?? null;
            $say = static fn (string $k, string|int ...$a): string
                => $lang instanceof Lang ? $lang->t($k, ...$a) : $k;

            throw new ValidationException(
                $say(
                    'field.required_in',
                    $say((string) ($def['label'] ?? '')),
                    $say((string) ($context['section'] ?? ''))
                ),
                // map() names the field it is walking; a sub-field the schema
                // does not declare — an image's alt — has to name itself.
                $name === '' ? [] : [$name]
            );
        }
    }

    /**
     * The empty value of a type. A field nobody has filled in yet must still
     * have the right *shape*, or a template branches on '' where it expects a
     * map — and Phase 6's og_image, an empty map on every page, would be
     * indistinguishable from a missing one.
     */
    public static function blank(string $type): string|bool|array
    {
        return match ($type) {
            'image', 'list', 'image_list' => [],
            'boolean'     => false,
            // Both are maps a template branches on, so an empty one has to be
            // the right *shape* — `fields.video.provider` must be readable on a
            // page nobody has filled in yet.
            'video_embed' => ['provider' => '', 'id' => ''],
            'video_loop'  => ['src' => '', 'poster' => []],
            'link'        => ['page' => '', 'url' => '', 'target' => '_self'],
            default       => '',
        };
    }

    /**
     * @param array<string, mixed> $def     Field definition from schema.yml
     * @param array<string, mixed> $context Runtime constraints; `media_bases`
     *                                      lists the prefixes an image src may
     *                                      use. Empty means "reject everything
     *                                      that is not a relative upload path".
     */
    public static function sanitise(array $def, mixed $raw, array $context = []): string|bool|array
    {
        $type = (string) ($def['type'] ?? 'text');
        $value = is_scalar($raw) ? (string) $raw : '';

        return match ($type) {
            'textarea' => self::plain($value, $def['max'] ?? null, true),
            'richtext' => self::rich($value),
            'image'    => self::image($def, $raw, $context),
            'media'    => self::mediaPath($value, (array) ($context['media_bases'] ?? [])),
            'link'     => self::linkMap($def, $raw, $context),
            // Internal, like `media`: the page half of a link field. Kept out
            // of TYPES so a component cannot declare a bare page id and lose
            // the target with it.
            'page'     => self::pageId($value),
            // The same href rule richtext uses, exposed as a type for the one
            // field that really is a URL the client types. A second URL rule
            // beside link() is how the two would come to disagree.
            'url'      => self::link($value),
            'select'   => self::select($def, $value),
            'boolean'  => self::boolean($raw),
            'list'     => self::items($def, $raw, $context),
            'image_list'  => self::gallery($def, $raw, $context),
            'video_embed' => self::embed($value),
            'video_loop'  => self::loop($def, $raw, $context),
            default    => self::plain($value, $def['max'] ?? null, false),
        };
    }

    /**
     * An image is a map, not a path: `src`, `alt`, and the original's intrinsic
     * `width`/`height`.
     *
     * Folding alt in is what makes it unforgettable — declare an image field
     * and the alt field comes with it, so a new component cannot ship without
     * one the way `hero` did. The pairing is only half of it, though: alt is
     * *required* whenever src is set, and `decorative: true` in schema.yml is
     * the escape hatch for an image that genuinely carries no information.
     * Requiring it unconditionally is the classic accessibility own-goal — it
     * buys "image", "photo" and `logo123.jpg` typed to clear a validation
     * error, which a screen reader then reads out instead of skipping.
     *
     * @param  array<string, mixed> $def
     * @param  array<string, mixed> $context
     * @return array{src: string, alt: string, width: int, height: int}
     */
    private static function image(array $def, mixed $raw, array $context): array
    {
        $stored = is_array($context['stored'] ?? null) ? $context['stored'] : [];

        $out = self::map(self::IMAGE, is_array($raw) ? $raw : [], $stored, $context);
        $src = (string) $out['src'];

        if (($def['decorative'] ?? false) === true) {
            // alt="" is the *correct* markup here, and it is the developer who
            // knows that — so it is declared, not typed. A forged `decorative`
            // in the request never reaches this: it is an undeclared key inside
            // the image map and map() has already dropped it.
            $out['alt'] = '';
        } else {
            // An image field left empty saves fine; one with a file and no
            // description does not. The condition is the point — Phase 6's
            // og_image defaults to an empty map on every page, and an
            // unconditional rule would make every page unsaveable.
            self::demand(
                ['label' => self::IMAGE['alt']['label'], 'required' => $src !== ''],
                $out['alt'],
                $context,
                'alt'
            );
        }

        return $out + self::dimensions($src, $stored, $context);
    }

    /**
     * `width`/`height` are **server-derived**, and this is where that is true
     * rather than merely intended: neither is ever read from $raw.
     *
     * A newly uploaded src carries its normalized dimensions in the record the
     * upload wrote into the session; an unchanged src keeps what is already on
     * disk beside it. Anything else — a src the client typed, a stored map
     * whose src has since been replaced — is unknown, and unknown is 0, which
     * picture.twig renders as no attribute at all. A wrong aspect ratio
     * reserves the wrong box and is worse than reserving none.
     *
     * @param  array<string, mixed> $stored
     * @param  array<string, mixed> $context
     * @return array{width: int, height: int}
     */
    private static function dimensions(string $src, array $stored, array $context): array
    {
        $record = (array) (($context['uploads'] ?? [])[$src] ?? []);

        if ($record === [] && $src !== '' && ($stored['src'] ?? null) === $src) {
            $record = $stored;
        }

        return [
            'width'  => max(0, (int) ($record['width'] ?? 0)),
            'height' => max(0, (int) ($record['height'] ?? 0)),
        ];
    }

    /**
     * A gallery: a list of image maps, and nothing else.
     *
     * It is a separate type rather than `list` with one `image` inside it
     * because thirty photos through a generic repeater is unusable — the panel
     * needs a grid, not thirty stacked cards. The *validation* is the same walk
     * either way, which is the point: this reuses map() over IMAGE per row, so
     * "undeclared keys are dropped" and "width/height are server-derived" are
     * not re-implemented here.
     *
     * **Alt is optional in a gallery**, and required on a standalone `image`.
     * That is the Phase 10 open question, decided: a client who must describe
     * thirty photos before the save goes through writes thirty junk strings,
     * and a screen reader reads those out instead of skipping them. The input
     * is still there, with its hint. `decorative: true` on the field still
     * forces alt="" for a gallery that carries no information at all.
     *
     * @param  array<string, mixed> $def
     * @param  array<string, mixed> $context
     * @return list<array{src: string, alt: string, width: int, height: int}>
     */
    private static function gallery(array $def, mixed $raw, array $context): array
    {
        if (!is_array($raw)) {
            return [];
        }

        // Bounded before the loop, exactly as items() is: a posted 50 000-row
        // gallery must cost the cut, not 50 000 sanitiser passes.
        $max = (is_int($def['max'] ?? null) && $def['max'] > 0) ? $def['max'] : self::GALLERY_MAX;
        $rows = array_slice(array_values($raw), 0, $max);

        $stored = is_array($context['stored'] ?? null) ? array_values($context['stored']) : [];
        $storedBySrc = [];
        foreach ($stored as $prior) {
            if (is_array($prior) && is_string($prior['src'] ?? null) && $prior['src'] !== '') {
                $storedBySrc[$prior['src']] = $prior;
            }
        }

        $out = [];
        foreach ($rows as $i => $row) {
            $rawSrc = is_array($row) && is_scalar($row['src'] ?? null) ? (string) $row['src'] : '';
            $image = self::image(
                // `decorative` is kept, the alt *requirement* is not: one is a
                // statement about what the pictures are, which only the
                // developer can make, and the other is the scalability
                // decision. `require: false` is the same switch the conflict
                // re-render uses, so there is no second way to say it.
                ['decorative' => ($def['decorative'] ?? false) === true],
                is_array($row) ? $row : [],
                [
                    // A gallery is explicitly reorderable. Match the prior
                    // server-derived dimensions by immutable src, not by the
                    // row position the editor just changed.
                    'stored'  => $storedBySrc[$rawSrc]
                        ?? (is_array($stored[$i] ?? null) ? $stored[$i] : []),
                    'require' => false,
                ] + $context
            );

            // A row whose src was rejected — outside media_bases, or simply
            // emptied — is not a photo. Dropping it here is what stops a
            // gallery from growing blank tiles every time it is saved.
            if ($image['src'] !== '') {
                $out[] = $image;
            }
        }

        return $out;
    }

    /**
     * A video embed: `{provider, id}` parsed out of a pasted URL, never HTML.
     *
     * The client pastes what is in their address bar. What is stored is two
     * short strings matched against a fixed pattern, and the template builds
     * the iframe — so there is no path by which client input becomes markup.
     * Anything that is not a YouTube or Vimeo URL stores an empty map, which
     * the template renders as nothing.
     *
     * @return array{provider: string, id: string}
     */
    private static function embed(string $raw): array
    {
        $raw = trim(strip_tags($raw));
        if ($raw === '') {
            return ['provider' => '', 'id' => ''];
        }

        // The id charset is the allowlist. Matching a *host* and then trusting
        // whatever followed it is how a path segment becomes an attribute.
        foreach (self::PROVIDERS as $provider => $patterns) {
            foreach ($patterns as $pattern) {
                if (preg_match($pattern, $raw, $m) === 1) {
                    return ['provider' => $provider, 'id' => $m[1]];
                }
            }
        }

        return ['provider' => '', 'id' => ''];
    }

    /**
     * A short muted background video: an MP4 we host, plus a poster image.
     *
     * `src` goes through the same media-base guard every image does — a
     * <video src> pointing at a third party is the same open-proxy problem with
     * a bigger file attached. The poster is a full image map, so it carries its
     * own server-derived dimensions and reserves the box before the video
     * arrives.
     *
     * @param  array<string, mixed> $def
     * @param  array<string, mixed> $context
     * @return array{src: string, poster: array<string, mixed>}
     */
    private static function loop(array $def, mixed $raw, array $context): array
    {
        $raw = is_array($raw) ? $raw : [];
        $stored = is_array($context['stored'] ?? null) ? $context['stored'] : [];

        return [
            'src' => self::mediaPath(
                is_scalar($raw['src'] ?? null) ? (string) $raw['src'] : '',
                (array) ($context['media_bases'] ?? [])
            ),
            // A poster is never decorative: it is what a visitor with autoplay
            // disabled actually sees, so it is the image, not a placeholder.
            'poster' => self::image(
                ['decorative' => ($def['decorative'] ?? false) === true],
                is_array($raw['poster'] ?? null) ? $raw['poster'] : [],
                ['stored' => is_array($stored['poster'] ?? null) ? $stored['poster'] : []] + $context
            ),
        ];
    }

    /**
     * A repeater over a fixed sub-schema. One level only.
     *
     * The bounds are applied *before* the item loop on purpose: a posted
     * 50 000-row list is cut to `max` before it costs 50 000 sanitiser passes,
     * each of which may run the HTML sanitiser. array_values() is part of the
     * same guard rather than tidiness — attacker-chosen keys would otherwise
     * be dumped as a YAML *map*, and the next load would hand a template
     * something that is no longer a list at all.
     *
     * @param  array<string, mixed> $def
     * @param  array<string, mixed> $context
     * @return list<array<string, mixed>>
     */
    private static function items(array $def, mixed $raw, array $context): array
    {
        if (!is_array($raw)) {
            return [];
        }

        $max = (is_int($def['max'] ?? null) && $def['max'] > 0) ? $def['max'] : self::LIST_MAX;
        $rows = array_slice(array_values($raw), 0, $max);

        $fields = (array) ($def['fields'] ?? []);
        $stored = is_array($context['stored'] ?? null) ? array_values($context['stored']) : [];

        // Rows have no identity beyond their position, so a stored row only
        // informs the row that lands in the same slot. Everything that depends
        // on it — an image's server-derived dimensions — re-checks the src
        // before trusting it, so a reordered list degrades to "unknown", never
        // to "wrong".
        $out = [];
        $refused = [];
        foreach ($rows as $i => $row) {
            try {
                $out[] = self::map(
                    $fields,
                    is_array($row) ? $row : [],
                    is_array($stored[$i] ?? null) ? $stored[$i] : [],
                    $context
                );
            } catch (ValidationException $e) {
                // The row index is part of the input's name, so it is part of
                // the path — otherwise every row's error points at the first.
                $refused[] = $e->under((string) $i);
                $out[] = is_array($stored[$i] ?? null) ? $stored[$i] : [];
            }
        }

        if ($refused !== []) {
            throw ValidationException::merge($refused);
        }

        return $out;
    }

    private static function plain(string $v, mixed $max, bool $multiline): string
    {
        $v = str_replace(["\r\n", "\r"], "\n", trim($v));
        $v = $multiline
            ? preg_replace('/\n{3,}/', "\n\n", strip_tags($v))
            : trim(preg_replace('/\s+/u', ' ', strip_tags($v)));

        $v ??= '';
        if (is_int($max) && $max > 0 && mb_strlen($v) > $max) {
            $v = rtrim(mb_substr($v, 0, $max));
        }

        return $v;
    }

    private static function boolean(mixed $raw): bool
    {
        if (is_bool($raw)) {
            return $raw;
        }

        return in_array(strtolower(trim((string) (is_scalar($raw) ? $raw : ''))), ['1', 'true', 'yes', 'on'], true);
    }

    /**
     * The allowlist, built once. Everything not named here is removed as
     * markup; its text survives, which is what `strip_tags` used to do and
     * what a paste from Word or Google Docs depends on — a Docs paste wraps
     * the whole selection in <b><span>, and dropping unknown elements with
     * their children would silently delete the client's text.
     */
    private static function sanitizer(): HtmlSanitizer
    {
        static $sanitizer = null;

        return $sanitizer ??= new HtmlSanitizer(
            array_reduce(
                self::ALLOWED_TAGS,
                static fn (HtmlSanitizerConfig $c, string $tag): HtmlSanitizerConfig => $c->allowElement($tag),
                (new HtmlSanitizerConfig())
                    ->defaultAction(HtmlSanitizerAction::Block)
                    ->allowElement('a', ['href'])
                    ->allowLinkSchemes(['http', 'https', 'mailto', 'tel'])
                    ->allowRelativeLinks()
                    // We already cut the value to RICHTEXT_LIMIT *characters*;
                    // this is the same ceiling in bytes, so it can only ever
                    // fire on input our own guard failed to cut. It matters
                    // that it never fires: on overflow the sanitiser returns an
                    // empty string, which would silently erase the field.
                    ->withMaxInputLength(self::RICHTEXT_LIMIT * 4)
            )
        );
    }

    private static function rich(string $v): string
    {
        if (mb_strlen($v) > self::RICHTEXT_LIMIT) {
            $v = mb_substr($v, 0, self::RICHTEXT_LIMIT);
        }

        // Both the sanitiser and the DOM parser require valid UTF-8. Truncation
        // above cuts on a character boundary, so this only rejects input that
        // arrived broken.
        if (preg_match('//u', $v) !== 1) {
            return '';
        }

        // <style> is classified as a <head> element by the sanitiser, so in body
        // context it is unwrapped and its CSS is emitted as visible text — and
        // no dropElement('style') can reach it. Remove the elements whose text
        // is code rather than prose before the sanitiser sees them.
        $v = self::withDom($v, static function (\Dom\HTMLDocument $doc): void {
            foreach (self::CODE_ELEMENTS as $tag) {
                foreach (iterator_to_array($doc->getElementsByTagName($tag)) as $el) {
                    $el->remove();
                }
            }
        });

        $v = self::sanitizer()->sanitize($v);

        $v = self::withDom($v, static function (\Dom\HTMLDocument $doc): void {
            // The sanitiser has already dropped hostile schemes. link() adds
            // the rules it does not have — protocol-relative and backslash
            // URLs, and upgrading a bare domain — so a client typing
            // "example.gr" gets the same result in richtext as in a link field.
            foreach ($doc->getElementsByTagName('a') as $a) {
                $href = self::link((string) $a->getAttribute('href'));
                if ($href === '') {
                    $a->removeAttribute('href');
                    continue;
                }

                // Setting an existing attribute keeps its position, so target
                // and rel are appended after href.
                $a->setAttribute('href', $href);
                if (self::isExternal($href)) {
                    $a->setAttribute('target', '_blank');
                    $a->setAttribute('rel', 'noopener noreferrer');
                }
            }

            // Drop empty paragraphs the editor leaves behind. Matching on text
            // rather than on markup also catches <p><strong></strong></p> and
            // the U+00A0 that &nbsp; decodes to.
            foreach (iterator_to_array($doc->getElementsByTagName('p')) as $p) {
                if (preg_match('/^[\s\x{00A0}]*$/u', $p->textContent) === 1) {
                    $p->remove();
                }
            }
        });

        return trim($v);
    }

    /**
     * Parse a fragment, let $mutate rewrite it, hand back the fragment.
     *
     * HTML5 parsing never fails, so there is no error path: malformed input
     * comes back as whatever the parser made of it, which is exactly the tree
     * a browser would have built.
     */
    private static function withDom(string $html, callable $mutate): string
    {
        if (trim($html) === '') {
            return '';
        }

        $doc = \Dom\HTMLDocument::createFromString(
            '<!DOCTYPE html><html><body>' . $html . '</body></html>',
            LIBXML_NOERROR
        );

        $mutate($doc);

        return $doc->getElementsByTagName('body')->item(0)?->innerHTML ?? '';
    }

    /**
     * A `link` field: a page picker, a custom address beside it, and where the
     * link opens. See LINK for why it is a map rather than a string.
     *
     * @param  array<string, mixed> $def
     * @param  array<string, mixed> $context
     * @return array{page: string, url: string, target: string}
     */
    private static function linkMap(array $def, mixed $raw, array $context): array
    {
        $stored = $context['stored'] ?? null;

        // This field used to store a bare page id, so that is what is sitting
        // in every content file written before now. Migrating on read means no
        // migration script and no flag day: the next save writes the map.
        // Only what is *stored* is coerced — a scalar arriving in a request is
        // dropped like any other wrong shape, so the strictness of the save
        // path is unchanged.
        $stored = match (true) {
            is_array($stored)                        => $stored,
            is_string($stored) && $stored !== ''     => ['page' => $stored],
            default                                  => [],
        };

        $out = self::map(self::LINK, is_array($raw) ? $raw : [], $stored, $context);

        // One destination, never two. A picked page is the deliberate choice —
        // it survives a slug rename, and a URL typed beside it does not.
        if ($out['page'] !== '') {
            $out['url'] = '';
        }

        // map() calls demand() on what this returns, and a map is never '', so
        // `required: true` on a link would otherwise pass silently. The
        // condition — either half counts — is the whole rule, and it is the
        // same shape as image()'s conditional alt.
        self::demand($def, $out['page'] !== '' || $out['url'] !== '' ? 'x' : '', $context, 'page');

        return $out;
    }

    /**
     * The `page` half of a link stores a **page id** — the filename.
     *
     * The id is deliberately the same in every locale while the slugs are not:
     * `contact.yml` exists in both `el/` and `en/` as `/epikoinonia` and
     * `/contact`. Resolving id → slug at render time is therefore what makes
     * renaming a slug safe, and what makes a link work across locales. An
     * earlier draft carried a `translation_key` inside each file instead — a
     * second identifier for the same thing, duplicated across N files, with no
     * enforcement and silent failure on a typo.
     *
     * Rejected rather than stripped: `javascript:alert(1)` reduced to its
     * letters would be a plausible-looking id, and storing junk that happens to
     * parse is how a dead link survives a review. Whether the id still resolves
     * is not asked here — a page deleted later must show up in the panel as a
     * link to fix, not vanish from the content file on the next save.
     */
    private static function pageId(string $v): string
    {
        $v = trim(strip_tags($v));

        return preg_match('/^[a-z0-9_-]+$/i', $v) === 1 ? $v : '';
    }

    /**
     * Accepted: absolute http(s) URLs, site-relative paths, fragments, mailto
     * and tel. Everything else becomes an empty string.
     *
     * No longer a field type — `link` is a page picker now. This is the href
     * rule inside richtext, where the client really is typing a URL, and it is
     * the only place a URL is still accepted from input.
     */
    private static function link(string $v): string
    {
        $v = trim(strip_tags($v));
        if ($v === '') {
            return '';
        }

        // Protocol-relative ("//evil.gr") and backslash variants ("/\evil.gr",
        // which browsers normalise to "//") look like internal paths but are
        // not. Reject before anything else.
        if (preg_match('#^[/\\\\]{2}#', $v) === 1 || preg_match('#^/[\\\\]#', $v) === 1) {
            return '';
        }

        if (preg_match('#^(/|\#|mailto:|tel:)#i', $v)) {
            return $v;
        }

        $scheme = strtolower((string) parse_url($v, PHP_URL_SCHEME));
        if (in_array($scheme, ['http', 'https'], true) && parse_url($v, PHP_URL_HOST) !== null) {
            return $v;
        }

        // Bare domain typed by a client: make it a real URL.
        if (preg_match('#^[a-z0-9.-]+\.[a-z]{2,}(/|$)#i', $v)) {
            return 'https://' . $v;
        }

        return ''; // javascript:, data:, anything else
    }

    /** A link is external when it has a host, not merely because it starts "http". */
    private static function isExternal(string $href): bool
    {
        return parse_url($href, PHP_URL_HOST) !== null;
    }

    /**
     * An image src may only point at media we host. Without this, an editor can
     * store `https://evil.tld/x.jpg`, which Cms::imageUrl() then wraps in
     * /cdn-cgi/image/... — turning the client's own zone into an open image
     * proxy serving attacker content and burning the transformation quota.
     *
     * Public because the derivative route applies the identical guard to an
     * anonymous GET. Two copies of this rule is one copy too many.
     *
     * @param list<string> $bases
     */
    public static function mediaPath(string $v, array $bases): string
    {
        $v = trim(strip_tags($v));
        if ($v === '') {
            return '';
        }

        if (str_contains($v, '..') || str_contains($v, "\0")) {
            return '';
        }

        foreach ($bases as $base) {
            $base = (string) $base;
            if ($base === '') {
                continue;
            }
            if (str_starts_with($v, '//') || str_starts_with($v, '\\')) {
                return '';
            }
            if (str_starts_with($v, $base)) {
                return $v;
            }
        }

        return '';
    }

    /** @param array<string, mixed> $def */
    private static function select(array $def, string $v): string
    {
        $options = array_keys(self::options($def));
        return in_array($v, $options, true) ? $v : (string) ($options[0] ?? '');
    }

    /**
     * @param  array<string, mixed> $def
     * @return array<string, string>
     */
    public static function options(array $def): array
    {
        $raw = $def['options'] ?? [];
        if (!is_array($raw)) {
            return [];
        }

        $out = [];
        foreach ($raw as $k => $label) {
            // Supports both `options: [a, b]` and `options: {a: Label A}`
            if (is_int($k)) {
                $out[(string) $label] = (string) $label;
            } else {
                $out[(string) $k] = (string) $label;
            }
        }

        return $out;
    }
}
