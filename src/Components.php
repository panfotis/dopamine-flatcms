<?php

declare(strict_types=1);

namespace Dopamine\FlatCms;

use Symfony\Component\Yaml\Yaml;

/**
 * Reads the component definitions off disk.
 *
 * A component is a folder under components/:
 *
 *   components/hero/
 *     schema.yml   <- which fields the editor sees
 *     hero.twig    <- how it renders
 *
 * Adding a component = adding a folder. No registration, no database.
 */
final class Components
{
    /** Everything `editable:` may say. Anything else is a typo, not a value. */
    public const EDITABLE = [true, 'admin', false];

    /** @var array<string, array<string, mixed>>|null */
    private ?array $cache = null;

    /**
     * May a $role edit a field declared `editable: $editable`?
     *
     *   editable   admin              editor
     *   true       edits              edits
     *   'admin'    edits              sees, locked
     *   false      sees, locked       sees, locked
     *
     * The one place this table exists. The save path and the edit template both
     * ask here, so the form can never offer an input the server would refuse —
     * and, far more importantly, locking an input in the template can never be
     * mistaken for enforcing it.
     */
    public static function mayEdit(mixed $editable, string $role): bool
    {
        return $editable === true || ($editable === 'admin' && $role === 'admin');
    }

    /** @var list<string> */
    private readonly array $dirs;

    /** @param string|list<string> $dirs first directory wins */
    public function __construct(string|array $dirs)
    {
        $this->dirs = array_values(array_unique(array_filter((array) $dirs, 'is_string')));
    }

    /** @return array<string, array<string, mixed>> */
    public function all(): array
    {
        if ($this->cache !== null) {
            return $this->cache;
        }

        $out = [];
        foreach ($this->dirs as $i => $dir) {
            foreach (glob($dir . '/*', GLOB_ONLYDIR) ?: [] as $path) {
                $type = basename($path);
                // Site roots are first. Once a type exists, its schema and
                // template are both the site's; package defaults cannot merge
                // half a component underneath it.
                if (isset($out[$type])) {
                    continue;
                }
                $schemaFile = $path . '/schema.yml';
                if (!is_file($schemaFile)) {
                    continue;
                }

                $schema = Yaml::parseFile($schemaFile) ?? [];
                $schema['type']     = $type;
                $schema['label']  ??= ucfirst(str_replace('_', ' ', $type));
                $schema['fields'] ??= [];
                // Bound to this layer's Twig namespace, not the layered lookup:
                // the folder that won the schema is the folder whose template,
                // CSS and JS render. Half a component from another layer is
                // exactly what first-wins exists to forbid.
                $schema['template'] = '@theme' . $i . '/components/' . $type . '/' . $type . '.twig';
                $schema['dir']      = $path;
                // External CDN deps only; the component's own .css/.js need no
                // declaration — the file beside the template is the declaration.
                $schema['assets'] = [
                    'css' => array_values((array) ($schema['assets']['css'] ?? [])),
                    'js'  => array_values((array) ($schema['assets']['js'] ?? [])),
                ];

                $schema['fields'] = self::normalise($schema['fields']);

                $out[$type] = $schema;
            }
        }

        return $this->cache = $out;
    }

    /**
     * The page-level `seo` map, normalised exactly like a component's fields.
     *
     * Through the same normaliser rather than declared already-normalised, so
     * og_image picks up Fields::IMAGE and its `decorative` flag from the one
     * place that decides them. A second copy is precisely how `text_image`
     * ended up with an alt field beside its image instead of inside it.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function seoFields(): array
    {
        static $fields = null;

        return $fields ??= self::normalise(Fields::SEO);
    }

    /**
     * Normalise every field so the rest of the code can assume shape.
     *
     * Runs on a list's sub-schema as well as on a component's own fields, so a
     * row's field is as normalised — and as fail-closed about `editable` — as
     * a top-level one. A list may not contain a list: the plan is explicit that
     * repeaters are one level only, and a nested one is a schema mistake rather
     * than a feature to support.
     *
     * @param  array<string, mixed> $fields
     * @return array<string, array<string, mixed>>
     */
    private static function normalise(array $fields): array
    {
        $out = [];

        foreach ($fields as $name => $def) {
            $def = is_array($def) ? $def : ['type' => (string) $def];
            $def['type']     = $def['type']     ?? 'text';
            $def['label']    = $def['label']    ?? $name;
            $def['editable'] = $def['editable'] ?? true;
            $def['required'] = $def['required'] ?? false;

            // Fail closed on anything unrecognised. `editable: yes` or a
            // misspelled `admni` must lock the field, not hand it to
            // everyone — a schema typo is not a reason to widen access.
            if (!in_array($def['editable'], self::EDITABLE, true)) {
                $def['editable'] = false;
            }

            if ($def['type'] === 'select') {
                // Accept both `options: [a, b]` and `options: {a: Label}`
                // and always hand templates a value => label map.
                $def['options'] = Fields::options($def);
                $def['default'] = $def['default'] ?? array_key_first($def['options']);
            }

            // Everything that holds an image holds the *same* image: one
            // src/alt pair, one place that decides whether it is decorative.
            // A gallery is a list of them; a background video's poster is one
            // of them. Declaring the sub-schema per type is exactly how
            // `text_image` once ended up with an alt beside its image.
            if (in_array($def['type'], ['image', 'image_list'], true)) {
                // Whether an image carries information or is decoration is a
                // design decision, so it is declared here and nowhere else. A
                // `decorative` arriving in a request is just another undeclared
                // key and is dropped like any other.
                $def['decorative'] = ($def['decorative'] ?? false) === true;
                // The panel builds its inputs from the same sub-schema the
                // save path validates against, so the two cannot drift.
                $def['fields'] = Fields::IMAGE;
            }

            // Same reason as IMAGE: the panel builds the picker, the URL box
            // and the target select from the sub-schema the save path
            // validates against, so a component gets the whole control from
            // `type: link` and the two cannot drift.
            if ($def['type'] === 'link') {
                $def['fields'] = Fields::LINK;
            }

            if ($def['type'] === 'video_loop') {
                $def['decorative'] = ($def['decorative'] ?? false) === true;
                $def['fields'] = ['poster' => ['type' => 'image', 'fields' => Fields::IMAGE]];
            }

            if ($def['type'] === 'list') {
                $def['fields'] = self::normalise(
                    array_filter(
                        (array) ($def['fields'] ?? []),
                        static fn (mixed $f): bool => (is_array($f) ? ($f['type'] ?? '') : $f) !== 'list'
                    )
                );
                $def['item_label'] = (string) ($def['item_label'] ?? array_key_first($def['fields']) ?? '');
            }

            $out[$name] = $def;
        }

        return $out;
    }

    public function has(string $type): bool
    {
        return isset($this->all()[$type]);
    }

    /** @return array<string, mixed>|null */
    public function get(string $type): ?array
    {
        return $this->all()[$type] ?? null;
    }

    /**
     * The fields any role is allowed to touch, in schema order — `editable:
     * true` only. `editable: admin` is not in here on purpose: this answers
     * "what is open to everyone", and mayEdit() answers the per-role question.
     *
     * @return array<string, array<string, mixed>>
     */
    public function editableFields(string $type): array
    {
        $schema = $this->get($type);
        if ($schema === null) {
            return [];
        }

        return array_filter(
            $schema['fields'],
            static fn (array $f): bool => $f['editable'] === true && $f['type'] !== 'hidden'
        );
    }
}
