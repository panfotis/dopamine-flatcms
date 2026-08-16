<?php

declare(strict_types=1);

namespace Dopamine\FlatCms;

/**
 * Interface strings, per language.
 *
 *   lang/en.php   <- the source language
 *   lang/el.php   <- a translation
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

    public function __construct(private readonly string $dir, string $locale)
    {
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

    /** @return array<string, string> */
    private function catalogue(string $locale): array
    {
        if (isset(self::$loaded[$locale])) {
            return self::$loaded[$locale];
        }

        $file = $this->dir . '/' . $locale . '.php';
        $data = is_file($file) ? require $file : [];

        return self::$loaded[$locale] = is_array($data) ? $data : [];
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
