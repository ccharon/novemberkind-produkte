<?php

/**
 * Liste der Newsletter-Ausgaben.
 *
 * @var array<int, array{id: int, subject: string, preheader: string, content: string, products: int[], status: string, scheduled: int, recipients: int, sent: int, failed: int, finished: int, modified: int}> $issues
 * @var array{confirmed: int, pending: int} $counts
 */

declare(strict_types=1);

namespace NovemberkindProdukte;

defined('ABSPATH') || exit;

$sections = [
    'sending'   => __('Wird gerade verschickt', 'novemberkind-produkte'),
    'scheduled' => __('Geplant', 'novemberkind-produkte'),
    'draft'     => __('Entwürfe', 'novemberkind-produkte'),
    'sent'      => __('Verschickt', 'novemberkind-produkte'),
];
$by_status = array_fill_keys(array_keys($sections), []);
foreach ($issues as $issue) {
    $by_status[$issue['status']][] = $issue;
}
usort($by_status['scheduled'], static fn(array $a, array $b): int => $a['scheduled'] <=> $b['scheduled']);
usort($by_status['sent'], static fn(array $a, array $b): int => $b['finished'] <=> $a['finished']);

$describe = static function (array $issue): string {
    return match ($issue['status']) {
        /* translators: 1: bisher verschickt, 2: Empfänger insgesamt */
        'sending'   => sprintf(__('%1$d von %2$d verschickt', 'novemberkind-produkte'), $issue['sent'] + $issue['failed'], $issue['recipients']),
        /* translators: %s: Datum und Uhrzeit */
        'scheduled' => sprintf(__('geht am %s Uhr raus', 'novemberkind-produkte'), wp_date('d.m.Y H:i', $issue['scheduled'])),
        'sent'      => sprintf(
            /* translators: 1: Datum, 2: Anzahl der Empfänger */
            _n('am %1$s an %2$d Empfänger', 'am %1$s an %2$d Empfänger', $issue['sent'], 'novemberkind-produkte'),
            wp_date('d.m.Y', $issue['finished'] ?: $issue['scheduled']),
            $issue['sent']
        ) . ($issue['failed'] > 0 ? ' · ' . sprintf(
            /* translators: %d: Anzahl */
            _n('%d nicht zustellbar', '%d nicht zustellbar', $issue['failed'], 'novemberkind-produkte'),
            $issue['failed']
        ) : ''),
        /* translators: %s: Datum */
        default     => sprintf(__('zuletzt geändert am %s', 'novemberkind-produkte'), wp_date('d.m.Y', $issue['modified'])),
    };
};
?>
<?php
$list_title   = __('Newsletter', 'novemberkind-produkte');
$list_buttons = [
    /* translators: %d: Anzahl der bestätigten Abonnenten */
    ['url' => App::newsletter_url('abonnenten'), 'label' => sprintf(__('Abonnenten (%d)', 'novemberkind-produkte'), $counts['confirmed']), 'primary' => false],
    ['url' => App::newsletter_url('neu'), 'label' => __('+ Neuer Newsletter', 'novemberkind-produkte'), 'primary' => true],
];
$list_intro   = [sprintf(
    /* translators: %s: Absender, z. B. Novemberkind <psst@novemberkind.art> */
    __('Der Newsletter geht an alle, die ihre Anmeldung bestätigt haben. Absender und Antwortadresse: %s.', 'novemberkind-produkte'),
    NewsletterMail::from_label()
)];
include __DIR__ . '/list-header.php';
?>

<?php if ($issues === []) : ?>
    <p class="nkp-empty"><?php esc_html_e('Noch kein Newsletter geschrieben.', 'novemberkind-produkte'); ?></p>
<?php endif; ?>

<?php foreach ($sections as $section_status => $heading) : ?>
    <?php if ($by_status[$section_status] === []) {
        continue;
    } ?>
    <section class="nkp-entry-group">
        <h2 class="nkp-group__title"><?php echo esc_html($heading); ?></h2>
        <ul class="nkp-entries">
            <?php foreach ($by_status[$section_status] as $issue) : ?>
                <li class="nkp-entry<?php echo $section_status === 'sent' ? ' nkp-entry--ended' : ''; ?>">
                    <a class="nkp-entry__link nkp-newsletter__link" href="<?php echo esc_url(App::newsletter_url($issue['id'])); ?>">
                        <span class="nkp-entry__main">
                            <strong class="nkp-entry__name"><?php echo esc_html($issue['subject']); ?></strong>
                            <span class="nkp-entry__meta"><?php echo esc_html($describe($issue)); ?></span>
                        </span>
                    </a>
                </li>
            <?php endforeach; ?>
        </ul>
    </section>
<?php endforeach; ?>
