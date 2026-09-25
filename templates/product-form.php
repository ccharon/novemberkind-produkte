<?php

/**
 * Formular zum Anlegen und Bearbeiten eines Produkts nach der Vorlage seiner Produktart.
 *
 * @var \NovemberkindProdukte\ProductType $type
 * @var \WC_Product|null         $product
 * @var array<string, string>    $context
 * @var string                   $description
 * @var bool                     $custom_description
 * @var string[]                 $motif_tags
 * @var string                   $price
 * @var string                   $stock
 * @var string                   $price_a4
 * @var string                   $stock_a4
 * @var int[]                    $gallery_ids
 * @var int[]                    $back_images
 * @var array<int, array{date: string, view: string, download: string}> $backups
 */

declare(strict_types=1);

namespace NovemberkindProdukte;

defined('ABSPATH') || exit;

$is_new         = $product === null;
$product_status = $is_new ? 'draft' : $product->get_status();
$is_online      = $product_status === 'publish';
$is_planned     = $product_status === 'future';
$publish_at     = $is_planned && $product->get_date_created() ? wp_date('Y-m-d\TH:i', $product->get_date_created()->getTimestamp()) : '';
$image_id       = $is_new ? 0 : (int) $product->get_image_id();
$with_a4        = $type->has_field('a4') && ($context['a4'] ?? '') === '1';
// Karten sind immer A6, A4 kommt optional dazu
$size_label = static function (string $plain, string $a6) use ($type): void {
    echo esc_html($type->has_field('a4') ? $a6 : $plain);
};

$field_error = static function (string $field): void {
    printf('<span class="nkp-field__error" data-error-for="%s" hidden></span>', esc_attr($field));
};
$choice = static function (string $name, string $value, string $label, string $current): void {
    printf(
        '<label class="nkp-pill"><input type="radio" name="%s" value="%s" %s><span>%s</span></label>',
        esc_attr($name),
        esc_attr($value),
        checked($current, $value, false),
        esc_html($label)
    );
};
?>
<a class="nkp-back" href="<?php echo esc_url($is_new ? App::new_url() : App::url()); ?>">
    <?php echo $is_new ? esc_html__('← Andere Produktart', 'novemberkind-produkte') : esc_html__('← Alle Produkte', 'novemberkind-produkte'); ?>
</a>

<header class="nkp-header">
    <div>
        <p class="nkp-eyebrow"><?php echo esc_html($type->label()); ?></p>
        <h1 data-nkp-title>
            <?php echo $is_new ? esc_html__('Neues Produkt', 'novemberkind-produkte') : esc_html($product->get_name()); ?>
        </h1>
    </div>
    <div class="nkp-header__actions">
        <a class="nkp-link" data-nkp-view-link target="_blank" rel="noopener"
           href="<?php echo esc_url($is_new ? '' : (string) get_permalink($product->get_id())); ?>" <?php echo $is_new ? 'hidden' : ''; ?>>
            <?php esc_html_e('Im Shop ansehen ↗', 'novemberkind-produkte'); ?>
        </a>
        <?php // Nach dem Speichern gleich das nächste Produkt derselben Art anlegen ?>
        <a class="nkp-button nkp-button--secondary" data-nkp-another href="<?php echo esc_url(App::new_url($type->key())); ?>" <?php echo $is_new ? 'hidden' : ''; ?>>
            + <?php echo esc_html((string) $type->config('new_label')); ?>
        </a>
    </div>
</header>

<form class="nkp-form" data-nkp-form novalidate>
    <input type="hidden" name="product_id" value="<?php echo esc_attr((string) ($is_new ? 0 : $product->get_id())); ?>">
    <input type="hidden" name="type" value="<?php echo esc_attr($type->key()); ?>">

    <div class="nkp-form__main">
        <section class="nkp-panel">
            <label class="nkp-field">
                <span class="nkp-field__label">
                    <?php echo $type->is_unique() ? esc_html__('Titel des Bildes', 'novemberkind-produkte') : esc_html__('Motiv', 'novemberkind-produkte'); ?>
                </span>
                <input type="text" name="motif" required autocomplete="off" value="<?php echo esc_attr($context['motif']); ?>"
                       placeholder="<?php echo $type->is_unique() ? esc_attr__('z. B. Herbstwald im Nebel', 'novemberkind-produkte') : esc_attr__('z. B. Auf Abenteuerreise', 'novemberkind-produkte'); ?>"
                       data-nkp-name-pattern="<?php echo esc_attr($type->product_name('%s')); ?>">
                <span class="nkp-field__hint" data-nkp-name-preview <?php echo $context['motif'] === '' ? 'hidden' : ''; ?>>
                    <?php esc_html_e('Im Shop:', 'novemberkind-produkte'); ?>
                    <strong><?php echo esc_html($type->product_name($context['motif'])); ?></strong>
                </span>
                <?php $field_error('motif'); ?>
            </label>

            <label class="nkp-field nkp-field--narrow">
                <span class="nkp-field__label"><?php esc_html_e('Artikelnummer', 'novemberkind-produkte'); ?></span>
                <input type="text" name="sku" required autocomplete="off" maxlength="7" autocapitalize="characters" spellcheck="false"
                       value="<?php echo esc_attr($is_new ? '' : $product->get_sku('edit')); ?>" placeholder="A000123">
                <span class="nkp-field__hint">
                    <?php
                    /* translators: %s: nächste freie Artikelnummer */
                    echo esc_html(sprintf(__('Auch Grundlage für die Dateinamen der Fotos. Nächste freie Nummer: %s', 'novemberkind-produkte'), ShopData::next_sku()));
                    ?>
                </span>
                <?php $field_error('sku'); ?>
            </label>

            <?php if ($type->has_field('format')) : ?>
                <div class="nkp-field">
                    <span class="nkp-field__label"><?php esc_html_e('Format', 'novemberkind-produkte'); ?></span>
                    <div class="nkp-pills">
                        <?php $choice('format', 'quer', __('Querformat 15 × 10,5 cm', 'novemberkind-produkte'), $context['format']); ?>
                        <?php $choice('format', 'hoch', __('Hochformat 10,5 × 15 cm', 'novemberkind-produkte'), $context['format']); ?>
                    </div>
                    <?php $field_error('format'); ?>
                </div>
            <?php endif; ?>

            <?php if ($type->has_field('a4')) : ?>
                <label class="nkp-check">
                    <input type="checkbox" name="a4" value="1" data-nkp-a4-toggle <?php checked($with_a4); ?>>
                    <?php esc_html_e('Auch in A4 anbieten', 'novemberkind-produkte'); ?>
                </label>
            <?php endif; ?>

            <?php if ($type->has_field('finish')) : ?>
                <div class="nkp-field">
                    <span class="nkp-field__label"><?php esc_html_e('Oberfläche', 'novemberkind-produkte'); ?></span>
                    <div class="nkp-pills">
                        <?php $choice('finish', 'matt', __('Matt', 'novemberkind-produkte'), $context['finish']); ?>
                        <?php $choice('finish', 'glaenzend', __('Glänzend', 'novemberkind-produkte'), $context['finish']); ?>
                    </div>
                    <?php $field_error('finish'); ?>
                </div>
            <?php endif; ?>

            <?php if ($type->has_field('bookmark_width')) : ?>
                <div class="nkp-field">
                    <span class="nkp-field__label"><?php esc_html_e('Breite', 'novemberkind-produkte'); ?></span>
                    <div class="nkp-pills">
                        <?php $choice('width', '5', __('5 cm', 'novemberkind-produkte'), $context['width']); ?>
                        <?php $choice('width', '7', __('7 cm', 'novemberkind-produkte'), $context['width']); ?>
                    </div>
                    <?php $field_error('width'); ?>
                </div>
            <?php endif; ?>

            <?php if ($type->has_field('technique') || $type->has_field('year')) : ?>
                <div class="nkp-row">
                    <?php if ($type->has_field('technique')) : ?>
                        <label class="nkp-field">
                            <span class="nkp-field__label"><?php esc_html_e('Technik', 'novemberkind-produkte'); ?></span>
                            <select name="technique">
                                <option value=""><?php esc_html_e('Bitte wählen', 'novemberkind-produkte'); ?></option>
                                <?php foreach (ProductType::TECHNIQUES as $technique) : ?>
                                    <option <?php selected($context['technique'], $technique); ?>><?php echo esc_html($technique); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <?php $field_error('technique'); ?>
                        </label>
                    <?php endif; ?>
                    <?php if ($type->has_field('year')) : ?>
                        <label class="nkp-field">
                            <span class="nkp-field__label"><?php esc_html_e('Entstanden', 'novemberkind-produkte'); ?></span>
                            <input type="text" name="year" inputmode="numeric" maxlength="4" value="<?php echo esc_attr($context['year']); ?>">
                            <?php $field_error('year'); ?>
                        </label>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <?php if ($type->has_field('size')) : ?>
                <div class="nkp-row">
                    <label class="nkp-field">
                        <span class="nkp-field__label"><?php esc_html_e('Breite', 'novemberkind-produkte'); ?></span>
                        <span class="nkp-input-unit">
                            <input type="text" name="width" inputmode="decimal" value="<?php echo esc_attr(ProductType::format_number($context['width'])); ?>">
                            <span aria-hidden="true">cm</span>
                        </span>
                        <?php $field_error('width'); ?>
                    </label>
                    <label class="nkp-field">
                        <span class="nkp-field__label"><?php esc_html_e('Höhe', 'novemberkind-produkte'); ?></span>
                        <span class="nkp-input-unit">
                            <input type="text" name="height" inputmode="decimal" value="<?php echo esc_attr(ProductType::format_number($context['height'])); ?>">
                            <span aria-hidden="true">cm</span>
                        </span>
                        <?php $field_error('height'); ?>
                    </label>
                </div>
            <?php endif; ?>

            <?php if ($type->has_field('text')) : ?>
                <label class="nkp-field">
                    <span class="nkp-field__label"><?php esc_html_e('Über das Bild', 'novemberkind-produkte'); ?></span>
                    <span class="nkp-field__hint"><?php esc_html_e('Was ist zu sehen, wie ist es entstanden? Technik, Maße und Jahr werden automatisch ergänzt.', 'novemberkind-produkte'); ?></span>
                    <textarea name="text" rows="7"><?php echo esc_textarea($context['text']); ?></textarea>
                    <?php $field_error('text'); ?>
                </label>
            <?php endif; ?>

            <div class="nkp-row">
                <label class="nkp-field">
                    <span class="nkp-field__label"><?php $size_label(__('Preis', 'novemberkind-produkte'), __('Preis A6', 'novemberkind-produkte')); ?></span>
                    <span class="nkp-input-unit">
                        <input type="text" name="price" inputmode="decimal" required autocomplete="off"
                               value="<?php echo esc_attr($price === '' ? '' : wc_format_localized_price($price)); ?>" placeholder="0,00">
                        <span aria-hidden="true">€</span>
                    </span>
                    <?php if ($type->is_variable()) : ?>
                        <span class="nkp-field__hint"><?php esc_html_e('Gilt für alle Rückseiten.', 'novemberkind-produkte'); ?></span>
                    <?php endif; ?>
                    <?php $field_error('price'); ?>
                </label>
                <?php if (!$type->is_unique()) : ?>
                    <label class="nkp-field">
                        <span class="nkp-field__label"><?php $size_label(__('Lagerbestand', 'novemberkind-produkte'), __('Lagerbestand A6', 'novemberkind-produkte')); ?></span>
                        <input type="number" name="stock" min="0" step="1" inputmode="numeric" value="<?php echo esc_attr($stock); ?>"
                               placeholder="<?php esc_attr_e('leer = nicht zählen', 'novemberkind-produkte'); ?>">
                        <?php $field_error('stock'); ?>
                    </label>
                <?php endif; ?>
            </div>

            <?php if ($type->has_field('a4')) : ?>
                <div class="nkp-row nkp-row--optional<?php echo $with_a4 ? '' : ' is-off'; ?>" data-nkp-a4-fields>
                    <label class="nkp-field">
                        <span class="nkp-field__label"><?php esc_html_e('Preis A4', 'novemberkind-produkte'); ?></span>
                        <span class="nkp-input-unit">
                            <input type="text" name="price_a4" inputmode="decimal" autocomplete="off" <?php disabled(!$with_a4); ?>
                                   value="<?php echo esc_attr($price_a4 === '' ? '' : wc_format_localized_price($price_a4)); ?>" placeholder="0,00">
                            <span aria-hidden="true">€</span>
                        </span>
                        <?php $field_error('price_a4'); ?>
                    </label>
                    <label class="nkp-field">
                        <span class="nkp-field__label"><?php esc_html_e('Lagerbestand A4', 'novemberkind-produkte'); ?></span>
                        <input type="number" name="stock_a4" min="0" step="1" inputmode="numeric" value="<?php echo esc_attr($stock_a4); ?>" <?php disabled(!$with_a4); ?>
                               placeholder="<?php esc_attr_e('leer = nicht zählen', 'novemberkind-produkte'); ?>">
                        <?php $field_error('stock_a4'); ?>
                    </label>
                </div>
            <?php endif; ?>

            <label class="nkp-field">
                <span class="nkp-field__label"><?php esc_html_e('Schlagwörter zum Motiv', 'novemberkind-produkte'); ?></span>
                <span class="nkp-field__hint">
                    <?php
                    $fixed = $type->tags($context);
                    echo $fixed === []
                        ? esc_html__('Mit Komma getrennt, z. B. otter, tier.', 'novemberkind-produkte')
                        /* translators: %s: feste Schlagwörter der Produktart */
                        : esc_html(sprintf(__('Mit Komma getrennt, z. B. otter, tier. Automatisch dabei: %s.', 'novemberkind-produkte'), implode(', ', $fixed)));
                    ?>
                </span>
                <input type="text" name="tags" autocomplete="off" value="<?php echo esc_attr(implode(', ', $motif_tags)); ?>">
            </label>

            <?php if (Suggestions::is_available()) : ?>
                <div class="nkp-suggest">
                    <div class="nkp-suggest__text">
                        <strong><?php esc_html_e('Vorschlag von Claude', 'novemberkind-produkte'); ?></strong>
                        <span><?php esc_html_e('Claude sieht sich Foto und Angaben an und schlägt Titel, Beschreibung und Schlagwörter vor. Du entscheidest, was du übernimmst.', 'novemberkind-produkte'); ?></span>
                    </div>
                    <button type="button" class="nkp-button nkp-button--secondary" data-nkp-suggest><?php esc_html_e('Vorschlag holen', 'novemberkind-produkte'); ?></button>
                </div>
            <?php endif; ?>

            <div class="nkp-field" data-nkp-description data-custom="<?php echo $custom_description ? '1' : '0'; ?>">
                <span class="nkp-field__label" id="nkp-description-label"><?php esc_html_e('Beschreibung im Shop', 'novemberkind-produkte'); ?></span>
                <span class="nkp-field__hint" data-nkp-description-auto><?php esc_html_e('Entsteht aus deinen Angaben und passt sich an, solange du den Text nicht selbst änderst.', 'novemberkind-produkte'); ?></span>
                <span class="nkp-field__hint" data-nkp-description-own><?php esc_html_e('Du hast den Text angepasst. Änderungen an den Angaben oben übernimmt er nicht mehr.', 'novemberkind-produkte'); ?></span>
                <div class="nkp-editor">
                    <div class="nkp-editor__toolbar">
                        <button type="button" class="nkp-editor__button" data-nkp-command="bold" title="<?php esc_attr_e('Fett', 'novemberkind-produkte'); ?>"><strong>F</strong></button>
                        <button type="button" class="nkp-editor__button" data-nkp-command="italic" title="<?php esc_attr_e('Kursiv', 'novemberkind-produkte'); ?>"><em>K</em></button>
                        <button type="button" class="nkp-editor__reset" data-nkp-description-reset><?php esc_html_e('Aus Vorlage neu erstellen', 'novemberkind-produkte'); ?></button>
                    </div>
                    <div class="nkp-editor__content" contenteditable="true" role="textbox" aria-multiline="true"
                         aria-labelledby="nkp-description-label" data-nkp-editor><?php echo wp_kses_post($description); ?></div>
                </div>
                <input type="hidden" name="description" value="">
                <input type="hidden" name="description_custom" value="<?php echo $custom_description ? '1' : '0'; ?>">
            </div>
        </section>
    </div>

    <aside class="nkp-form__side">
        <section class="nkp-panel nkp-panel--photos">
            <h2 class="nkp-panel__title"><?php esc_html_e('Fotos', 'novemberkind-produkte'); ?></h2>

            <div class="nkp-photo nkp-photo--main <?php echo $image_id ? 'has-image' : ''; ?>" data-nkp-main-photo>
                <label class="nkp-dropzone" data-nkp-dropzone>
                    <input type="file" accept="image/*" class="screen-reader-text" data-nkp-file>
                    <?php if ($image_id) : ?>
                        <?php echo wp_get_attachment_image($image_id, 'woocommerce_thumbnail', false, ['class' => 'nkp-photo__image']); ?>
                    <?php else : ?>
                        <img class="nkp-photo__image" alt="" hidden>
                    <?php endif; ?>
                    <span class="nkp-dropzone__hint">
                        <?php esc_html_e('Hauptfoto auswählen', 'novemberkind-produkte'); ?>
                        <small><?php esc_html_e('oder hierher ziehen', 'novemberkind-produkte'); ?></small>
                    </span>
                </label>
                <button type="button" class="nkp-photo__remove" data-nkp-remove aria-label="<?php esc_attr_e('Foto entfernen', 'novemberkind-produkte'); ?>">×</button>
                <input type="hidden" name="image_id" value="<?php echo esc_attr((string) $image_id); ?>">
            </div>

            <div>
                <p class="nkp-field__label"><?php esc_html_e('Weitere Fotos', 'novemberkind-produkte'); ?></p>
                <div class="nkp-gallery" data-nkp-gallery>
                    <?php foreach ($gallery_ids as $gallery_id) : ?>
                        <div class="nkp-photo has-image">
                            <?php echo wp_get_attachment_image($gallery_id, 'woocommerce_gallery_thumbnail', false, ['class' => 'nkp-photo__image']); ?>
                            <button type="button" class="nkp-photo__remove" data-nkp-remove aria-label="<?php esc_attr_e('Foto entfernen', 'novemberkind-produkte'); ?>">×</button>
                            <input type="hidden" name="gallery_ids[]" value="<?php echo esc_attr((string) $gallery_id); ?>">
                        </div>
                    <?php endforeach; ?>
                    <label class="nkp-dropzone nkp-dropzone--add" data-nkp-dropzone data-nkp-gallery-add
                           aria-label="<?php esc_attr_e('Weitere Fotos hinzufügen', 'novemberkind-produkte'); ?>">
                        <input type="file" accept="image/*" multiple class="screen-reader-text" data-nkp-file>
                        <span aria-hidden="true">+</span>
                    </label>
                </div>
            </div>

            <?php if ($type->is_variable()) : ?>
                <p class="nkp-field__hint">
                    <?php
                    echo $back_images === []
                        ? esc_html__('Die Fotos der Rückseiten wurden in der Mediathek nicht gefunden und fehlen daher in der Galerie.', 'novemberkind-produkte')
                        /* translators: %d: Anzahl der Fotos */
                        : esc_html(sprintf(__('Die %d Fotos der Rückseiten kommen automatisch dazu.', 'novemberkind-produkte'), count($back_images)));
                    ?>
                </p>
            <?php endif; ?>
        </section>

        <section class="nkp-panel">
            <h2 class="nkp-panel__title"><?php esc_html_e('Sichtbarkeit', 'novemberkind-produkte'); ?></h2>
            <div class="nkp-choices">
                <label class="nkp-choice">
                    <input type="radio" name="status" value="draft" <?php checked(!$is_online && !$is_planned); ?>>
                    <span><strong><?php esc_html_e('Entwurf', 'novemberkind-produkte'); ?></strong>
                    <?php esc_html_e('Noch nicht im Shop', 'novemberkind-produkte'); ?></span>
                </label>
                <label class="nkp-choice">
                    <input type="radio" name="status" value="future" <?php checked($is_planned); ?>>
                    <span><strong><?php esc_html_e('Geplant', 'novemberkind-produkte'); ?></strong>
                    <?php esc_html_e('Geht zum gewählten Zeitpunkt automatisch online', 'novemberkind-produkte'); ?></span>
                </label>
                <label class="nkp-field nkp-field--schedule" data-nkp-schedule <?php echo $is_planned ? '' : 'hidden'; ?>>
                    <span class="nkp-field__label"><?php esc_html_e('Online ab', 'novemberkind-produkte'); ?></span>
                    <input type="datetime-local" name="publish_at" value="<?php echo esc_attr($publish_at); ?>" <?php disabled(!$is_planned); ?>>
                    <?php $field_error('publish_at'); ?>
                </label>
                <label class="nkp-choice">
                    <input type="radio" name="status" value="publish" <?php checked($is_online); ?>>
                    <span><strong><?php esc_html_e('Online', 'novemberkind-produkte'); ?></strong>
                    <?php esc_html_e('Im Shop zu kaufen', 'novemberkind-produkte'); ?></span>
                </label>
            </div>
            <?php if (!$is_new && Originals::is_sold($product)) : ?>
                <p class="nkp-field__hint"><?php esc_html_e('Verkauft. Das Bild erscheint nicht mehr im Shop, die Seite ist über ihren Link noch erreichbar.', 'novemberkind-produkte'); ?></p>
            <?php endif; ?>
        </section>

        <section class="nkp-panel" data-nkp-backups <?php echo $is_new ? 'hidden' : ''; ?>>
            <h2 class="nkp-panel__title"><?php esc_html_e('Sicherungen', 'novemberkind-produkte'); ?></h2>
            <ul class="nkp-backups" data-nkp-backup-list>
                <?php foreach ($backups as $backup) : ?>
                    <li>
                        <span><?php echo esc_html($backup['date']); ?></span>
                        <a href="<?php echo esc_url($backup['view']); ?>" target="_blank" rel="noopener"><?php esc_html_e('Ansehen', 'novemberkind-produkte'); ?></a>
                        <a href="<?php echo esc_url($backup['download']); ?>"><?php esc_html_e('Herunterladen', 'novemberkind-produkte'); ?></a>
                    </li>
                <?php endforeach; ?>
            </ul>
            <p class="nkp-field__hint">
                <?php
                /* translators: %d: Anzahl der Sicherungen, die behalten werden */
                echo esc_html(sprintf(__('Vor jedem Speichern wird der bisherige Stand gesichert. Die neuesten %d bleiben erhalten.', 'novemberkind-produkte'), Backups::KEEP));
                ?>
            </p>
        </section>

        <button type="submit" class="nkp-button nkp-button--primary nkp-button--block" data-nkp-submit>
            <?php esc_html_e('Speichern', 'novemberkind-produkte'); ?>
        </button>
    </aside>
</form>

<template data-nkp-gallery-item>
    <div class="nkp-photo has-image">
        <img class="nkp-photo__image" alt="">
        <button type="button" class="nkp-photo__remove" data-nkp-remove aria-label="<?php esc_attr_e('Foto entfernen', 'novemberkind-produkte'); ?>">×</button>
        <input type="hidden" name="gallery_ids[]" value="">
    </div>
</template>

<?php if (Suggestions::is_available()) : ?>
    <dialog class="nkp-dialog" data-nkp-suggestion aria-labelledby="nkp-suggestion-title">
        <form method="dialog">
            <h2 class="nkp-dialog__title" id="nkp-suggestion-title"><?php esc_html_e('Vorschlag von Claude', 'novemberkind-produkte'); ?></h2>
            <p class="nkp-note" data-nkp-suggestion-mode></p>

            <section class="nkp-dialog__part">
                <label class="nkp-check"><input type="checkbox" checked data-nkp-take="title"> <?php esc_html_e('Titel übernehmen', 'novemberkind-produkte'); ?></label>
                <p class="nkp-dialog__value" data-nkp-suggestion-title></p>
            </section>
            <section class="nkp-dialog__part">
                <label class="nkp-check"><input type="checkbox" checked data-nkp-take="tags"> <?php esc_html_e('Schlagwörter übernehmen', 'novemberkind-produkte'); ?></label>
                <p class="nkp-chips" data-nkp-suggestion-tags></p>
            </section>
            <section class="nkp-dialog__part">
                <label class="nkp-check"><input type="checkbox" checked data-nkp-take="description"> <?php esc_html_e('Beschreibung übernehmen', 'novemberkind-produkte'); ?></label>
                <div class="nkp-dialog__preview" data-nkp-suggestion-description></div>
            </section>

            <footer class="nkp-dialog__actions">
                <button type="submit" value="cancel" class="nkp-button nkp-button--secondary"><?php esc_html_e('Verwerfen', 'novemberkind-produkte'); ?></button>
                <button type="submit" value="apply" class="nkp-button nkp-button--primary"><?php esc_html_e('Auswahl übernehmen', 'novemberkind-produkte'); ?></button>
            </footer>
        </form>
    </dialog>
<?php endif; ?>

<div class="nkp-toast" data-nkp-toast role="status" aria-live="polite" hidden></div>
