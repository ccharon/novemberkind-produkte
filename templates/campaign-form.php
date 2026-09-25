<?php

/**
 * Formular einer Rabattaktion.
 *
 * @var array{id: int, name: string, percent: int, start: int, end: int, scope: string, categories: int[], products: int[]}|null $campaign
 * @var array<int, array{id: int, name: string, depth: int, count: int}>                                                         $categories
 * @var array<int, array{id: int, name: string, sku: string, own_sale: bool}>                                                   $products
 * @var array{overlaps: string[], reference: string[]}                                                                          $conflicts
 */

declare(strict_types=1);

namespace NovemberkindProdukte;

defined('ABSPATH') || exit;

$is_new          = $campaign === null;
$campaign_status = $is_new ? 'new' : Campaigns::status($campaign);
$is_ended        = $campaign_status === 'ended';
$scope           = $campaign['scope'] ?? 'all';
$local           = static fn(?int $timestamp, string $format): string => $timestamp ? wp_date($format, $timestamp) : '';

$field_error = static function (string $field): void {
    printf('<span class="nkp-field__error" data-error-for="%s" hidden></span>', esc_attr($field));
};
$status_labels = [
    'running' => __('Läuft gerade', 'novemberkind-produkte'),
    'planned' => __('Geplant', 'novemberkind-produkte'),
    'ended'   => __('Beendet', 'novemberkind-produkte'),
];
?>
<a class="nkp-back" href="<?php echo esc_url(App::campaigns_url()); ?>"><?php esc_html_e('← Alle Aktionen', 'novemberkind-produkte'); ?></a>

<header class="nkp-header">
    <div>
        <p class="nkp-eyebrow"><?php esc_html_e('Aktion', 'novemberkind-produkte'); ?></p>
        <h1><?php echo $is_new ? esc_html__('Neue Aktion', 'novemberkind-produkte') : esc_html($campaign['name']); ?></h1>
    </div>
    <?php if (!$is_new) : ?>
        <span class="nkp-badge nkp-badge--campaign-<?php echo esc_attr($campaign_status); ?>"><?php echo esc_html($status_labels[$campaign_status]); ?></span>
    <?php endif; ?>
</header>

<form class="nkp-campaign-form" data-nkp-simple-form data-nkp-save="novemberkind_produkte_save_campaign" novalidate>
    <input type="hidden" name="id" value="<?php echo esc_attr((string) ($campaign['id'] ?? 0)); ?>">

    <?php if ($is_ended) : ?>
        <p class="nkp-note"><?php esc_html_e('Diese Aktion ist beendet und bleibt zur Ansicht erhalten. Sie zeigt, welche Preise wann reduziert waren.', 'novemberkind-produkte'); ?></p>
    <?php endif; ?>
    <?php foreach ($conflicts['overlaps'] as $other) : ?>
        <p class="nkp-note">
            <?php
            /* translators: %s: Name der anderen Aktion */
            echo esc_html(sprintf(__('Läuft zeitgleich mit „%s“ für teils dieselben Produkte. Es gilt jeweils der höhere Rabatt.', 'novemberkind-produkte'), $other));
            ?>
        </p>
    <?php endforeach; ?>
    <?php foreach ($conflicts['reference'] as $other) : ?>
        <p class="nkp-note nkp-note--warning">
            <?php
            /* translators: %s: Name der anderen Aktion */
            echo esc_html(sprintf(__('„%s“ hat dieselben Produkte in den 30 Tagen vor dem Start reduziert. Als Vergleichspreis muss dann der niedrigste Preis dieser 30 Tage gelten, der durchgestrichene Normalpreis ist rechtlich heikel.', 'novemberkind-produkte'), $other));
            ?>
        </p>
    <?php endforeach; ?>

    <fieldset class="nkp-campaign-form__fields" <?php disabled($is_ended); ?>>
        <section class="nkp-panel">
            <label class="nkp-field">
                <span class="nkp-field__label"><?php esc_html_e('Name', 'novemberkind-produkte'); ?></span>
                <input type="text" name="name" required autocomplete="off" maxlength="100" value="<?php echo esc_attr($campaign['name'] ?? ''); ?>"
                       placeholder="<?php esc_attr_e('z. B. Herbstaktion', 'novemberkind-produkte'); ?>">
                <span class="nkp-field__hint"><?php esc_html_e('Nur für dich, im Shop erscheint der Name nicht.', 'novemberkind-produkte'); ?></span>
                <?php $field_error('name'); ?>
            </label>

            <label class="nkp-field nkp-field--narrow">
                <span class="nkp-field__label"><?php esc_html_e('Rabatt in Prozent', 'novemberkind-produkte'); ?></span>
                <input type="number" name="percent" required min="1" max="<?php echo esc_attr((string) Campaigns::MAX_PERCENT); ?>" step="1" inputmode="numeric"
                       value="<?php echo esc_attr((string) ($campaign['percent'] ?? '')); ?>" placeholder="20">
                <?php $field_error('percent'); ?>
            </label>

            <?php
            $moments = [
                'start' => [__('Beginn', 'novemberkind-produkte'), $campaign['start'] ?? time(), ''],
                'end'   => [__('Ende', 'novemberkind-produkte'), $campaign['end'] ?? null, '23:59'],
            ];
            foreach ($moments as $key => [$label, $timestamp, $default_time]) :
                ?>
                <fieldset class="nkp-field nkp-moment">
                    <legend class="nkp-field__label"><?php echo esc_html($label); ?></legend>
                    <div class="nkp-moment__inputs">
                        <input type="date" name="<?php echo esc_attr($key); ?>_date" required value="<?php echo esc_attr($local($timestamp, 'Y-m-d')); ?>"
                               aria-label="<?php echo esc_attr(sprintf(/* translators: %s: Beginn oder Ende */ __('%s, Datum', 'novemberkind-produkte'), $label)); ?>">
                        <input type="time" name="<?php echo esc_attr($key); ?>_time" step="60" value="<?php echo esc_attr($timestamp ? $local($timestamp, 'H:i') : $default_time); ?>"
                               aria-label="<?php echo esc_attr(sprintf(/* translators: %s: Beginn oder Ende */ __('%s, Uhrzeit', 'novemberkind-produkte'), $label)); ?>">
                    </div>
                    <?php $field_error($key); ?>
                </fieldset>
            <?php endforeach; ?>
        </section>

        <section class="nkp-panel">
            <h2 class="nkp-panel__title"><?php esc_html_e('Gilt für', 'novemberkind-produkte'); ?></h2>
            <div class="nkp-choices">
                <label class="nkp-choice">
                    <input type="radio" name="scope" value="all" <?php checked($scope, 'all'); ?>>
                    <span><strong><?php esc_html_e('Ganzer Shop', 'novemberkind-produkte'); ?></strong>
                    <?php esc_html_e('Alle Produkte', 'novemberkind-produkte'); ?></span>
                </label>
                <label class="nkp-choice">
                    <input type="radio" name="scope" value="categories" <?php checked($scope, 'categories'); ?>>
                    <span><strong><?php esc_html_e('Kategorien', 'novemberkind-produkte'); ?></strong>
                    <?php esc_html_e('Eine Kategorie gilt samt ihren Unterkategorien', 'novemberkind-produkte'); ?></span>
                </label>
                <label class="nkp-choice">
                    <input type="radio" name="scope" value="products" <?php checked($scope, 'products'); ?>>
                    <span><strong><?php esc_html_e('Einzelne Produkte', 'novemberkind-produkte'); ?></strong>
                    <?php esc_html_e('Auswahl aus der Liste', 'novemberkind-produkte'); ?></span>
                </label>
            </div>

            <div class="nkp-picker" data-nkp-show-for="scope:categories" <?php echo $scope === 'categories' ? '' : 'hidden'; ?>>
                <?php foreach ($categories as $category) : ?>
                    <label class="nkp-check" style="--depth: <?php echo esc_attr((string) $category['depth']); ?>">
                        <input type="checkbox" name="categories[]" value="<?php echo esc_attr((string) $category['id']); ?>"
                            <?php checked(in_array($category['id'], $campaign['categories'] ?? [], true)); ?>>
                        <?php echo esc_html($category['name']); ?>
                        <span class="nkp-picker__count"><?php echo esc_html((string) $category['count']); ?></span>
                    </label>
                <?php endforeach; ?>
                <?php $field_error('categories'); ?>
            </div>

            <div class="nkp-picker" data-nkp-show-for="scope:products" <?php echo $scope === 'products' ? '' : 'hidden'; ?>>
                <input type="search" class="nkp-picker__filter" data-nkp-product-filter autocomplete="off"
                       placeholder="<?php esc_attr_e('Name oder Artikelnummer suchen', 'novemberkind-produkte'); ?>"
                       aria-label="<?php esc_attr_e('Produkte filtern', 'novemberkind-produkte'); ?>">
                <div class="nkp-picker__list">
                    <?php foreach ($products as $item) : ?>
                        <label class="nkp-check" data-nkp-product="<?php echo esc_attr(mb_strtolower($item['name'] . ' ' . $item['sku'])); ?>">
                            <input type="checkbox" name="products[]" value="<?php echo esc_attr((string) $item['id']); ?>"
                                <?php checked(in_array($item['id'], $campaign['products'] ?? [], true)); ?>>
                            <?php echo esc_html($item['name']); ?>
                            <span class="nkp-picker__count"><?php echo esc_html($item['sku']); ?></span>
                            <?php if ($item['own_sale']) : ?>
                                <span class="nkp-picker__hint"><?php esc_html_e('eigener Angebotspreis, ausgenommen', 'novemberkind-produkte'); ?></span>
                            <?php endif; ?>
                        </label>
                    <?php endforeach; ?>
                </div>
                <?php $field_error('products'); ?>
            </div>
        </section>
    </fieldset>

    <?php if (!$is_ended) : ?>
        <div class="nkp-campaign-form__actions">
            <?php if (!$is_new) : ?>
                <button type="button" class="nkp-button nkp-button--secondary" data-nkp-action="novemberkind_produkte_end_campaign"
                        data-nkp-confirm="<?php esc_attr_e('Die Aktion endet sofort, die Preise im Shop sind dann wieder normal. Beenden?', 'novemberkind-produkte'); ?>">
                    <?php echo $campaign_status === 'planned' ? esc_html__('Nicht starten', 'novemberkind-produkte') : esc_html__('Jetzt beenden', 'novemberkind-produkte'); ?>
                </button>
            <?php endif; ?>
            <button type="submit" class="nkp-button nkp-button--primary" data-nkp-submit><?php esc_html_e('Speichern', 'novemberkind-produkte'); ?></button>
        </div>
    <?php endif; ?>
</form>

<div class="nkp-toast" data-nkp-toast role="status" aria-live="polite" hidden></div>
