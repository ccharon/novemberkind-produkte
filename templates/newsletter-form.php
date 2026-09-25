<?php

/**
 * Formular einer Newsletter-Ausgabe.
 *
 * @var array{id: int, subject: string, preheader: string, content: string, products: int[], status: string, scheduled: int, recipients: int, sent: int, failed: int, finished: int, modified: int}|null $issue
 * @var array<int, array{id: int, name: string, sku: string}> $products
 * @var int    $recipients bestätigte Abonnenten
 * @var int    $remaining  noch nicht verschickt
 * @var string $from       Absender
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

$field_error = static function (string $field): void {
    printf('<span class="nkp-field__error" data-error-for="%s" hidden></span>', esc_attr($field));
};
$status_labels = [
    'draft'     => __('Entwurf', 'novemberkind-produkte'),
    'scheduled' => __('Geplant', 'novemberkind-produkte'),
    'sending'   => __('Wird verschickt', 'novemberkind-produkte'),
    'sent'      => __('Verschickt', 'novemberkind-produkte'),
];
$badge = ['draft' => 'draft', 'scheduled' => 'campaign-planned', 'sending' => 'campaign-running', 'sent' => 'campaign-ended'];
?>
<a class="nkp-back" href="<?php echo esc_url(App::newsletter_url()); ?>"><?php esc_html_e('← Alle Newsletter', 'novemberkind-produkte'); ?></a>

<header class="nkp-header">
    <div>
        <p class="nkp-eyebrow"><?php esc_html_e('Newsletter', 'novemberkind-produkte'); ?></p>
        <h1><?php echo $is_new ? esc_html__('Neuer Newsletter', 'novemberkind-produkte') : esc_html($issue['subject']); ?></h1>
    </div>
    <?php if (!$is_new) : ?>
        <span class="nkp-badge nkp-badge--<?php echo esc_attr($badge[$issue_status]); ?>"><?php echo esc_html($status_labels[$issue_status]); ?></span>
    <?php endif; ?>
</header>

<form class="nkp-campaign-form" data-nkp-simple-form data-nkp-save="novemberkind_produkte_save_newsletter" novalidate
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
                Newsletters::BATCH_SIZE
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

    <fieldset class="nkp-campaign-form__fields" <?php disabled($is_locked); ?>>
        <section class="nkp-panel">
            <label class="nkp-field">
                <span class="nkp-field__label"><?php esc_html_e('Betreff', 'novemberkind-produkte'); ?></span>
                <input type="text" name="subject" required autocomplete="off" maxlength="<?php echo esc_attr((string) Newsletters::SUBJECT_MAX_LENGTH); ?>" value="<?php echo esc_attr($issue['subject'] ?? ''); ?>"
                       placeholder="<?php esc_attr_e('z. B. Neue Herbstkarten sind da', 'novemberkind-produkte'); ?>">
                <?php $field_error('subject'); ?>
            </label>
            <label class="nkp-field">
                <span class="nkp-field__label"><?php esc_html_e('Vorschauzeile', 'novemberkind-produkte'); ?></span>
                <input type="text" name="preheader" autocomplete="off" maxlength="<?php echo esc_attr((string) Newsletters::PREHEADER_MAX_LENGTH); ?>" value="<?php echo esc_attr($issue['preheader'] ?? ''); ?>">
                <span class="nkp-field__hint"><?php esc_html_e('Erscheint im Posteingang grau neben oder unter dem Betreff.', 'novemberkind-produkte'); ?></span>
                <?php $field_error('preheader'); ?>
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
                </div>
                <div class="nkp-editor__content nkp-editor__content--mail" contenteditable="<?php echo $is_locked ? 'false' : 'true'; ?>" role="textbox" aria-multiline="true"
                     aria-labelledby="nkp-newsletter-content-label" data-nkp-editor><?php echo wp_kses_post($issue['content'] ?? ''); ?></div>
            </div>
            <input type="hidden" name="content" value="">
            <?php $field_error('content'); ?>
        </section>

        <section class="nkp-panel">
            <h2 class="nkp-panel__title"><?php esc_html_e('Produkte zeigen', 'novemberkind-produkte'); ?></h2>
            <p class="nkp-field__hint"><?php esc_html_e('Erscheinen unter dem Text mit Foto, Name und Preis zum Zeitpunkt des Versands.', 'novemberkind-produkte'); ?></p>
            <div class="nkp-picker">
                <input type="search" class="nkp-picker__filter" data-nkp-product-filter autocomplete="off"
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
                <fieldset class="nkp-field nkp-moment" data-nkp-show-for="send:scheduled" <?php echo $send === 'scheduled' ? '' : 'hidden'; ?>>
                    <legend class="nkp-field__label"><?php esc_html_e('Versand am', 'novemberkind-produkte'); ?></legend>
                    <div class="nkp-moment__inputs">
                        <input type="date" name="send_date" value="<?php echo esc_attr($scheduled ? wp_date('Y-m-d', $scheduled) : ''); ?>" aria-label="<?php esc_attr_e('Versand, Datum', 'novemberkind-produkte'); ?>">
                        <input type="time" name="send_time" step="60" value="<?php echo esc_attr($scheduled ? wp_date('H:i', $scheduled) : '09:00'); ?>" aria-label="<?php esc_attr_e('Versand, Uhrzeit', 'novemberkind-produkte'); ?>">
                    </div>
                </fieldset>
                <?php $field_error('send'); ?>
                <p class="nkp-field__hint">
                    <?php
                    /* translators: %s: Absender */
                    echo esc_html(sprintf(__('Absender: %s', 'novemberkind-produkte'), $from));
                    ?>
                </p>
            </section>
        <?php endif; ?>
    </fieldset>

    <?php if (!$is_locked) : ?>
        <div class="nkp-campaign-form__actions">
            <button type="button" class="nkp-button nkp-button--secondary" data-nkp-test="novemberkind_produkte_test_newsletter"><?php esc_html_e('Testmail an mich', 'novemberkind-produkte'); ?></button>
            <button type="submit" class="nkp-button nkp-button--primary" data-nkp-submit><?php esc_html_e('Speichern', 'novemberkind-produkte'); ?></button>
        </div>
    <?php endif; ?>
</form>

<div class="nkp-toast" data-nkp-toast role="status" aria-live="polite" hidden></div>
