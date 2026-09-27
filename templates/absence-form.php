<?php

/**
 * Formular einer Abwesenheit.
 *
 * @var array{id: int, text: string, start: int, end: int, announce: int}|null $absence
 */

declare(strict_types=1);

namespace NovemberkindProdukte;

defined('ABSPATH') || exit;

$is_new         = $absence === null;
$absence_status = $is_new ? 'new' : Absences::status($absence);
$is_ended       = $absence_status === 'ended';
$is_running     = $absence_status === 'running';
$start_choice   = $absence_status === 'planned' ? 'later' : 'now';
$end_choice     = ($absence['end'] ?? 0) !== 0 ? 'until' : 'open';

$form_back_url   = App::absences_url();
$form_back_label = __('← Alle Abwesenheiten', 'novemberkind-produkte');
$form_eyebrow    = __('Abwesenheit', 'novemberkind-produkte');
$form_title      = $is_new ? __('Neue Abwesenheit', 'novemberkind-produkte') : Absences::period_label($absence);
$form_badge      = $is_new ? null : Absences::badge($absence_status);
include __DIR__ . '/form-header.php';
?>

<form class="nkp-simple-form" data-nkp-simple-form data-nkp-save="novemberkind_produkte_save_absence" novalidate>
    <input type="hidden" name="id" value="<?php echo esc_attr((string) ($absence['id'] ?? 0)); ?>">

    <?php if ($is_ended) : ?>
        <p class="nkp-note"><?php esc_html_e('Diese Abwesenheit ist beendet und bleibt zur Ansicht erhalten.', 'novemberkind-produkte'); ?></p>
    <?php endif; ?>

    <fieldset class="nkp-simple-form__fields" <?php disabled($is_ended); ?>>
        <section class="nkp-panel">
            <h2 class="nkp-panel__title"><?php esc_html_e('Beginn', 'novemberkind-produkte'); ?></h2>
            <?php if ($is_running) : ?>
                <p>
                    <?php
                    /* translators: 1: Datum, 2: Uhrzeit */
                    echo esc_html(sprintf(__('Läuft seit %1$s, %2$s Uhr.', 'novemberkind-produkte'), wp_date('d.m.Y', $absence['start']), wp_date('H:i', $absence['start'])));
                    ?>
                </p>
            <?php else : ?>
                <div class="nkp-choices">
                    <label class="nkp-choice">
                        <input type="radio" name="start" value="now" <?php checked($start_choice, 'now'); ?>>
                        <span><strong><?php esc_html_e('Sofort', 'novemberkind-produkte'); ?></strong>
                        <?php esc_html_e('Der Hinweis erscheint nach dem Speichern', 'novemberkind-produkte'); ?></span>
                    </label>
                    <label class="nkp-choice">
                        <input type="radio" name="start" value="later" <?php checked($start_choice, 'later'); ?>>
                        <span><strong><?php esc_html_e('Geplant', 'novemberkind-produkte'); ?></strong>
                        <?php esc_html_e('Beginnt zum gewählten Zeitpunkt', 'novemberkind-produkte'); ?></span>
                    </label>
                </div>
                <div class="nkp-panel__group" data-nkp-show-for="start:later" <?php echo $start_choice === 'later' ? '' : 'hidden'; ?>>
                    <?php
                    Html::moment('start_at', __('Beginnt am', 'novemberkind-produkte'), $start_choice === 'later' ? $absence['start'] : null, '00:00', [
                        'label' => __('Beginn', 'novemberkind-produkte'),
                    ]);
                    Html::moment('announce', __('Im Shop ankündigen ab (optional)', 'novemberkind-produkte'), ($absence['announce'] ?? 0) ?: null, '00:00', [
                        'label' => __('Ankündigung', 'novemberkind-produkte'),
                    ]);
                    ?>
                    <p class="nkp-field__hint"><?php esc_html_e('Mit Ankündigung sieht die Kundschaft schon vorher, wann nicht verschickt wird, und können rechtzeitig bestellen.', 'novemberkind-produkte'); ?></p>
                </div>
            <?php endif; ?>
        </section>

        <section class="nkp-panel">
            <h2 class="nkp-panel__title"><?php esc_html_e('Ende', 'novemberkind-produkte'); ?></h2>
            <div class="nkp-choices">
                <label class="nkp-choice">
                    <input type="radio" name="end" value="open" <?php checked($end_choice, 'open'); ?>>
                    <span><strong><?php esc_html_e('Offen', 'novemberkind-produkte'); ?></strong>
                    <?php esc_html_e('Endet erst mit „Jetzt beenden“', 'novemberkind-produkte'); ?></span>
                </label>
                <label class="nkp-choice">
                    <input type="radio" name="end" value="until" <?php checked($end_choice, 'until'); ?>>
                    <span><strong><?php esc_html_e('Bis', 'novemberkind-produkte'); ?></strong>
                    <?php esc_html_e('Endet zum gewählten Zeitpunkt', 'novemberkind-produkte'); ?></span>
                </label>
            </div>
            <div class="nkp-panel__group" data-nkp-show-for="end:until" <?php echo $end_choice === 'until' ? '' : 'hidden'; ?>>
                <?php
                Html::moment('end_at', __('Wieder da am', 'novemberkind-produkte'), ($absence['end'] ?? 0) ?: null, '00:00', [
                    'label' => __('Ende', 'novemberkind-produkte'),
                ]);
                ?>
                <p class="nkp-field__hint"><?php esc_html_e('Ab diesem Tag werden Bestellungen wieder verschickt. Das Datum steht im Hinweis und bei der Lieferzeit.', 'novemberkind-produkte'); ?></p>
            </div>
        </section>

        <section class="nkp-panel">
            <label class="nkp-field">
                <span class="nkp-field__label"><?php esc_html_e('Eigener Text (optional)', 'novemberkind-produkte'); ?></span>
                <textarea name="text" rows="3" maxlength="<?php echo esc_attr((string) Absences::TEXT_MAX_LENGTH); ?>"
                          placeholder="<?php esc_attr_e('z. B. Ich bin im Urlaub und male neue Motive.', 'novemberkind-produkte'); ?>"><?php echo esc_textarea($absence['text'] ?? ''); ?></textarea>
                <span class="nkp-field__hint"><?php esc_html_e('Steht im Shop über dem Satz, ab wann verschickt wird.', 'novemberkind-produkte'); ?></span>
                <?php Html::field_error('text'); ?>
            </label>
        </section>
    </fieldset>

    <?php if (!$is_ended) : ?>
        <div class="nkp-simple-form__actions">
            <?php if (!$is_new) : ?>
                <button type="button" class="nkp-button nkp-button--secondary" data-nkp-action="novemberkind_produkte_end_absence"
                        data-nkp-confirm="<?php esc_attr_e('Der Hinweis verschwindet sofort aus dem Shop, die Lieferzeiten sind wieder normal. Beenden?', 'novemberkind-produkte'); ?>">
                    <?php echo $absence_status === 'planned' ? esc_html__('Nicht starten', 'novemberkind-produkte') : esc_html__('Jetzt beenden', 'novemberkind-produkte'); ?>
                </button>
            <?php endif; ?>
            <button type="submit" class="nkp-button nkp-button--primary" data-nkp-submit><?php esc_html_e('Speichern', 'novemberkind-produkte'); ?></button>
        </div>
    <?php else : ?>
        <div class="nkp-simple-form__actions">
            <?php Html::delete_button('novemberkind_produkte_delete_absence', __('Die Abwesenheit wird endgültig gelöscht. Löschen?', 'novemberkind-produkte')); ?>
        </div>
    <?php endif; ?>
</form>
