<?php

declare(strict_types=1);

namespace Dopamine\FlatCms;

/**
 * Interface strings, per language.
 *
 *   lang/en.php   <- the source language
 *   lang/el.php   <- a translation
 *
 * Layered like the theme roots, site first: a site drops `lang/el.php` in its
 * own root containing only the keys it wants to add or reword, and everything
 * else resolves from the engine's. That is what makes a translatable string for
 * a component the site wrote possible at all without editing vendor/.
 *
 * **English is the source and the default**, and `el` is a translation of it.
 * The panel was Greek-only and hardcoded until now, which is a fork waiting to
 * happen the moment a site is not Greek — "a distributable package whose
 * default language is Greek" is not a sentence anyone wants in a README.
 *
 * A flat PHP array and forty lines, not `symfony/translation` and not gettext:
 * this is ~90 strings with no plural rules to speak of, and a catalogue system
 * is the wrong shape for a flat map. The day a plural rule is genuinely needed
 * is the day to reconsider — not before.
 *
 * A key with no translation falls back to English; English with no entry falls
 * back to **the key itself**. A missing string must render as something
 * readable, because the failure mode of a translation file is "we shipped and
 * one screen is blank".
 *
 * **Two instances, deliberately.** The panel speaks `ADMIN_LOCALE`, which is
 * the language the person editing the site reads. The handful of engine strings
 * that reach a *visitor* — the contact form's validation messages — speak the
 * language of the page they appear on. One global would make one of those two
 * wrong on every bilingual site.
 */
final class Lang
{
    public const SOURCE = 'en';

    /** @var array<string, array<string, string>> catalogues, shared across instances */
    private static array $loaded = [];

    private readonly string $locale;

    /** @var list<string> catalogue roots, site layer first */
    private readonly array $dirs;

    /**
     * @param string|list<string> $dirs site layer first, engine last —
     *                                  the same ordering as the theme roots
     */
    public function __construct(string|array $dirs, string $locale)
    {
        $this->dirs = array_values(array_filter((array) $dirs, 'is_string'));

        // Anything unrecognised is the source language rather than an error: a
        // typo in ADMIN_LOCALE must show English, not take the panel down.
        $this->locale = preg_match('/^[a-z]{2}(-[a-z]{2})?$/i', $locale) === 1 ? $locale : self::SOURCE;
    }

    public function locale(): string
    {
        return $this->locale;
    }

    /**
     * One string, in this instance's language.
     *
     * `%s`-style placeholders go straight to sprintf, so a translator can move
     * them around the sentence — which is the whole reason a message is one
     * template rather than three concatenated fragments.
     */
    public function t(string $key, string|int ...$args): string
    {
        $text = $this->catalogue($this->locale)[$key]
            ?? $this->catalogue(self::SOURCE)[$key]
            ?? $key;

        return $args === [] ? $text : sprintf($text, ...$args);
    }

    /**
     * One language, with every layer merged into it.
     *
     * **Merged key by key, not first-file-wins.** A site that wants one word
     * changed — or one string of its own for a component it wrote — writes a
     * file with one entry in it, and everything else still resolves from the
     * engine. First-wins would mean copying ~90 strings and then maintaining
     * them, which nobody does, and the copy silently misses every key a later
     * engine release adds.
     *
     * `array_merge` keeps the **last** value, so the layers are walked back to
     * front: engine first, site last. That is the reverse of how theme roots
     * resolve, where the loader stops at the first file it finds — same "the
     * site wins" outcome, opposite direction, because one is a merge and the
     * other is a lookup.
     *
     * The cache is keyed by the roots as well as the language: two instances
     * with different roots are two different catalogues, and a test that builds
     * one must not be served another's.
     *
     * @return array<string, string>
     */
    private function catalogue(string $locale): array
    {
        $key = $locale . "\0" . implode("\0", $this->dirs);

        if (isset(self::$loaded[$key])) {
            return self::$loaded[$key];
        }

        $data = [];
        foreach (array_reverse($this->dirs) as $dir) {
            $file = $dir . '/' . $locale . '.php';
            if (!is_file($file)) {
                continue;
            }

            // `require`, so this path is executed. It comes from config and is
            // developer-owned like every other path there — it must never point
            // anywhere a request can write.
            $loaded = require $file;
            if (is_array($loaded)) {
                $data = array_merge($data, $loaded);
            }
        }

        return self::$loaded[$key] = $data;
    }

    /**
     * Every key the source language declares.
     *
     * Not for the panel: bin/doctor uses it to report a translation that has
     * fallen behind, which is the only way anyone finds out before a client does.
     *
     * @return list<string>
     */
    public function keys(): array
    {
        return array_keys($this->catalogue(self::SOURCE));
    }

    /** @return list<string> keys this language does not translate */
    public function missing(): array
    {
        return array_values(array_diff($this->keys(), array_keys($this->catalogue($this->locale))));
    }
}
