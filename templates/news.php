<?php

/**
 * Liste der Neuigkeiten: geplante, Entwürfe, online.
 *
 * @var array<int, array{id: int, title: string, blocks: string, image_id: int, status: string, date: int, modified: int, url: string}> $entries
 */

declare(strict_types=1);

namespace NovemberkindProdukte;

defined('ABSPATH') || exit;

$sections = [
    'future'  => __('Geplant', 'novemberkind-produkte'),
    'draft'   => __('Entwürfe', 'novemberkind-produkte'),
    'publish' => __('Online', 'novemberkind-produkte'),
];
$by_status = array_fill_keys(array_keys($sections), []);
foreach ($entries as $entry) {
    $by_status[$entry['status'] === 'publish' || $entry['status'] === 'future' ? $entry['status'] : 'draft'][] = $entry;
}
// Geplante in der Reihenfolge, in der sie erscheinen
$by_status['future'] = array_reverse($by_status['future']);

$describe = static fn(array $entry): string => match ($entry['status']) {
    /* translators: %s: Datum */
    'publish' => sprintf(__('online seit %s', 'novemberkind-produkte'), Time::format('d.m.Y', $entry['date'])),
    /* translators: %s: Datum und Uhrzeit */
    'future'  => sprintf(__('erscheint am %s Uhr', 'novemberkind-produkte'), Time::format('d.m.Y H:i', $entry['date'])),
    /* translators: %s: Datum */
    default   => sprintf(__('zuletzt geändert am %s', 'novemberkind-produkte'), Time::format('d.m.Y', $entry['modified'])),
};

$list_title   = __('Neuigkeiten', 'novemberkind-produkte');
$list_buttons = [['url' => App::news_url('neu'), 'label' => __('+ Neue Neuigkeit', 'novemberkind-produkte'), 'primary' => true]];
$list_intro   = [
    /* translators: %s: Name der Kategorie */
    sprintf(__('Neuigkeiten sind Beiträge der Kategorie „%s“. Auf der Startseite stehen die neuesten oben, ältere sind über die Seitenzahlen erreichbar.', 'novemberkind-produkte'), News::CATEGORY),
];
include __DIR__ . '/list-header.php';
?>

<?php if ($entries === []) : ?>
    <p class="nkp-empty"><?php esc_html_e('Noch keine Neuigkeit geschrieben.', 'novemberkind-produkte'); ?></p>
<?php endif; ?>

<?php foreach ($sections as $section_status => $heading) : ?>
    <?php if ($by_status[$section_status] === []) {
        continue;
    } ?>
    <section class="nkp-entry-group">
        <h2 class="nkp-group__title"><?php echo esc_html($heading); ?></h2>
        <ul class="nkp-entries">
            <?php foreach ($by_status[$section_status] as $entry) : ?>
                <li class="nkp-entry">
                    <a class="nkp-entry__link" href="<?php echo esc_url(App::news_url($entry['id'])); ?>">
                        <span class="nkp-entry__date"><?php echo esc_html(Time::format('d.m.', $section_status === 'draft' ? $entry['modified'] : $entry['date'])); ?></span>
                        <span class="nkp-entry__main">
                            <strong class="nkp-entry__name"><?php echo esc_html($entry['title']); ?></strong>
                            <span class="nkp-entry__meta"><?php echo esc_html($describe($entry)); ?></span>
                        </span>
                    </a>
                </li>
            <?php endforeach; ?>
        </ul>
    </section>
<?php endforeach; ?>
