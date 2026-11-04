<?php

/**
 * Integrationstests: Neuigkeiten als Beiträge der Kategorie „Neuigkeiten“.
 * Teil von tests/integration.php, läuft im selben Gültigkeitsbereich und nutzt dessen Hilfsfunktionen und Variablen.
 */

use NovemberkindProdukte\ImageProcessor;
use NovemberkindProdukte\News;
use NovemberkindProdukte\Time;

defined('ABSPATH') || exit(1);

section('Neuigkeiten');
$news       = new News();
$news_at    = static fn(int $timestamp): array => ['publish_date' => Time::format('Y-m-d', $timestamp), 'publish_time' => Time::format('H:i', $timestamp)];
$paragraph  = static fn(string $text): string => "<!-- wp:paragraph -->\n<p>{$text}</p>\n<!-- /wp:paragraph -->";
$had_category = News::category_id() !== 0;
$news_ids   = [];

$empty = $news->save(['title' => '', 'blocks' => '', 'status' => 'future']);
check('Titel, Inhalt und Zeitpunkt sind Pflicht', is_wp_error($empty) && array_keys($empty->get_error_data()) === ['title', 'content', 'publish']);
check('Zeitpunkt in der Vergangenheit wird abgelehnt', isset($news->save(['title' => 'x', 'blocks' => $paragraph('x'), 'status' => 'future', ...$news_at(time() - HOUR_IN_SECONDS)])->get_error_data()['publish']));

$upload = (new ImageProcessor())->import(make_png(800, 600), 'neuigkeit');
$cleanup['attachments'][] = is_int($upload) ? $upload : 0;
$foreign_image = wp_insert_attachment(['post_title' => 'Logo', 'post_mime_type' => 'image/png', 'post_status' => 'inherit'], make_png(200, 200));
$cleanup['attachments'][] = $foreign_image;

$draft = $news->save([
    'title'    => 'Herbst & neue Karten',
    'blocks'   => $paragraph('Hallo <strong>Herbst</strong><script>alert(1)</script>') . "\n<!-- wp:html -->\n<div>roh</div>\n<!-- /wp:html -->",
    'image_id' => (string) $upload,
    'status'   => 'draft',
]);
$news_ids[] = $draft['id'] ?? 0;
check('Entwurf angelegt, Kategorie angelegt', is_array($draft) && $draft['status'] === 'draft' && News::category_id() !== 0 && has_category(News::category_id(), $draft['id']));
check('nur die Kategorie „Neuigkeiten“', wp_get_post_categories($draft['id']) === [News::category_id()]);
check('Titel mit & bleibt lesbar', $draft['title'] === 'Herbst & neue Karten');
check('Skript und nicht erlaubte Blöcke entfernt, Fett bleibt', str_contains($draft['blocks'], '<strong>Herbst</strong>') && !str_contains($draft['blocks'], '<script') && !str_contains($draft['blocks'], 'wp:html'));
check('eigenes Foto als Beitragsbild mit Titel als Alternativtext', $draft['image_id'] === $upload && get_post_meta($upload, '_wp_attachment_image_alt', true) === 'Herbst & neue Karten');

$online = $news->save(['title' => 'Herbst & neue Karten', 'blocks' => $draft['blocks'], 'image_id' => (string) $upload, 'status' => 'publish'], $draft['id']);
check('Entwurf online gestellt, Datum jetzt', $online['status'] === 'publish' && abs($online['date'] - time()) < MINUTE_IN_SECONDS);
check('in der Liste', in_array($draft['id'], array_column(News::all(), 'id'), true));

$foreign = $news->save(['title' => 'Fremdes Bild', 'blocks' => $paragraph('x'), 'image_id' => (string) $foreign_image, 'status' => 'draft']);
$news_ids[] = $foreign['id'] ?? 0;
check('fremdes Foto wird nicht übernommen', $foreign['image_id'] === 0);
check('Foto entfernen löscht das Beitragsbild', $news->save(['title' => 'Herbst', 'blocks' => $draft['blocks'], 'image_id' => '', 'status' => 'publish'], $draft['id'])['image_id'] === 0);

$when    = (new DateTimeImmutable('+3 days 18:00', Time::zone()))->getTimestamp();
$planned = $news->save(['title' => 'Bald', 'blocks' => $paragraph('bald'), 'status' => 'future', ...$news_at($when)]);
$news_ids[] = $planned['id'] ?? 0;
check('geplant zum gewählten Zeitpunkt', $planned['status'] === 'future' && $planned['date'] === $when && get_post($planned['id'])->post_date_gmt === gmdate('Y-m-d H:i:s', $when));
$now_online = $news->save(['title' => 'Bald', 'blocks' => $paragraph('bald'), 'status' => 'publish'], $planned['id']);
check('geplante direkt online: Datum zurück auf jetzt', $now_online['status'] === 'publish' && $now_online['date'] <= time());

$other_post = wp_insert_post(['post_title' => 'Anderer Beitrag', 'post_content' => 'x', 'post_status' => 'publish']);
check('Beiträge anderer Kategorien fehlen in der Liste und lassen sich nicht ändern', !in_array($other_post, array_column(News::all(), 'id'), true)
    && News::get($other_post) === null && is_wp_error($news->save(['title' => 'x', 'blocks' => $paragraph('x')], $other_post)));
wp_set_post_categories($draft['id'], [News::category_id(), (int) get_option('default_category')]);
$news->save(['title' => 'Herbst', 'blocks' => $draft['blocks'], 'status' => 'publish'], $draft['id']);
check('zusätzliche Kategorien bleiben beim Ändern', count(wp_get_post_categories($draft['id'])) === 2);

check('Löschen legt in den Papierkorb', $news->delete($foreign['id']) === true && get_post_status($foreign['id']) === 'trash' && News::get($foreign['id']) === null);
check('Beitrag anderer Kategorie lässt sich nicht löschen', is_wp_error($news->delete($other_post)) && get_post_status($other_post) === 'publish');

foreach ([...$news_ids, $other_post] as $id) {
    $id && wp_delete_post($id, true);
}
if (!$had_category && News::category_id() !== 0) {
    wp_delete_term(News::category_id(), 'category');
}
