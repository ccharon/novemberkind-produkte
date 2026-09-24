<?php

/**
 * Formular zum Anlegen und Bearbeiten eines Produkts nach der Vorlage seiner Produktart.
 *
 * @var \EasyProduct\ProductType $type
 * @var \WC_Product|null         $product
 * @var array<string, string>    $context
 * @var string                   $description
 * @var bool                     $custom_description
 * @var string[]                 $motif_tags
 * @var string                   $price
 * @var int[]                    $gallery_ids
 * @var int[]                    $back_images
 */

declare(strict_types=1);

namespace EasyProduct;

defined('ABSPATH') || exit;

$is_new    = $product === null;
$is_online = !$is_new && $product->get_status() === 'publish';
$image_id  = $is_new ? 0 : (int) $product->get_image_id();
$stock     = !$is_new && $product->managing_stock() ? (string) $product->get_stock_quantity() : '';

$field_error = static function (string $field): void {
    printf('<span class="ep-field__error" data-error-for="%s" hidden></span>', esc_attr($field));
};
$choice = static function (string $name, string $value, string $label, string $current): void {
    printf(
        '<label class="ep-pill"><input type="radio" name="%s" value="%s" %s><span>%s</span></label>',
        esc_attr($name),
        esc_attr($value),
        checked($current, $value, false),
        esc_html($label)
    );
};
?>
<a class="ep-back" href="<?php echo esc_url($is_new ? App::new_url() : App::url()); ?>">
    <?php echo $is_new ? esc_html__('← Andere Produktart', 'easy-product') : esc_html__('← Alle Produkte', 'easy-product'); ?>
</a>

<header class="ep-header">
    <div>
        <p class="ep-eyebrow"><?php echo esc_html($type->label()); ?></p>
        <h1 data-ep-title>
            <?php echo $is_new ? esc_html__('Neues Produkt', 'easy-product') : esc_html($product->get_name()); ?>
        </h1>
    </div>
    <a class="ep-link" data-ep-view-link target="_blank" rel="noopener"
       href="<?php echo esc_url($is_new ? '' : (string) get_permalink($product->get_id())); ?>" <?php echo $is_new ? 'hidden' : ''; ?>>
        <?php esc_html_e('Im Shop ansehen ↗', 'easy-product'); ?>
    </a>
</header>

<form class="ep-form" data-ep-form novalidate>
    <input type="hidden" name="product_id" value="<?php echo esc_attr((string) ($is_new ? 0 : $product->get_id())); ?>">
    <input type="hidden" name="type" value="<?php echo esc_attr($type->key()); ?>">

    <div class="ep-form__main">
        <section class="ep-panel">
            <label class="ep-field">
                <span class="ep-field__label">
                    <?php echo $type->is_unique() ? esc_html__('Titel des Bildes', 'easy-product') : esc_html__('Motiv', 'easy-product'); ?>
                </span>
                <input type="text" name="motif" required autocomplete="off" value="<?php echo esc_attr($context['motif']); ?>"
                       placeholder="<?php echo $type->is_unique() ? esc_attr__('z. B. Herbstwald im Nebel', 'easy-product') : esc_attr__('z. B. Auf Abenteuerreise', 'easy-product'); ?>"
                       data-ep-name-pattern="<?php echo esc_attr($type->product_name('%s')); ?>">
                <span class="ep-field__hint" data-ep-name-preview <?php echo $context['motif'] === '' ? 'hidden' : ''; ?>>
                    <?php esc_html_e('Im Shop:', 'easy-product'); ?>
                    <strong><?php echo esc_html($type->product_name($context['motif'])); ?></strong>
                </span>
                <?php $field_error('motif'); ?>
            </label>

            <label class="ep-field ep-field--narrow">
                <span class="ep-field__label"><?php esc_html_e('Artikelnummer', 'easy-product'); ?></span>
                <input type="text" name="sku" required autocomplete="off" maxlength="7" autocapitalize="characters" spellcheck="false"
                       value="<?php echo esc_attr($is_new ? '' : $product->get_sku('edit')); ?>" placeholder="A000123">
                <span class="ep-field__hint">
                    <?php
                    /* translators: %s: nächste freie Artikelnummer */
                    echo esc_html(sprintf(__('Auch Grundlage für die Dateinamen der Fotos. Nächste freie Nummer: %s', 'easy-product'), ShopData::next_sku()));
                    ?>
                </span>
                <?php $field_error('sku'); ?>
            </label>

            <?php if ($type->has_field('format')) : ?>
                <div class="ep-field">
                    <span class="ep-field__label"><?php esc_html_e('Format', 'easy-product'); ?></span>
                    <div class="ep-pills">
                        <?php $choice('format', 'quer', __('Querformat 15 × 10,5 cm', 'easy-product'), $context['format']); ?>
                        <?php $choice('format', 'hoch', __('Hochformat 10,5 × 15 cm', 'easy-product'), $context['format']); ?>
                    </div>
                    <?php $field_error('format'); ?>
                </div>
            <?php endif; ?>

            <?php if ($type->has_field('finish')) : ?>
                <div class="ep-field">
                    <span class="ep-field__label"><?php esc_html_e('Oberfläche', 'easy-product'); ?></span>
                    <div class="ep-pills">
                        <?php $choice('finish', 'matt', __('Matt', 'easy-product'), $context['finish']); ?>
                        <?php $choice('finish', 'glaenzend', __('Glänzend', 'easy-product'), $context['finish']); ?>
                    </div>
                    <?php $field_error('finish'); ?>
                </div>
            <?php endif; ?>

            <?php if ($type->has_field('bookmark_width')) : ?>
                <div class="ep-field">
                    <span class="ep-field__label"><?php esc_html_e('Breite', 'easy-product'); ?></span>
                    <div class="ep-pills">
                        <?php $choice('width', '5', __('5 cm', 'easy-product'), $context['width']); ?>
                        <?php $choice('width', '7', __('7 cm', 'easy-product'), $context['width']); ?>
                    </div>
                    <?php $field_error('width'); ?>
                </div>
            <?php endif; ?>

            <?php if ($type->has_field('technique') || $type->has_field('year')) : ?>
                <div class="ep-row">
                    <?php if ($type->has_field('technique')) : ?>
                        <label class="ep-field">
                            <span class="ep-field__label"><?php esc_html_e('Technik', 'easy-product'); ?></span>
                            <select name="technique">
                                <option value=""><?php esc_html_e('Bitte wählen', 'easy-product'); ?></option>
                                <?php foreach (ProductType::TECHNIQUES as $technique) : ?>
                                    <option <?php selected($context['technique'], $technique); ?>><?php echo esc_html($technique); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <?php $field_error('technique'); ?>
                        </label>
                    <?php endif; ?>
                    <?php if ($type->has_field('year')) : ?>
                        <label class="ep-field">
                            <span class="ep-field__label"><?php esc_html_e('Entstanden', 'easy-product'); ?></span>
                            <input type="text" name="year" inputmode="numeric" maxlength="4" value="<?php echo esc_attr($context['year']); ?>">
                            <?php $field_error('year'); ?>
                        </label>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <?php if ($type->has_field('size')) : ?>
                <div class="ep-row">
                    <label class="ep-field">
                        <span class="ep-field__label"><?php esc_html_e('Breite', 'easy-product'); ?></span>
                        <span class="ep-input-unit">
                            <input type="text" name="width" inputmode="decimal" value="<?php echo esc_attr(ProductType::format_number($context['width'])); ?>">
                            <span aria-hidden="true">cm</span>
                        </span>
                        <?php $field_error('width'); ?>
                    </label>
                    <label class="ep-field">
                        <span class="ep-field__label"><?php esc_html_e('Höhe', 'easy-product'); ?></span>
                        <span class="ep-input-unit">
                            <input type="text" name="height" inputmode="decimal" value="<?php echo esc_attr(ProductType::format_number($context['height'])); ?>">
                            <span aria-hidden="true">cm</span>
                        </span>
                        <?php $field_error('height'); ?>
                    </label>
                </div>
            <?php endif; ?>

            <?php if ($type->has_field('text')) : ?>
                <label class="ep-field">
                    <span class="ep-field__label"><?php esc_html_e('Über das Bild', 'easy-product'); ?></span>
                    <span class="ep-field__hint"><?php esc_html_e('Was ist zu sehen, wie ist es entstanden? Technik, Maße und Jahr werden automatisch ergänzt.', 'easy-product'); ?></span>
                    <textarea name="text" rows="7"><?php echo esc_textarea($context['text']); ?></textarea>
                    <?php $field_error('text'); ?>
                </label>
            <?php endif; ?>

            <div class="ep-row">
                <label class="ep-field">
                    <span class="ep-field__label"><?php esc_html_e('Preis', 'easy-product'); ?></span>
                    <span class="ep-input-unit">
                        <input type="text" name="price" inputmode="decimal" required autocomplete="off"
                               value="<?php echo esc_attr($price === '' ? '' : wc_format_localized_price($price)); ?>" placeholder="0,00">
                        <span aria-hidden="true">€</span>
                    </span>
                    <?php if ($type->is_variable()) : ?>
                        <span class="ep-field__hint"><?php esc_html_e('Gilt für alle Rückseiten.', 'easy-product'); ?></span>
                    <?php endif; ?>
                    <?php $field_error('price'); ?>
                </label>
                <?php if (!$type->is_unique()) : ?>
                    <label class="ep-field">
                        <span class="ep-field__label"><?php esc_html_e('Lagerbestand', 'easy-product'); ?></span>
                        <input type="number" name="stock" min="0" step="1" inputmode="numeric" value="<?php echo esc_attr($stock); ?>"
                               placeholder="<?php esc_attr_e('leer = nicht zählen', 'easy-product'); ?>">
                        <?php $field_error('stock'); ?>
                    </label>
                <?php endif; ?>
            </div>

            <label class="ep-field">
                <span class="ep-field__label"><?php esc_html_e('Schlagwörter zum Motiv', 'easy-product'); ?></span>
                <span class="ep-field__hint">
                    <?php
                    $fixed = $type->tags($context);
                    echo $fixed === []
                        ? esc_html__('Mit Komma getrennt, z. B. otter, tier.', 'easy-product')
                        /* translators: %s: feste Schlagwörter der Produktart */
                        : esc_html(sprintf(__('Mit Komma getrennt, z. B. otter, tier. Automatisch dabei: %s.', 'easy-product'), implode(', ', $fixed)));
                    ?>
                </span>
                <input type="text" name="tags" autocomplete="off" value="<?php echo esc_attr(implode(', ', $motif_tags)); ?>">
            </label>

            <?php if (Suggestions::is_available()) : ?>
                <div class="ep-suggest">
                    <div class="ep-suggest__text">
                        <strong><?php esc_html_e('Vorschlag von Claude', 'easy-product'); ?></strong>
                        <span><?php esc_html_e('Claude sieht sich Foto und Angaben an und schlägt Titel, Beschreibung und Schlagwörter vor. Du entscheidest, was du übernimmst.', 'easy-product'); ?></span>
                    </div>
                    <button type="button" class="ep-button ep-button--secondary" data-ep-suggest><?php esc_html_e('Vorschlag holen', 'easy-product'); ?></button>
                </div>
            <?php endif; ?>

            <div class="ep-field" data-ep-description data-custom="<?php echo $custom_description ? '1' : '0'; ?>">
                <span class="ep-field__label" id="ep-description-label"><?php esc_html_e('Beschreibung im Shop', 'easy-product'); ?></span>
                <span class="ep-field__hint" data-ep-description-auto><?php esc_html_e('Entsteht aus deinen Angaben und passt sich an, solange du den Text nicht selbst änderst.', 'easy-product'); ?></span>
                <span class="ep-field__hint" data-ep-description-own><?php esc_html_e('Du hast den Text angepasst. Änderungen an den Angaben oben übernimmt er nicht mehr.', 'easy-product'); ?></span>
                <div class="ep-editor">
                    <div class="ep-editor__toolbar">
                        <button type="button" class="ep-editor__button" data-ep-command="bold" title="<?php esc_attr_e('Fett', 'easy-product'); ?>"><strong>F</strong></button>
                        <button type="button" class="ep-editor__button" data-ep-command="italic" title="<?php esc_attr_e('Kursiv', 'easy-product'); ?>"><em>K</em></button>
                        <button type="button" class="ep-editor__reset" data-ep-description-reset><?php esc_html_e('Aus Vorlage neu erstellen', 'easy-product'); ?></button>
                    </div>
                    <div class="ep-editor__content" contenteditable="true" role="textbox" aria-multiline="true"
                         aria-labelledby="ep-description-label" data-ep-editor><?php echo wp_kses_post($description); ?></div>
                </div>
                <input type="hidden" name="description" value="">
                <input type="hidden" name="description_custom" value="<?php echo $custom_description ? '1' : '0'; ?>">
            </div>
        </section>
    </div>

    <aside class="ep-form__side">
        <section class="ep-panel">
            <h2 class="ep-panel__title"><?php esc_html_e('Fotos', 'easy-product'); ?></h2>

            <div class="ep-photo ep-photo--main <?php echo $image_id ? 'has-image' : ''; ?>" data-ep-main-photo>
                <label class="ep-dropzone" data-ep-dropzone>
                    <input type="file" accept="image/*" class="screen-reader-text" data-ep-file>
                    <?php if ($image_id) : ?>
                        <?php echo wp_get_attachment_image($image_id, 'woocommerce_thumbnail', false, ['class' => 'ep-photo__image']); ?>
                    <?php else : ?>
                        <img class="ep-photo__image" alt="" hidden>
                    <?php endif; ?>
                    <span class="ep-dropzone__hint">
                        <?php esc_html_e('Hauptfoto auswählen', 'easy-product'); ?>
                        <small><?php esc_html_e('oder hierher ziehen', 'easy-product'); ?></small>
                    </span>
                </label>
                <button type="button" class="ep-photo__remove" data-ep-remove aria-label="<?php esc_attr_e('Foto entfernen', 'easy-product'); ?>">×</button>
                <input type="hidden" name="image_id" value="<?php echo esc_attr((string) $image_id); ?>">
            </div>

            <div>
                <p class="ep-field__label"><?php esc_html_e('Weitere Fotos', 'easy-product'); ?></p>
                <div class="ep-gallery" data-ep-gallery>
                    <?php foreach ($gallery_ids as $gallery_id) : ?>
                        <div class="ep-photo has-image">
                            <?php echo wp_get_attachment_image($gallery_id, 'woocommerce_gallery_thumbnail', false, ['class' => 'ep-photo__image']); ?>
                            <button type="button" class="ep-photo__remove" data-ep-remove aria-label="<?php esc_attr_e('Foto entfernen', 'easy-product'); ?>">×</button>
                            <input type="hidden" name="gallery_ids[]" value="<?php echo esc_attr((string) $gallery_id); ?>">
                        </div>
                    <?php endforeach; ?>
                    <label class="ep-dropzone ep-dropzone--add" data-ep-dropzone data-ep-gallery-add
                           aria-label="<?php esc_attr_e('Weitere Fotos hinzufügen', 'easy-product'); ?>">
                        <input type="file" accept="image/*" multiple class="screen-reader-text" data-ep-file>
                        <span aria-hidden="true">+</span>
                    </label>
                </div>
            </div>

            <?php if ($type->is_variable()) : ?>
                <p class="ep-field__hint">
                    <?php
                    echo $back_images === []
                        ? esc_html__('Die Fotos der Rückseiten wurden in der Mediathek nicht gefunden und fehlen daher in der Galerie.', 'easy-product')
                        /* translators: %d: Anzahl der Fotos */
                        : esc_html(sprintf(__('Die %d Fotos der Rückseiten kommen automatisch dazu.', 'easy-product'), count($back_images)));
                    ?>
                </p>
            <?php endif; ?>
        </section>

        <section class="ep-panel">
            <h2 class="ep-panel__title"><?php esc_html_e('Sichtbarkeit', 'easy-product'); ?></h2>
            <div class="ep-choices">
                <label class="ep-choice">
                    <input type="radio" name="status" value="draft" <?php checked(!$is_online); ?>>
                    <span><strong><?php esc_html_e('Entwurf', 'easy-product'); ?></strong>
                    <?php esc_html_e('Noch nicht im Shop', 'easy-product'); ?></span>
                </label>
                <label class="ep-choice">
                    <input type="radio" name="status" value="publish" <?php checked($is_online); ?>>
                    <span><strong><?php esc_html_e('Online', 'easy-product'); ?></strong>
                    <?php esc_html_e('Im Shop zu kaufen', 'easy-product'); ?></span>
                </label>
            </div>
            <?php if (!$is_new && Originals::is_sold($product)) : ?>
                <p class="ep-field__hint"><?php esc_html_e('Verkauft. Das Bild erscheint nicht mehr im Shop, die Seite ist über ihren Link noch erreichbar.', 'easy-product'); ?></p>
            <?php endif; ?>
        </section>

        <button type="submit" class="ep-button ep-button--primary ep-button--block" data-ep-submit>
            <?php esc_html_e('Speichern', 'easy-product'); ?>
        </button>
    </aside>
</form>

<template data-ep-gallery-item>
    <div class="ep-photo has-image">
        <img class="ep-photo__image" alt="">
        <button type="button" class="ep-photo__remove" data-ep-remove aria-label="<?php esc_attr_e('Foto entfernen', 'easy-product'); ?>">×</button>
        <input type="hidden" name="gallery_ids[]" value="">
    </div>
</template>

<?php if (Suggestions::is_available()) : ?>
    <dialog class="ep-dialog" data-ep-suggestion aria-labelledby="ep-suggestion-title">
        <form method="dialog">
            <h2 class="ep-dialog__title" id="ep-suggestion-title"><?php esc_html_e('Vorschlag von Claude', 'easy-product'); ?></h2>
            <p class="ep-note" data-ep-suggestion-mode></p>

            <section class="ep-dialog__part">
                <label class="ep-check"><input type="checkbox" checked data-ep-take="title"> <?php esc_html_e('Titel übernehmen', 'easy-product'); ?></label>
                <p class="ep-dialog__value" data-ep-suggestion-title></p>
            </section>
            <section class="ep-dialog__part">
                <label class="ep-check"><input type="checkbox" checked data-ep-take="tags"> <?php esc_html_e('Schlagwörter übernehmen', 'easy-product'); ?></label>
                <p class="ep-chips" data-ep-suggestion-tags></p>
            </section>
            <section class="ep-dialog__part">
                <label class="ep-check"><input type="checkbox" checked data-ep-take="description"> <?php esc_html_e('Beschreibung übernehmen', 'easy-product'); ?></label>
                <div class="ep-dialog__preview" data-ep-suggestion-description></div>
            </section>

            <footer class="ep-dialog__actions">
                <button type="submit" value="cancel" class="ep-button ep-button--secondary"><?php esc_html_e('Verwerfen', 'easy-product'); ?></button>
                <button type="submit" value="apply" class="ep-button ep-button--primary"><?php esc_html_e('Auswahl übernehmen', 'easy-product'); ?></button>
            </footer>
        </form>
    </dialog>
<?php endif; ?>

<div class="ep-toast" data-ep-toast role="status" aria-live="polite" hidden></div>
