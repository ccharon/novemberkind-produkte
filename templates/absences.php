<?php

/**
 * Liste der Abwesenheiten: laufende, geplante, beendete.
 *
 * @var array<int, array{id: int, text: string, start: int, end: int, announce: int}> $absences
 */

declare(strict_types=1);

namespace NovemberkindProdukte;

defined('ABSPATH') || exit;

$sections = [
    'running' => __('Läuft gerade', 'novemberkind-produkte'),
    'planned' => __('Geplant', 'novemberkind-produkte'),
    'ended'   => __('Beendet', 'novemberkind-produkte'),
];
$by_status = array_fill_keys(array_keys($sections), []);
foreach ($absences as $absence) {
    $by_status[Absences::status($absence)][] = $absence;
}
// Geplante in der Reihenfolge ihres Beginns
$by_status['planned'] = array_reverse($by_status['planned']);

$list_title   = __('Abwesenheit', 'novemberkind-produkte');
$list_buttons = [['url' => App::absences_url('neu'), 'label' => __('+ Neue Abwesenheit', 'novemberkind-produkte'), 'primary' => true]];
$list_intro   = [
    __('Während einer Abwesenheit bleibt der Shop geöffnet. Ein Hinweis sagt, wann Bestellungen verschickt werden, und die Lieferzeit bekommt das Datum dazu.', 'novemberkind-produkte'),
    /* translators: %s: Shortcode */
    sprintf(__('Der Hinweis steht in Warenkorb und Kasse und überall, wo der Shortcode %s eingefügt ist.', 'novemberkind-produkte'), '[' . Absences::SHORTCODE . ']'),
];
include __DIR__ . '/list-header.php';
?>

<?php if ($absences === []) : ?>
    <p class="nkp-empty"><?php esc_html_e('Noch keine Abwesenheit geplant.', 'novemberkind-produkte'); ?></p>
<?php endif; ?>

<?php foreach ($sections as $section_status => $heading) : ?>
    <?php if ($by_status[$section_status] === []) {
        continue;
    } ?>
    <section class="nkp-entry-group">
        <h2 class="nkp-group__title"><?php echo esc_html($heading); ?></h2>
        <ul class="nkp-entries">
            <?php foreach ($by_status[$section_status] as $absence) : ?>
                <li class="nkp-entry nkp-entry--<?php echo esc_attr($section_status); ?>">
                    <a class="nkp-entry__link" href="<?php echo esc_url(App::absences_url($absence['id'])); ?>">
                        <span class="nkp-entry__date"><?php echo esc_html(wp_date('d.m.', $absence['start'])); ?></span>
                        <span class="nkp-entry__main">
                            <strong class="nkp-entry__name"><?php echo esc_html(Absences::period_label($absence)); ?></strong>
                            <span class="nkp-entry__meta">
                                <?php echo esc_html($absence['text'] !== '' ? $absence['text'] : Absences::shipping_line($absence)); ?>
                            </span>
                            <?php if ($section_status === 'planned' && $absence['announce'] !== 0) : ?>
                                <span class="nkp-entry__note">
                                    <?php
                                    /* translators: 1: Datum, 2: Uhrzeit */
                                    echo esc_html(sprintf(__('Im Shop angekündigt ab %1$s, %2$s Uhr', 'novemberkind-produkte'), wp_date('d.m.Y', $absence['announce']), wp_date('H:i', $absence['announce'])));
                                    ?>
                                </span>
                            <?php endif; ?>
                        </span>
                    </a>
                </li>
            <?php endforeach; ?>
        </ul>
    </section>
<?php endforeach; ?>
