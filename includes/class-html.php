<?php

declare(strict_types=1);

namespace NovemberkindProdukte;

defined('ABSPATH') || exit;

/**
 * Bausteine, die mehrere Templates gleich ausgeben.
 */
final class Html
{
    /**
     * Platz für die Fehlermeldung eines Feldes; das Skript füllt ihn mit der Meldung des Servers.
     */
    public static function field_error(string $field): void
    {
        printf('<span class="nkp-field__error" data-error-for="%s" hidden></span>', esc_attr($field));
    }

    /**
     * Zeitpunkt als Datum und Uhrzeit in zwei Feldern `<name>_date` und `<name>_time`, wie Input::datetime() sie liest.
     *
     * @param array{required?: bool, label?: string, class?: string, attributes?: array<string, string|bool>, error?: bool} $options
     *        label: Anfang der Bezeichnung für Screenreader, sonst `$legend`; attributes: weitere Attribute des fieldset,
     *        true für Attribute ohne Wert; error: Fehlerplatz im fieldset, sonst gibt ihn das Template selbst aus
     */
    public static function moment(string $name, string $legend, ?int $timestamp, string $default_time, array $options = []): void
    {
        $label      = $options['label'] ?? $legend;
        $attributes = '';
        foreach ($options['attributes'] ?? [] as $attribute => $value) {
            if ($value === true) {
                $attributes .= ' ' . esc_attr($attribute);
            } elseif ($value !== false) {
                $attributes .= sprintf(' %s="%s"', esc_attr($attribute), esc_attr($value));
            }
        }
        ?>
        <fieldset class="nkp-field nkp-moment<?php echo isset($options['class']) ? ' ' . esc_attr($options['class']) : ''; ?>"<?php echo $attributes; // phpcs:ignore WordPress.Security.EscapeOutput -- oben einzeln escapt ?>>
            <legend class="nkp-field__label"><?php echo esc_html($legend); ?></legend>
            <div class="nkp-moment__inputs">
                <input type="date" name="<?php echo esc_attr($name); ?>_date" <?php echo !empty($options['required']) ? 'required' : ''; ?>
                       value="<?php echo esc_attr($timestamp ? wp_date('Y-m-d', $timestamp) : ''); ?>"
                       aria-label="<?php echo esc_attr(sprintf(/* translators: %s: z. B. Beginn */ __('%s, Datum', 'novemberkind-produkte'), $label)); ?>">
                <input type="time" name="<?php echo esc_attr($name); ?>_time" step="60"
                       value="<?php echo esc_attr($timestamp ? wp_date('H:i', $timestamp) : $default_time); ?>"
                       aria-label="<?php echo esc_attr(sprintf(/* translators: %s: z. B. Beginn */ __('%s, Uhrzeit', 'novemberkind-produkte'), $label)); ?>">
            </div>
            <?php if ($options['error'] ?? true) : ?>
                <?php self::field_error($name); ?>
            <?php endif; ?>
        </fieldset>
        <?php
    }

    /**
     * Plakette mit Farbe und Text, z. B. für den Status einer Aktion.
     *
     * @param array{badge: string, label: string} $state
     */
    public static function badge(array $state): void
    {
        printf('<span class="nkp-badge nkp-badge--%s">%s</span>', esc_attr($state['badge']), esc_html($state['label']));
    }
}
