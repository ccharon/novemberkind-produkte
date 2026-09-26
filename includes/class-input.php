<?php

declare(strict_types=1);

namespace NovemberkindProdukte;

defined('ABSPATH') || exit;

/**
 * Liest und prüft Formularwerte für alle Bereiche. Werte aus `$_POST` sind mit Slashes versehen,
 * die Methoden für Text entfernen sie. Listen wie `email[]=…` ergeben den Standardwert statt einer PHP-Warnung.
 */
final class Input
{
    /**
     * Ein Formularwert als Text, unverändert.
     *
     * @param array<mixed> $data
     */
    public static function value(array $data, string $key, string $default = ''): string
    {
        $value = $data[$key] ?? $default;

        return is_scalar($value) ? (string) $value : $default;
    }

    /**
     * Einzeiliger Text ohne Tags und ohne Leerzeichen am Rand.
     *
     * @param array<mixed> $data
     */
    public static function text(array $data, string $key, string $default = ''): string
    {
        return trim(sanitize_text_field(wp_unslash(self::value($data, $key, $default))));
    }

    /**
     * Mehrzeiliger Text ohne Tags.
     *
     * @param array<mixed> $data
     */
    public static function textarea(array $data, string $key): string
    {
        return trim(sanitize_textarea_field(wp_unslash(self::value($data, $key))));
    }

    /**
     * HTML aus einem Editor, bereinigt wie ein Beitragsinhalt.
     *
     * @param array<mixed> $data
     */
    public static function html(array $data, string $key): string
    {
        return wp_kses_post(wp_unslash(self::value($data, $key)));
    }

    /**
     * @param array<mixed> $data
     */
    public static function id(array $data, string $key): int
    {
        return absint(self::value($data, $key));
    }

    /**
     * Liste von IDs ohne Dubletten und ohne 0, in der Reihenfolge des Formulars.
     *
     * @param array<mixed> $data
     * @return int[]
     */
    public static function ids(array $data, string $key): array
    {
        $values = $data[$key] ?? [];
        $ids    = array_map('absint', array_filter(is_array($values) ? $values : [$values], 'is_scalar'));

        return array_values(array_unique(array_filter($ids)));
    }

    /**
     * Einer der erlaubten Werte, sonst der Standardwert.
     *
     * @param array<mixed> $data
     * @param string[]     $allowed
     */
    public static function choice(array $data, string $key, array $allowed, string $default): string
    {
        $value = self::value($data, $key);

        return in_array($value, $allowed, true) ? $value : $default;
    }

    /**
     * Ganze Zahl von 1 bis `$max`, sonst null.
     *
     * @param array<mixed> $data
     */
    public static function percent(array $data, string $key, int $max): ?int
    {
        $raw = trim(self::value($data, $key));
        $percent = ctype_digit($raw) ? (int) $raw : 0;

        return $percent >= 1 && $percent <= $max ? $percent : null;
    }

    /**
     * Zeitpunkt aus den Feldern `<prefix>_date` und `<prefix>_time` in der Zeitzone des Shops.
     * Fehlt die Uhrzeit, gilt `$default_time`. Fehler meldet das Formular unter `<prefix>`.
     *
     * @param array<mixed> $data
     */
    public static function datetime(array $data, string $prefix, string $default_time = '00:00'): ?int
    {
        $time  = self::text($data, $prefix . '_time');
        $value = self::text($data, $prefix . '_date') . 'T' . ($time !== '' ? $time : $default_time);
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $value, wp_timezone());

        // Der Vergleich verwirft Werte, die PHP stillschweigend umrechnet, z. B. den 31.02.
        return $parsed !== false && $parsed->format('Y-m-d\TH:i') === $value ? $parsed->getTimestamp() : null;
    }

    /**
     * Wandelt einen Preis wie „24,90“, „1.234,50“ oder „24.90“ in „24.90“ um.
     */
    public static function price(string $input): ?string
    {
        $value = preg_replace('/[\s€]/u', '', $input) ?? '';
        if (str_contains($value, ',')) {
            $value = str_replace(['.', ','], ['', '.'], $value);
        }
        if (!preg_match('/^\d+(\.\d{1,2})?$/', $value)) {
            return null;
        }

        return number_format((float) $value, 2, '.', '');
    }

    /**
     * Positive Zahl wie „7,5“ oder „7.5“ → 7.5
     */
    public static function number(string $input): ?float
    {
        $value = str_replace(',', '.', trim($input));
        if (!preg_match('/^\d+(\.\d+)?$/', $value) || (float) $value <= 0) {
            return null;
        }

        return (float) $value;
    }

    /**
     * „Otter, Tier ,otter“ → ['Otter', 'Tier']
     *
     * @return string[]
     */
    public static function tags(string $input): array
    {
        $tags = [];
        foreach (explode(',', wp_unslash($input)) as $tag) {
            $tag = trim(sanitize_text_field($tag));
            if ($tag !== '' && !isset($tags[mb_strtolower($tag)])) {
                $tags[mb_strtolower($tag)] = $tag;
            }
        }

        return array_values($tags);
    }

    /**
     * Fehler mit Meldungen je Feld, die das Formular am Feld anzeigt.
     *
     * @param array<string, string> $errors
     */
    public static function invalid(array $errors): \WP_Error
    {
        return new \WP_Error('invalid', __('Bitte prüfe die markierten Felder.', 'novemberkind-produkte'), $errors);
    }
}
