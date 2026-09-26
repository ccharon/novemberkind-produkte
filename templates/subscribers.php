<?php

/**
 * Liste der Newsletter-Abonnenten.
 *
 * @var array<int, array{id: int, email: string, status: string, created: int, confirmed: int, source: string, token: string}> $subscribers
 */

declare(strict_types=1);

namespace NovemberkindProdukte;

defined('ABSPATH') || exit;

$confirmed = array_filter($subscribers, static fn(array $subscriber): bool => $subscriber['status'] === 'confirmed');
$pending   = count($subscribers) - count($confirmed);
?>
<a class="nkp-back" href="<?php echo esc_url(App::newsletter_url()); ?>"><?php esc_html_e('← Newsletter', 'novemberkind-produkte'); ?></a>

<header class="nkp-header">
    <h1><?php esc_html_e('Abonnenten', 'novemberkind-produkte'); ?></h1>
    <?php if ($confirmed !== []) : ?>
        <div class="nkp-header__actions">
            <a class="nkp-button nkp-button--secondary" href="<?php echo esc_url(Subscribers::csv_url()); ?>"><?php esc_html_e('Als CSV herunterladen', 'novemberkind-produkte'); ?></a>
        </div>
    <?php endif; ?>
</header>

<p class="nkp-note nkp-note--intro">
    <?php
    echo esc_html(sprintf(
        /* translators: 1: bestätigte, 2: unbestätigte Anmeldungen */
        __('%1$d bestätigt, %2$d warten auf Bestätigung. Unbestätigte Anmeldungen werden nach 7 Tagen gelöscht.', 'novemberkind-produkte'),
        count($confirmed),
        $pending
    ));
    ?>
    <br>
    <?php
    echo esc_html(sprintf(
        /* translators: %s: Shortcode */
        __('Das Anmeldeformular erscheint im Shop überall, wo %s steht, zum Beispiel im Footer. An der Kasse gibt es einen eigenen Haken.', 'novemberkind-produkte'),
        '[' . NewsletterSignup::SHORTCODE . ']'
    ));
    ?>
</p>

<?php if ($subscribers === []) : ?>
    <p class="nkp-empty"><?php esc_html_e('Noch niemand angemeldet.', 'novemberkind-produkte'); ?></p>
<?php else : ?>
    <ul class="nkp-entries">
        <?php foreach ($subscribers as $subscriber) : ?>
            <li class="nkp-entry nkp-subscriber<?php echo $subscriber['status'] === 'confirmed' ? '' : ' nkp-entry--ended'; ?>">
                <span class="nkp-entry__main">
                    <strong class="nkp-entry__name"><?php echo esc_html($subscriber['email']); ?></strong>
                    <span class="nkp-entry__meta">
                        <?php
                        echo esc_html($subscriber['status'] === 'confirmed'
                            /* translators: 1: Datum, 2: Formular oder Kasse */
                            ? sprintf(__('seit %1$s · %2$s', 'novemberkind-produkte'), wp_date('d.m.Y', $subscriber['confirmed']), Subscribers::source_label($subscriber['source']))
                            /* translators: %s: Datum */
                            : sprintf(__('wartet seit %s auf Bestätigung', 'novemberkind-produkte'), wp_date('d.m.Y', $subscriber['created'])));
                        ?>
                    </span>
                </span>
                <button type="button" class="nkp-button nkp-button--secondary nkp-button--small" data-nkp-remove-subscriber="<?php echo esc_attr((string) $subscriber['id']); ?>"
                        data-nkp-confirm="<?php
                        /* translators: %s: E-Mail-Adresse */
                        echo esc_attr(sprintf(__('%s austragen und löschen?', 'novemberkind-produkte'), $subscriber['email']));
                        ?>">
                    <?php esc_html_e('Austragen', 'novemberkind-produkte'); ?>
                </button>
            </li>
        <?php endforeach; ?>
    </ul>
<?php endif; ?>
