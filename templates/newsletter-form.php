<?php

/**
 * Formular einer Newsletter-Ausgabe.
 *
 * @var array{id: int, subject: string, preheader: string, content: string, products: int[], status: string, scheduled: int, recipients: int, sent: int, failed: int, finished: int, modified: int}|null $issue
 * @var array<int, array{id: int, name: string, sku: string}> $products
 * @var int    $recipients bestätigte Abonnenten
 * @var int    $remaining  noch nicht verschickt
 * @var string $from       Absender
 * @var string $test_email Empfänger der Testmail
 */

declare(strict_types=1);

namespace NovemberkindProdukte;

defined('ABSPATH') || exit;

$is_new    = $issue === null;
$issue_status = $issue['status'] ?? 'draft';
$is_locked = $issue !== null && Newsletters::is_locked($issue);
$send      = $issue_status === 'scheduled' ? 'scheduled' : 'draft';
$chosen    = $issue['products'] ?? [];
$scheduled = $issue_status === 'scheduled' ? $issue['scheduled'] : null;

// Gewählte Produkte zuerst, damit die Auswahl ohne Scrollen sichtbar ist
usort($products, static fn(array $a, array $b): int => (int) !in_array($a['id'], $chosen, true) <=> (int) !in_array($b['id'], $chosen, true));


$form_back_url   = App::newsletter_url();
$form_back_label = __('← Alle Newsletter', 'novemberkind-produkte');
$form_eyebrow    = __('Newsletter', 'novemberkind-produkte');
$form_title      = $is_new ? __('Neuer Newsletter', 'novemberkind-produkte') : $issue['subject'];
$form_badge      = $is_new ? null : Newsletters::badge($issue_status);
include __DIR__ . '/form-header.php';
?>

<form class="nkp-simple-form" data-nkp-simple-form data-nkp-save="novemberkind_produkte_save_newsletter" novalidate
      data-nkp-confirm-now="<?php
        echo esc_attr(sprintf(
            /* translators: %d: Anzahl der Empfänger */
            _n('Der Newsletter geht jetzt an %d Empfänger. Verschicken?', 'Der Newsletter geht jetzt an %d Empfänger. Verschicken?', $recipients, 'novemberkind-produkte'),
            $recipients
        ));
        ?>">
    <input type="hidden" name="id" value="<?php echo esc_attr((string) ($issue['id'] ?? 0)); ?>">

    <?php if ($issue_status === 'sending') : ?>
        <p class="nkp-note">
            <?php
            echo esc_html(sprintf(
                /* translators: 1: bisher verschickt, 2: Empfänger insgesamt, 3: Päckchengröße */
                __('Bisher %1$d von %2$d verschickt. Es gehen %3$d Mails pro Minute raus, die Seite muss dafür nicht offen bleiben.', 'novemberkind-produkte'),
                $issue['recipients'] - $remaining,
                $issue['recipients'],
                Newsletters::batch_size()
            ));
            ?>
        </p>
    <?php elseif ($issue_status === 'sent') : ?>
        <p class="nkp-note">
            <?php
            echo esc_html(sprintf(
                /* translators: 1: Datum und Uhrzeit, 2: Anzahl der Empfänger */
                _n('Verschickt am %1$s Uhr an %2$d Empfänger.', 'Verschickt am %1$s Uhr an %2$d Empfänger.', $issue['sent'], 'novemberkind-produkte'),
                wp_date('d.m.Y H:i', $issue['finished']),
                $issue['sent']
            ));
            if ($issue['failed'] > 0) {
                echo ' ' . esc_html(sprintf(
                    /* translators: %d: Anzahl */
                    _n('%d Mail konnte nicht zugestellt werden.', '%d Mails konnten nicht zugestellt werden.', $issue['failed'], 'novemberkind-produkte'),
                    $issue['failed']
                ));
            }
            ?>
        </p>
    <?php endif; ?>

    <fieldset class="nkp-simple-form__fields" <?php disabled($is_locked); ?>>
        <section class="nkp-panel">
            <label class="nkp-field">
                <span class="nkp-field__label"><?php esc_html_e('Betreff', 'novemberkind-produkte'); ?></span>
                <input type="text" name="subject" required autocomplete="off" maxlength="<?php echo esc_attr((string) Newsletters::SUBJECT_MAX_LENGTH); ?>" value="<?php echo esc_attr($issue['subject'] ?? ''); ?>"
                       placeholder="<?php esc_attr_e('z. B. Neue Herbstkarten sind da', 'novemberkind-produkte'); ?>">
                <?php Html::field_error('subject'); ?>
            </label>
            <label class="nkp-field">
                <span class="nkp-field__label"><?php esc_html_e('Vorschauzeile', 'novemberkind-produkte'); ?></span>
                <input type="text" name="preheader" autocomplete="off" maxlength="<?php echo esc_attr((string) Newsletters::PREHEADER_MAX_LENGTH); ?>" value="<?php echo esc_attr($issue['preheader'] ?? ''); ?>">
                <span class="nkp-field__hint"><?php esc_html_e('Erscheint im Posteingang grau neben oder unter dem Betreff.', 'novemberkind-produkte'); ?></span>
                <?php Html::field_error('preheader'); ?>
            </label>
        </section>

        <section class="nkp-panel">
            <h2 class="nkp-panel__title" id="nkp-newsletter-content-label"><?php esc_html_e('Text', 'novemberkind-produkte'); ?></h2>
            <div class="nkp-editor">
                <div class="nkp-editor__toolbar">
                    <button type="button" class="nkp-editor__button" data-nkp-command="bold" title="<?php esc_attr_e('Fett', 'novemberkind-produkte'); ?>"><strong>F</strong></button>
                    <button type="button" class="nkp-editor__button" data-nkp-command="italic" title="<?php esc_attr_e('Kursiv', 'novemberkind-produkte'); ?>"><em>K</em></button>
                    <button type="button" class="nkp-editor__button nkp-editor__button--wide" data-nkp-command="heading" title="<?php esc_attr_e('Zwischenüberschrift', 'novemberkind-produkte'); ?>"><?php esc_html_e('Überschrift', 'novemberkind-produkte'); ?></button>
                    <button type="button" class="nkp-editor__button nkp-editor__button--wide" data-nkp-command="link" title="<?php esc_attr_e('Markierten Text verlinken', 'novemberkind-produkte'); ?>"><?php esc_html_e('Link', 'novemberkind-produkte'); ?></button>
                    <?php if (current_user_can('upload_files')) : ?>
                        <button type="button" class="nkp-editor__button nkp-editor__button--wide" data-nkp-command="image" title="<?php esc_attr_e('Foto an der Cursorposition einfügen', 'novemberkind-produkte'); ?>"><?php esc_html_e('Foto', 'novemberkind-produkte'); ?></button>
                        <input type="file" accept="image/*" hidden data-nkp-image-file>
                    <?php endif; ?>
                </div>
                <div class="nkp-editor__content nkp-editor__content--mail" contenteditable="<?php echo $is_locked ? 'false' : 'true'; ?>" role="textbox" aria-multiline="true"
                     aria-labelledby="nkp-newsletter-content-label" data-nkp-editor><?php echo wp_kses_post($issue['content'] ?? ''); ?></div>
            </div>
            <input type="hidden" name="content" value="">
            <?php Html::field_error('content'); ?>
        </section>

        <section class="nkp-panel">
            <h2 class="nkp-panel__title"><?php esc_html_e('Produkte zeigen', 'novemberkind-produkte'); ?></h2>
            <p class="nkp-field__hint"><?php esc_html_e('Erscheinen unter dem Text mit Foto, Name und Preis zum Zeitpunkt des Versands.', 'novemberkind-produkte'); ?></p>
            <div class="nkp-picker">
                <input type="search" class="nkp-picker__filter" data-nkp-product-filter data-nkp-not-dirty autocomplete="off"
                       placeholder="<?php esc_attr_e('Name oder Artikelnummer suchen', 'novemberkind-produkte'); ?>"
                       aria-label="<?php esc_attr_e('Produkte filtern', 'novemberkind-produkte'); ?>">
                <div class="nkp-picker__list">
                    <?php foreach ($products as $item) : ?>
                        <label class="nkp-check" data-nkp-product="<?php echo esc_attr(mb_strtolower($item['name'] . ' ' . $item['sku'])); ?>">
                            <input type="checkbox" name="products[]" value="<?php echo esc_attr((string) $item['id']); ?>" <?php checked(in_array($item['id'], $chosen, true)); ?>>
                            <?php echo esc_html($item['name']); ?>
                            <span class="nkp-picker__count"><?php echo esc_html($item['sku']); ?></span>
                        </label>
                    <?php endforeach; ?>
                </div>
            </div>
        </section>

        <?php if (!$is_locked) : ?>
            <section class="nkp-panel">
                <h2 class="nkp-panel__title"><?php esc_html_e('Versand', 'novemberkind-produkte'); ?></h2>
                <div class="nkp-choices">
                    <label class="nkp-choice">
                        <input type="radio" name="send" value="draft" <?php checked($send, 'draft'); ?>>
                        <span><strong><?php esc_html_e('Entwurf', 'novemberkind-produkte'); ?></strong>
                        <?php esc_html_e('Noch nicht verschicken', 'novemberkind-produkte'); ?></span>
                    </label>
                    <label class="nkp-choice">
                        <input type="radio" name="send" value="now">
                        <span><strong><?php esc_html_e('Jetzt verschicken', 'novemberkind-produkte'); ?></strong>
                        <?php
                        echo esc_html(sprintf(
                            /* translators: %d: Anzahl der Empfänger */
                            _n('An %d Empfänger', 'An %d Empfänger', $recipients, 'novemberkind-produkte'),
                            $recipients
                        ));
                        ?></span>
                    </label>
                    <label class="nkp-choice">
                        <input type="radio" name="send" value="scheduled" <?php checked($send, 'scheduled'); ?>>
                        <span><strong><?php esc_html_e('Geplant', 'novemberkind-produkte'); ?></strong>
                        <?php esc_html_e('An alle, die zu diesem Zeitpunkt angemeldet sind', 'novemberkind-produkte'); ?></span>
                    </label>
                </div>
                <?php
                // Der Fehler zum Versand steht außerhalb, er gilt auch für „Jetzt verschicken“
                Html::moment('send', __('Versand am', 'novemberkind-produkte'), $scheduled, '09:00', [
                    'label'      => __('Versand', 'novemberkind-produkte'),
                    'attributes' => ['data-nkp-show-for' => 'send:scheduled', 'hidden' => $send !== 'scheduled'],
                    'error'      => false,
                ]);
                ?>
                <?php Html::field_error('send'); ?>
                <p class="nkp-field__hint">
                    <?php
                    /* translators: %s: Absender */
                    echo esc_html(sprintf(__('Absender: %s', 'novemberkind-produkte'), $from));
                    ?>
                </p>
                <label class="nkp-field">
                    <span class="nkp-field__label"><?php esc_html_e('Testmail an', 'novemberkind-produkte'); ?></span>
                    <input type="email" name="test_email" autocomplete="email" inputmode="email" value="<?php echo esc_attr($test_email); ?>" data-nkp-not-dirty>
                    <?php Html::field_error('test_email'); ?>
                </label>
            </section>
        <?php endif; ?>
    </fieldset>

    <?php if (!$is_locked) : ?>
        <div class="nkp-simple-form__actions">
            <button type="button" class="nkp-button nkp-button--secondary" data-nkp-test="novemberkind_produkte_test_newsletter"><?php esc_html_e('Testmail schicken', 'novemberkind-produkte'); ?></button>
            <button type="submit" class="nkp-button nkp-button--primary" data-nkp-submit><?php esc_html_e('Speichern', 'novemberkind-produkte'); ?></button>
        </div>
    <?php endif; ?>
</form>
