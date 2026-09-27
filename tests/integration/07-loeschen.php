<?php

/**
 * Integrationstests: Löschen von Aktionen, Gutscheinen, Newslettern und Abwesenheiten.
 * Teil von tests/integration.php, läuft im selben Gültigkeitsbereich und nutzt dessen Hilfsfunktionen und Variablen.
 */

use NovemberkindProdukte\Absences;
use NovemberkindProdukte\Campaigns;
use NovemberkindProdukte\Coupons;
use NovemberkindProdukte\Newsletters;

defined('ABSPATH') || exit(1);

section('Löschen');
$at = static fn(string $key, int $timestamp): array => ["{$key}_date" => wp_date('Y-m-d', $timestamp), "{$key}_time" => wp_date('H:i', $timestamp)];

$campaigns = new Campaigns();
$campaign  = $campaigns->save(['name' => 'Löschtest', 'percent' => '10', ...$at('start', time()), ...$at('end', time() + DAY_IN_SECONDS), 'scope' => 'all']);
check('laufende Aktion lässt sich nicht löschen', is_wp_error($campaigns->delete($campaign['id'])) && Campaigns::get($campaign['id']) !== null);
$ended_campaign = $campaigns->end($campaign['id']);
check('gerade beendete Aktion zählt für die 30-Tage-Regel', Campaigns::is_recent_reference($ended_campaign));
check('beendete Aktion gelöscht', $campaigns->delete($campaign['id']) === true && Campaigns::get($campaign['id']) === null && get_post($campaign['id']) === null);
check('gelöschte Aktion liefert Fehler', is_wp_error($campaigns->delete($campaign['id'])));

$coupons = new Coupons();
$coupon  = $coupons->save(['code' => 'LOESCH-TEST', 'kind' => 'percent', 'percent' => '10']);
check('aktiver Gutschein lässt sich nicht löschen', is_wp_error($coupons->delete($coupon['id'])) && Coupons::get($coupon['id']) !== null);
$coupons->set_active($coupon['id'], false);
check('deaktivierter Gutschein landet im Papierkorb', $coupons->delete($coupon['id']) === true && get_post_status($coupon['id']) === 'trash');
check('Code ist danach wieder frei', !is_wp_error($again = $coupons->save(['code' => 'LOESCH-TEST', 'kind' => 'percent', 'percent' => '5'])));
$foreign_coupon = new WC_Coupon();
$foreign_coupon->set_code('FREMD-LOESCH');
$foreign_coupon->set_status('draft');
$foreign_coupon->save();
check('Gutschein aus WooCommerce lässt sich nicht löschen', is_wp_error($coupons->delete($foreign_coupon->get_id())) && get_post_status($foreign_coupon->get_id()) === 'draft');
foreach ([$coupon['id'], $again['id'] ?? 0, $foreign_coupon->get_id()] as $id) {
    $id && wp_delete_post($id, true);
}

$newsletters = new Newsletters();
$draft = $newsletters->save(['subject' => 'Löschtest', 'content' => '<p>x</p>', 'send' => 'draft']);
check('Newsletter-Entwurf gelöscht', $newsletters->delete($draft['id']) === true && Newsletters::get($draft['id']) === null);
$planned_issue = $newsletters->save(['subject' => 'Löschtest geplant', 'content' => '<p>x</p>', 'send' => 'scheduled', ...$at('send', time() + DAY_IN_SECONDS)]);
check('geplanter Newsletter gelöscht und abgesagt', $newsletters->delete($planned_issue['id']) === true
    && !as_has_scheduled_action(Newsletters::HOOK_START, ['id' => $planned_issue['id']], 'novemberkind-produkte'));
$sending = $newsletters->save(['subject' => 'Löschtest Versand', 'content' => '<p>x</p>', 'send' => 'draft']);
update_post_meta($sending['id'], Newsletters::META, ['status' => 'sending'] + get_post_meta($sending['id'], Newsletters::META, true));
check('Newsletter im Versand lässt sich nicht löschen', is_wp_error($newsletters->delete($sending['id'])) && Newsletters::get($sending['id']) !== null);
wp_delete_post($sending['id'], true);

$absences = new Absences();
$absence  = $absences->save(['start' => 'later', ...$at('start_at', time() + 30 * DAY_IN_SECONDS), 'end' => 'open']);
check('geplante Abwesenheit lässt sich nicht löschen', is_wp_error($absences->delete($absence['id'])) && Absences::get($absence['id']) !== null);
$absences->end($absence['id']);
check('beendete Abwesenheit gelöscht', $absences->delete($absence['id']) === true && Absences::get($absence['id']) === null && get_post($absence['id']) === null);

// Jede Löschfunktion lehnt Einträge anderer Arten ab und lässt sie unverändert
$other_campaign = $campaigns->save(['name' => 'Fremd-ID-Test', 'percent' => '10', ...$at('start', time()), ...$at('end', time() + DAY_IN_SECONDS), 'scope' => 'all']);
$campaigns->end($other_campaign['id']);
$other_absence = $absences->save(['start' => 'later', ...$at('start_at', time() + 40 * DAY_IN_SECONDS), 'end' => 'open']);
$absences->end($other_absence['id']);
$other_issue = $newsletters->save(['subject' => 'Fremd-ID-Test', 'content' => '<p>x</p>', 'send' => 'draft']);
$other_coupon = $coupons->save(['code' => 'FREMD-ID-TEST', 'kind' => 'percent', 'percent' => '10']);
$coupons->set_active($other_coupon['id'], false);
$other_product = wc_get_products(['limit' => 1, 'return' => 'ids'])[0];
$other_backup = wp_insert_post(['post_type' => 'novemberkind_backup', 'post_status' => 'private', 'post_parent' => $other_product, 'post_title' => 'Fremd-ID-Test']);
$entries = ['Aktion' => [$campaigns, $other_campaign['id']], 'Abwesenheit' => [$absences, $other_absence['id']], 'Newsletter' => [$newsletters, $other_issue['id']], 'Gutschein' => [$coupons, $other_coupon['id']]];
$untouched = true;
foreach ($entries as $name => [$deleter]) {
    $targets = [$other_product, $other_backup, ...array_map(static fn(array $entry): int => $entry[1], array_diff_key($entries, [$name => true]))];
    foreach ($targets as $target) {
        $status = get_post_status($target);
        $untouched = $untouched && is_wp_error($deleter->delete($target)) && get_post_status($target) === $status;
    }
}
check('Löschfunktionen lehnen Produkte, Sicherungen und Einträge anderer Bereiche ab', $untouched);
foreach ([$other_campaign['id'], $other_absence['id'], $other_issue['id'], $other_coupon['id'], $other_backup] as $id) {
    wp_delete_post($id, true);
}
