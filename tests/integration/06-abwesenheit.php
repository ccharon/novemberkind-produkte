<?php

/**
 * Integrationstests: Abwesenheiten mit Hinweis im Shop und angepasster Lieferzeit.
 * Teil von tests/integration.php, läuft im selben Gültigkeitsbereich und nutzt dessen Hilfsfunktionen und Variablen.
 */

use NovemberkindProdukte\Absences;
use NovemberkindProdukte\ProductType;
use NovemberkindProdukte\ShopData;

defined('ABSPATH') || exit(1);

section('Abwesenheiten');
$absences = new Absences();
$at = static fn(string $key, int $timestamp): array => ["{$key}_date" => wp_date('Y-m-d', $timestamp), "{$key}_time" => wp_date('H:i', $timestamp)];
$absence_ids = [];
// Abwesenheiten, die in der Testumgebung von Hand angelegt wurden, zählen hier nicht
Absences::flush();
$manual_absences = array_keys(Absences::all());
add_filter('novemberkind_produkte_absences', $only_test_absences = static fn(array $all): array => array_diff_key($all, array_flip($manual_absences)));
Absences::flush();

$invalid = $absences->save(['start' => 'later', 'end' => 'until', ...$at('end_at', time() - HOUR_IN_SECONDS), 'text' => str_repeat('x', Absences::TEXT_MAX_LENGTH + 1)]);
check('Abwesenheit: Beginn, Ende und Textlänge werden geprüft', is_wp_error($invalid) && array_keys($invalid->get_error_data()) === ['text', 'start_at', 'end_at']);
$late_announce = $absences->save(['start' => 'later', ...$at('start_at', time() + DAY_IN_SECONDS), ...$at('announce', time() + 2 * DAY_IN_SECONDS)]);
check('Abwesenheit: Ankündigung nach dem Beginn abgelehnt', is_wp_error($late_announce) && array_keys($late_announce->get_error_data()) === ['announce']);
check('ohne Abwesenheit kein Hinweis', do_shortcode('[' . Absences::SHORTCODE . ']') === '');

$open = $absences->save(['start' => 'now', 'end' => 'open', 'text' => "Urlaub <b>am Meer</b>\nbis bald"]);
$absence_ids[] = is_array($open) ? $open['id'] : 0;
check('Abwesenheit sofort und offen läuft', is_array($open) && Absences::status($open) === 'running' && $open['end'] === 0);
$notice = do_shortcode('[' . Absences::SHORTCODE . ']');
check('Hinweis mit eigenem Text ohne Tags und mit Zeilenumbruch', str_contains($notice, 'Urlaub am Meer<br />') && !str_contains($notice, '<b>'));
check('Hinweis bei offenem Ende ohne Datum', str_contains($notice, 'sobald ich zurück bin'));

$overlap = $absences->save(['start' => 'later', ...$at('start_at', time() + DAY_IN_SECONDS), 'end' => 'until', ...$at('end_at', time() + 3 * DAY_IN_SECONDS)]);
check('Überschneidung mit offener Abwesenheit abgelehnt', is_wp_error($overlap) && isset($overlap->get_error_data()['start_at']));

if ($germanized) {
    $absence_card = $service->save(ProductType::get('card'), ['sku' => ShopData::next_sku(), 'motif' => 'Abwesenheitstest', 'price' => '2,5', 'format' => 'quer', 'status' => 'publish']);
    $cleanup['products'][] = $absence_card->get_id();
    check('Lieferzeit bei offenem Ende ergänzt', str_contains(wc_gzd_get_product($absence_card)->get_delivery_time_html(), '1-3 Werktage nach meiner Rückkehr'));
    $virtual = new WC_Product_Simple();
    $virtual->set_name('Abwesenheit digital');
    $virtual->set_virtual(true);
    $virtual->set_regular_price('3');
    $virtual->save();
    $cleanup['products'][] = $virtual->get_id();
    check('Lieferzeit digitaler Produkte unverändert', !str_contains(wc_gzd_get_product($virtual)->get_delivery_time_html(), 'Rückkehr'));
}

$back = strtotime('+4 days 10:00', time());
$running = $absences->save(['end' => 'until', ...$at('end_at', $back), 'text' => 'Urlaub'], $open['id']);
check('laufende Abwesenheit bekommt ein Ende, Beginn bleibt', is_array($running) && $running['start'] === $open['start'] && $running['end'] === $back);
check('Hinweis nennt den Versandtag', str_contains(do_shortcode('[' . Absences::SHORTCODE . ']'), 'ab dem ' . wp_date('j. F', $back) . ' verschickt'));
if ($germanized) {
    check('Lieferzeit mit Datum ergänzt', str_contains(wc_gzd_get_product(wc_get_product($absence_card->get_id()))->get_delivery_time_html(), '1-3 Werktage ab ' . wp_date('d.m.', $back)));
}

$ended = $absences->end($open['id']);
check('Jetzt beenden', is_array($ended) && Absences::status($ended) === 'ended' && Absences::current() === null);
check('beendete Abwesenheit lässt sich nicht ändern', is_wp_error($absences->save(['end' => 'open'], $open['id'])));
if ($germanized) {
    check('Lieferzeit nach dem Ende wieder normal', !str_contains(wc_gzd_get_product(wc_get_product($absence_card->get_id()))->get_delivery_time_html(), wp_date('d.m.', $back)));
}

$start = time() + 2 * DAY_IN_SECONDS;
$planned = $absences->save(['start' => 'later', ...$at('start_at', $start), 'end' => 'until', ...$at('end_at', $start + 5 * DAY_IN_SECONDS), ...$at('announce', time() + DAY_IN_SECONDS)]);
$absence_ids[] = is_array($planned) ? $planned['id'] : 0;
check('geplante Abwesenheit', is_array($planned) && Absences::status($planned) === 'planned');
check('vor der Ankündigung kein Hinweis', Absences::current() === null);
check('nach der Ankündigung angekündigt', Absences::current(time() + DAY_IN_SECONDS + 60)['id'] === $planned['id']);
check('Ankündigung nennt den Zeitraum', str_starts_with(Absences::shipping_line($planned), 'Vom ' . wp_date('j. F', $start)));
check('während der Laufzeit aktiv', Absences::current($start + 60)['id'] === $planned['id']);
$cancelled = $absences->end($planned['id']);
check('Nicht starten', is_array($cancelled) && Absences::status($cancelled) === 'ended' && Absences::current($start + 60) === null);

foreach (array_filter($absence_ids) as $id) {
    wp_delete_post($id, true);
}
remove_filter('novemberkind_produkte_absences', $only_test_absences);
Absences::flush();
