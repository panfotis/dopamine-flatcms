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

    public function __construct(private readonly string $dir)
    {
    }

    /** @return array<string, array<string, mixed>> */
    public function all(): array
    {
        if ($this->cache !== null) {
            return $this->cache;
        }

        $out = [];
        foreach (glob($this->dir . '/*', GLOB_ONLYDIR) ?: [] as $path) {
            $type = basename($path);
            $schemaFile = $path . '/schema.yml';
            if (!is_file($schemaFile)) {
                continue;
            }

            $schema = Yaml::parseFile($schemaFile) ?? [];
            $schema['type']     = $type;
            $schema['label']  ??= ucfirst(str_replace('_', ' ', $type));
            $schema['fields'] ??= [];
            $schema['template'] = $type . '/' . $type . '.twig';

            // Normalise every field so the rest of the code can assume shape.
            foreach ($schema['fields'] as $name => $def) {
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

                $schema['fields'][$name] = $def;
            }

            $out[$type] = $schema;
        }

        return $this->cache = $out;
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
