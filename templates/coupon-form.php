<?php

/**
 * Formular eines Gutscheins.
 *
 * @var array{id: int, code: string, kind: string, percent: int, expires: string, once: bool, active: bool, expired: bool, usage: int, own: bool, edit_url: string}|null $coupon
 */

declare(strict_types=1);

namespace NovemberkindProdukte;

defined('ABSPATH') || exit;

$is_new = $coupon === null;
$kind   = $coupon['kind'] ?? 'percent';

$field_error = static function (string $field): void {
    printf('<span class="nkp-field__error" data-error-for="%s" hidden></span>', esc_attr($field));
};
?>
<a class="nkp-back" href="<?php echo esc_url(App::coupons_url()); ?>"><?php esc_html_e('← Alle Gutscheine', 'novemberkind-produkte'); ?></a>

<header class="nkp-header">
    <div>
        <p class="nkp-eyebrow"><?php esc_html_e('Gutschein', 'novemberkind-produkte'); ?></p>
        <h1><?php echo $is_new ? esc_html__('Neuer Gutschein', 'novemberkind-produkte') : esc_html($coupon['code']); ?></h1>
    </div>
    <?php if (!$is_new) : ?>
        <?php if (!$coupon['active']) : ?>
            <span class="nkp-badge nkp-badge--draft"><?php esc_html_e('Deaktiviert', 'novemberkind-produkte'); ?></span>
        <?php elseif ($coupon['expired']) : ?>
            <span class="nkp-badge nkp-badge--draft"><?php esc_html_e('Abgelaufen', 'novemberkind-produkte'); ?></span>
        <?php else : ?>
            <span class="nkp-badge nkp-badge--online"><?php esc_html_e('Aktiv', 'novemberkind-produkte'); ?></span>
        <?php endif; ?>
    <?php endif; ?>
</header>

<form class="nkp-campaign-form" data-nkp-simple-form data-nkp-save="novemberkind_produkte_save_coupon" novalidate>
    <input type="hidden" name="id" value="<?php echo esc_attr((string) ($coupon['id'] ?? 0)); ?>">

    <?php if (!$is_new && $coupon['usage'] > 0) : ?>
        <p class="nkp-note">
            <?php
            /* translators: %d: Anzahl der Einlösungen */
            echo esc_html(sprintf(_n('Bisher %d-mal eingelöst.', 'Bisher %d-mal eingelöst.', $coupon['usage'], 'novemberkind-produkte'), $coupon['usage']));
            ?>
        </p>
    <?php endif; ?>

    <section class="nkp-panel">
        <div class="nkp-field">
            <label class="nkp-field__label" for="nkp-coupon-code"><?php esc_html_e('Code', 'novemberkind-produkte'); ?></label>
            <div class="nkp-code-input">
                <input type="text" id="nkp-coupon-code" name="code" required autocomplete="off" maxlength="<?php echo esc_attr((string) Coupons::CODE_MAX_LENGTH); ?>" autocapitalize="characters" spellcheck="false"
                       value="<?php echo esc_attr($coupon['code'] ?? ''); ?>" placeholder="HERBST10">
                <button type="button" class="nkp-button nkp-button--secondary" data-nkp-generate-code><?php esc_html_e('Zufällig', 'novemberkind-produkte'); ?></button>
            </div>
            <span class="nkp-field__hint"><?php esc_html_e('Den gibt die Kundschaft im Warenkorb ein. Groß- und Kleinschreibung spielt keine Rolle.', 'novemberkind-produkte'); ?></span>
            <?php $field_error('code'); ?>
        </div>
    </section>

    <section class="nkp-panel">
        <h2 class="nkp-panel__title"><?php esc_html_e('Was bekommt man?', 'novemberkind-produkte'); ?></h2>
        <div class="nkp-choices">
            <label class="nkp-choice">
                <input type="radio" name="kind" value="percent" <?php checked($kind, 'percent'); ?>>
                <span><strong><?php esc_html_e('Rabatt in Prozent', 'novemberkind-produkte'); ?></strong>
                <?php esc_html_e('Auf alle Produkte im Warenkorb, die nicht schon reduziert sind', 'novemberkind-produkte'); ?></span>
            </label>
            <label class="nkp-choice">
                <input type="radio" name="kind" value="shipping" <?php checked($kind, 'shipping'); ?>>
                <span><strong><?php esc_html_e('Kostenloser Versand', 'novemberkind-produkte'); ?></strong>
                <?php esc_html_e('Brief und Päckchen kosten nichts', 'novemberkind-produkte'); ?></span>
            </label>
        </div>
        <label class="nkp-field nkp-field--narrow" data-nkp-show-for="kind:percent" <?php echo $kind === 'percent' ? '' : 'hidden'; ?>>
            <span class="nkp-field__label"><?php esc_html_e('Rabatt in Prozent', 'novemberkind-produkte'); ?></span>
            <input type="number" name="percent" min="1" max="<?php echo esc_attr((string) Coupons::MAX_PERCENT); ?>" step="1" inputmode="numeric" value="<?php echo esc_attr($kind === 'percent' && $coupon ? (string) $coupon['percent'] : ''); ?>" placeholder="10">
            <?php $field_error('percent'); ?>
        </label>
    </section>

    <section class="nkp-panel">
        <h2 class="nkp-panel__title"><?php esc_html_e('Grenzen', 'novemberkind-produkte'); ?></h2>
        <label class="nkp-field nkp-field--narrow">
            <span class="nkp-field__label"><?php esc_html_e('Gültig bis einschließlich', 'novemberkind-produkte'); ?></span>
            <input type="date" name="expires" value="<?php echo esc_attr($coupon['expires'] ?? ''); ?>">
            <span class="nkp-field__hint"><?php esc_html_e('Leer lassen, wenn der Gutschein unbegrenzt gilt.', 'novemberkind-produkte'); ?></span>
            <?php $field_error('expires'); ?>
        </label>
        <label class="nkp-check">
            <input type="checkbox" name="once" value="1" <?php checked($coupon['once'] ?? false); ?>>
            <?php esc_html_e('Nur einmal pro Kunde', 'novemberkind-produkte'); ?>
        </label>
    </section>

    <div class="nkp-campaign-form__actions">
        <?php if (!$is_new) : ?>
            <?php if ($coupon['active']) : ?>
                <button type="button" class="nkp-button nkp-button--secondary" data-nkp-action="novemberkind_produkte_toggle_coupon" data-nkp-value="off"
                        data-nkp-confirm="<?php esc_attr_e('Der Code ist danach im Shop nicht mehr einlösbar. Deaktivieren?', 'novemberkind-produkte'); ?>">
                    <?php esc_html_e('Deaktivieren', 'novemberkind-produkte'); ?>
                </button>
            <?php else : ?>
                <button type="button" class="nkp-button nkp-button--secondary" data-nkp-action="novemberkind_produkte_toggle_coupon" data-nkp-value="on">
                    <?php esc_html_e('Wieder aktivieren', 'novemberkind-produkte'); ?>
                </button>
            <?php endif; ?>
        <?php endif; ?>
        <button type="submit" class="nkp-button nkp-button--primary" data-nkp-submit><?php esc_html_e('Speichern', 'novemberkind-produkte'); ?></button>
    </div>
</form>

<div class="nkp-toast" data-nkp-toast role="status" aria-live="polite" hidden></div>
