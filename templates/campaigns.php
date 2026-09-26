<?php

/**
 * Liste der Rabattaktionen: laufende, geplante, beendete.
 *
 * @var array<int, array{id: int, name: string, percent: int, start: int, end: int, scope: string, categories: int[], products: int[]}> $campaigns
 */

declare(strict_types=1);

namespace NovemberkindProdukte;

defined('ABSPATH') || exit;

$sections = [
    'running' => __('Laufen gerade', 'novemberkind-produkte'),
    'planned' => __('Geplant', 'novemberkind-produkte'),
    'ended'   => __('Beendet', 'novemberkind-produkte'),
];
$by_status = array_fill_keys(array_keys($sections), []);
foreach ($campaigns as $campaign) {
    $by_status[Campaigns::status($campaign)][] = $campaign;
}
// Geplante in der Reihenfolge ihres Starts
$by_status['planned'] = array_reverse($by_status['planned']);
?>
<?php
$list_title   = __('Aktionen', 'novemberkind-produkte');
$list_buttons = [['url' => App::campaigns_url('neu'), 'label' => __('+ Neue Aktion', 'novemberkind-produkte'), 'primary' => true]];
$list_intro   = [__('Eine Aktion senkt die Preise während ihrer Laufzeit um einen festen Prozentsatz. Die Produkte selbst bleiben unverändert. Produkte mit eigenem Angebotspreis sind ausgenommen, und laufen mehrere Aktionen gleichzeitig, gilt der höchste Rabatt.', 'novemberkind-produkte')];
include __DIR__ . '/list-header.php';
?>

<?php if ($campaigns === []) : ?>
    <p class="nkp-empty"><?php esc_html_e('Noch keine Aktionen angelegt.', 'novemberkind-produkte'); ?></p>
<?php endif; ?>

<?php foreach ($sections as $section_status => $heading) : ?>
    <?php if ($by_status[$section_status] === []) {
        continue;
    } ?>
    <section class="nkp-entry-group">
        <h2 class="nkp-group__title"><?php echo esc_html($heading); ?></h2>
        <ul class="nkp-entries">
            <?php foreach ($by_status[$section_status] as $campaign) : ?>
                <?php $conflicts = $section_status === 'ended' ? ['overlaps' => [], 'reference' => []] : Campaigns::conflicts($campaign); ?>
                <li class="nkp-entry nkp-entry--<?php echo esc_attr($section_status); ?>">
                    <a class="nkp-entry__link" href="<?php echo esc_url(App::campaigns_url($campaign['id'])); ?>">
                        <span class="nkp-entry__percent">−<?php echo esc_html((string) $campaign['percent']); ?> %</span>
                        <span class="nkp-entry__main">
                            <strong class="nkp-entry__name"><?php echo esc_html($campaign['name']); ?></strong>
                            <span class="nkp-entry__meta">
                                <?php
                                echo esc_html(sprintf(
                                    /* translators: 1: Beginn, 2: Ende */
                                    __('%1$s bis %2$s', 'novemberkind-produkte'),
                                    wp_date('d.m.Y H:i', $campaign['start']),
                                    wp_date('d.m.Y H:i', $campaign['end'])
                                ));
                                ?>
                                · <?php echo esc_html(Campaigns::scope_label($campaign)); ?>
                            </span>
                            <?php foreach ($conflicts['overlaps'] as $other) : ?>
                                <span class="nkp-entry__note">
                                    <?php
                                    /* translators: %s: Name der anderen Aktion */
                                    echo esc_html(sprintf(__('Läuft zeitgleich mit „%s“ für teils dieselben Produkte. Es gilt jeweils der höhere Rabatt.', 'novemberkind-produkte'), $other));
                                    ?>
                                </span>
                            <?php endforeach; ?>
                            <?php foreach ($conflicts['reference'] as $other) : ?>
                                <span class="nkp-entry__note nkp-entry__note--warning">
                                    <?php
                                    /* translators: %s: Name der anderen Aktion */
                                    echo esc_html(sprintf(__('„%s“ hat dieselben Produkte in den 30 Tagen davor reduziert. Der durchgestrichene Normalpreis ist dann als Vergleichspreis rechtlich heikel.', 'novemberkind-produkte'), $other));
                                    ?>
                                </span>
                            <?php endforeach; ?>
                        </span>
                    </a>
                </li>
            <?php endforeach; ?>
        </ul>
    </section>
<?php endforeach; ?>
