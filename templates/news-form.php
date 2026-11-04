<?php

/**
 * Formular einer Neuigkeit: Titel, Beitragsbild, Inhalt im Blockeditor und Sichtbarkeit.
 *
 * @var array{id: int, title: string, blocks: string, image_id: int, status: string, date: int, modified: int, url: string}|null $news
 */

declare(strict_types=1);

namespace NovemberkindProdukte;

defined('ABSPATH') || exit;

$is_new      = $news === null;
$news_status = in_array($news['status'] ?? 'draft', News::STATUSES, true) ? ($news['status'] ?? 'draft') : 'draft';
$image_id    = $news['image_id'] ?? 0;
$publish_at  = $news_status === 'future' ? $news['date'] : null;

$form_back_url   = App::news_url();
$form_back_label = __('← Alle Neuigkeiten', 'novemberkind-produkte');
$form_eyebrow    = __('Neuigkeit', 'novemberkind-produkte');
$form_title      = $is_new ? __('Neue Neuigkeit', 'novemberkind-produkte') : $news['title'];
$form_badge      = $is_new ? null : News::badge($news_status);
include __DIR__ . '/form-header.php';
?>

<form class="nkp-simple-form nkp-simple-form--wide" data-nkp-simple-form data-nkp-save="novemberkind_produkte_save_news" novalidate>
    <input type="hidden" name="id" value="<?php echo esc_attr((string) ($news['id'] ?? 0)); ?>">

    <?php if (!$is_new && $news_status === 'publish') : ?>
        <p class="nkp-note">
            <a href="<?php echo esc_url($news['url']); ?>" target="_blank" rel="noopener"><?php esc_html_e('Im Shop ansehen', 'novemberkind-produkte'); ?></a>
        </p>
    <?php endif; ?>

    <div class="nkp-simple-form__fields">
        <section class="nkp-panel">
            <label class="nkp-field">
                <span class="nkp-field__label"><?php esc_html_e('Titel', 'novemberkind-produkte'); ?></span>
                <input type="text" name="title" required autocomplete="off" maxlength="<?php echo esc_attr((string) News::TITLE_MAX_LENGTH); ?>" value="<?php echo esc_attr($news['title'] ?? ''); ?>"
                       placeholder="<?php esc_attr_e('z. B. Neue Herbstkarten sind da', 'novemberkind-produkte'); ?>">
                <?php Html::field_error('title'); ?>
            </label>
        </section>

        <section class="nkp-panel">
            <h2 class="nkp-panel__title"><?php esc_html_e('Beitragsbild', 'novemberkind-produkte'); ?></h2>
            <p class="nkp-field__hint"><?php esc_html_e('Erscheint auf der Startseite neben dem Titel und oben auf der Seite der Neuigkeit.', 'novemberkind-produkte'); ?></p>
            <div class="nkp-photo nkp-photo--main nkp-news-photo <?php echo $image_id ? 'has-image' : ''; ?>" data-nkp-main-photo>
                <label class="nkp-dropzone" data-nkp-dropzone>
                    <input type="file" accept="image/*" class="screen-reader-text" data-nkp-file>
                    <?php if ($image_id) : ?>
                        <?php echo wp_get_attachment_image($image_id, 'medium', false, ['class' => 'nkp-photo__image']); ?>
                    <?php else : ?>
                        <img class="nkp-photo__image" alt="" hidden>
                    <?php endif; ?>
                    <span class="nkp-dropzone__hint">
                        <?php esc_html_e('Foto auswählen', 'novemberkind-produkte'); ?>
                        <small><?php esc_html_e('oder hierher ziehen', 'novemberkind-produkte'); ?></small>
                    </span>
                </label>
                <button type="button" class="nkp-photo__remove" data-nkp-remove aria-label="<?php esc_attr_e('Foto entfernen', 'novemberkind-produkte'); ?>">×</button>
                <input type="hidden" name="image_id" value="<?php echo esc_attr((string) $image_id); ?>">
            </div>
        </section>

        <section class="nkp-panel">
            <h2 class="nkp-panel__title"><?php esc_html_e('Inhalt', 'novemberkind-produkte'); ?></h2>
            <div class="nkp-block-editor" data-nkp-block-editor></div>
            <script type="application/json" data-nkp-block-source><?php echo wp_json_encode(['blocks' => $news['blocks'] ?? '', 'legacy' => ''], JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE); ?></script>
            <input type="hidden" name="blocks" value="">
            <?php Html::field_error('content'); ?>
        </section>

        <section class="nkp-panel">
            <h2 class="nkp-panel__title"><?php esc_html_e('Sichtbarkeit', 'novemberkind-produkte'); ?></h2>
            <div class="nkp-choices">
                <label class="nkp-choice">
                    <input type="radio" name="status" value="draft" <?php checked($news_status, 'draft'); ?>>
                    <span><strong><?php esc_html_e('Entwurf', 'novemberkind-produkte'); ?></strong>
                    <?php esc_html_e('Noch nicht im Shop', 'novemberkind-produkte'); ?></span>
                </label>
                <label class="nkp-choice">
                    <input type="radio" name="status" value="future" <?php checked($news_status, 'future'); ?>>
                    <span><strong><?php esc_html_e('Geplant', 'novemberkind-produkte'); ?></strong>
                    <?php esc_html_e('Erscheint zum gewählten Zeitpunkt automatisch', 'novemberkind-produkte'); ?></span>
                </label>
                <?php
                Html::moment('publish', __('Online ab', 'novemberkind-produkte'), $publish_at, '00:00', [
                    'class'      => 'nkp-field--schedule',
                    'attributes' => ['data-nkp-show-for' => 'status:future', 'hidden' => $news_status !== 'future'],
                ]);
                ?>
                <label class="nkp-choice">
                    <input type="radio" name="status" value="publish" <?php checked($news_status, 'publish'); ?>>
                    <span><strong><?php esc_html_e('Online', 'novemberkind-produkte'); ?></strong>
                    <?php esc_html_e('Auf der Startseite zu sehen', 'novemberkind-produkte'); ?></span>
                </label>
            </div>
        </section>
    </div>

    <div class="nkp-simple-form__actions">
        <?php if (!$is_new) : ?>
            <?php Html::delete_button('novemberkind_produkte_delete_news', __('Die Neuigkeit verschwindet aus dem Shop und kommt in den Papierkorb von WordPress. Löschen?', 'novemberkind-produkte')); ?>
        <?php endif; ?>
        <button type="submit" class="nkp-button nkp-button--primary" data-nkp-submit><?php esc_html_e('Speichern', 'novemberkind-produkte'); ?></button>
    </div>
</form>
