<?php

/**
 * Integrationstests: Genaueste Kategorie, Rabattaktionen und Gutscheine.
 * Teil von tests/integration.php, läuft im selben Gültigkeitsbereich und nutzt dessen Hilfsfunktionen und Variablen.
 */

use NovemberkindProdukte\Campaigns;
use NovemberkindProdukte\Coupons;
use NovemberkindProdukte\ImageProcessor;
use NovemberkindProdukte\NewsletterMail;
use NovemberkindProdukte\Newsletters;
use NovemberkindProdukte\NewsletterSignup;
use NovemberkindProdukte\Originals;
use NovemberkindProdukte\ProductService;
use NovemberkindProdukte\ProductType;
use NovemberkindProdukte\ShopData;
use NovemberkindProdukte\Subscribers;

defined('ABSPATH') || exit(1);

section('Genaueste Kategorie');
[$top_id, $middle_id, $bottom_id] = ShopData::category_ids(['Testober', 'Testmitte', 'Testunten']);
$leaf_product = new WC_Product_Simple();
$leaf_product->set_name('Kategorietest');
$leaf_product->set_category_ids([$top_id, $bottom_id]);
$leaf_product->save();
check('Oberkategorie zählt nicht, auch ohne die Ebene dazwischen', ShopData::leaf_categories($leaf_product->get_id()) === [$bottom_id]);
$only_top = new WC_Product_Simple();
$only_top->set_name('Nur oben');
$only_top->set_category_ids([$top_id]);
$only_top->save();
check('nur Oberkategorie zählt als genaueste', ShopData::leaf_categories($only_top->get_id()) === [$top_id]);
$leaf_product->delete(true);
$only_top->delete(true);
foreach ([$bottom_id, $middle_id, $top_id] as $term_id) {
    wp_delete_term($term_id, 'product_cat');
}

section('Rabattaktionen');
$campaigns = new Campaigns();
$when = static fn(string $key, int $timestamp): array => ["{$key}_date" => wp_date('Y-m-d', $timestamp), "{$key}_time" => wp_date('H:i', $timestamp)];
$campaign_ids = [];
// Aktionen, die in der Testumgebung von Hand angelegt wurden, dürfen die Preise hier nicht beeinflussen
Campaigns::flush();
$manual_campaigns = array_keys(Campaigns::all());
add_filter('novemberkind_produkte_campaigns', $only_test_campaigns = static fn(array $all): array => array_diff_key($all, array_flip($manual_campaigns)));
Campaigns::flush();
$sticker_a = $service->save(ProductType::get('sticker'), ['sku' => ShopData::next_sku(), 'motif' => 'Aktionstest', 'price' => '2,5', 'width' => '5', 'height' => '5', 'finish' => 'matt', 'status' => 'publish']);
$sticker_b = $service->save(ProductType::get('sticker'), ['sku' => ShopData::next_sku(), 'motif' => 'Aktionstest mit Angebot', 'price' => '2,5', 'width' => '5', 'height' => '5', 'finish' => 'matt', 'status' => 'publish']);
$aktion_button = $service->save(ProductType::get('button'), ['sku' => ShopData::next_sku(), 'motif' => 'Aktionstest', 'price' => '4,5', 'status' => 'publish']);
$aktion_card = $service->save(ProductType::get('card'), ['sku' => ShopData::next_sku(), 'motif' => 'Aktionstest', 'price' => '2,5', 'format' => 'quer', 'status' => 'publish']);
array_push($cleanup['products'], $sticker_a->get_id(), $sticker_b->get_id(), $aktion_button->get_id(), $aktion_card->get_id());
$sticker_b->set_sale_price('1.99');
$sticker_b->save();
$fresh = static fn(WC_Product $product): WC_Product => wc_get_product($product->get_id());

$invalid = $campaigns->save(['name' => '', 'percent' => '95', ...$when('start', time()), ...$when('end', time() - 3600), 'scope' => 'categories']);
check('Aktion: Pflichtfelder und Grenzen werden geprüft', is_wp_error($invalid) && array_keys($invalid->get_error_data()) === ['name', 'percent', 'end', 'categories']);

$date_only = $campaigns->save(['name' => 'Nur Datum', 'percent' => '5', 'start_date' => wp_date('Y-m-d', time() + DAY_IN_SECONDS), 'end_date' => wp_date('Y-m-d', time() + 2 * DAY_IN_SECONDS), 'scope' => 'products', 'products' => [$sticker_a->get_id()]]);
$campaign_ids[] = $date_only['id'];
check('ohne Uhrzeit beginnt eine Aktion um 0:00 und endet um 23:59', wp_date('H:i', $date_only['start']) === '00:00' && wp_date('H:i', $date_only['end']) === '23:59');
$campaigns->end($date_only['id']);

$sticker_term = ShopData::category_ids(['Physische Produkte', 'Sticker'])[1];
$sticker_campaign = $campaigns->save(['name' => 'Stickerwoche', 'percent' => '20', ...$when('start', time() - 120), ...$when('end', time() + 3600), 'scope' => 'categories', 'categories' => [$sticker_term]]);
$campaign_ids[] = $sticker_campaign['id'];
check('Aktion läuft', Campaigns::status($sticker_campaign) === 'running');
check('Sticker kostet 20 % weniger und gilt als Angebot', $fresh($sticker_a)->get_price() === '2.00' && $fresh($sticker_a)->is_on_sale());
check('Normalpreis und gespeicherte Werte bleiben unverändert', $fresh($sticker_a)->get_regular_price() === '2.50' && $fresh($sticker_a)->get_sale_price('edit') === '' && get_post_meta($sticker_a->get_id(), '_price', true) === '2.50');
check('Produkt mit eigenem Angebotspreis ist ausgenommen', $fresh($sticker_b)->get_price() === '1.99');
check('Karte außerhalb der Kategorie bleibt beim Normalpreis', $fresh($aktion_card)->get_price() === '2.50');

$top_term = ShopData::category_ids(['Physische Produkte'])[0];
$card_term = ShopData::category_ids(['Physische Produkte', 'Karten'])[1];
$only_top = $campaigns->save(['name' => 'Nur Oberkategorie', 'percent' => '30', ...$when('start', time() - 120), ...$when('end', time() + 3600), 'scope' => 'categories', 'categories' => [$top_term]]);
$campaign_ids[] = $only_top['id'];
$direct = new WC_Product_Simple();
$direct->set_name('Direkt in der Oberkategorie');
$direct->set_regular_price('10.00');
$direct->set_category_ids([$top_term]);
$direct->save();
$cleanup['products'][] = $direct->get_id();
check('Oberkategorie allein gilt für Produkte direkt darin, nicht für Unterkategorien', $fresh($direct)->get_price() === '7.00' && $fresh($aktion_card)->get_price() === '2.50');
$campaigns->end($only_top['id']);
$all_terms = array_map(static fn(WP_Term $term): int => $term->term_id, get_terms(['taxonomy' => 'product_cat', 'hide_empty' => false]));
$parent_campaign = $campaigns->save(['name' => 'Physisch', 'percent' => '10', ...$when('start', time() - 120), ...$when('end', time() + 3600), 'scope' => 'categories', 'categories' => array_values(array_diff($all_terms, [$card_term]))]);
$campaign_ids[] = $parent_campaign['id'];
check('abgewählte Unterkategorie ist ausgenommen', $fresh($aktion_card)->get_price() === '2.50' && $fresh($direct)->get_price() === '9.00');
$campaigns->end($parent_campaign['id']);
$parent_campaign = $campaigns->save(['name' => 'Physisch', 'percent' => '10', ...$when('start', time() - 120), ...$when('end', time() + 3600), 'scope' => 'categories', 'categories' => $all_terms]);
$campaign_ids[] = $parent_campaign['id'];
check('Oberkategorie mit allen Unterkategorien erfasst alles', $fresh($aktion_card)->get_price() === '2.25');
$range_card = $service->save(ProductType::get('card'), ['sku' => ShopData::next_sku(), 'motif' => 'Aktionstest A4', 'price' => '2,5', 'format' => 'quer', 'a4' => '1', 'price_a4' => '5', 'status' => 'publish']);
$cleanup['products'][] = $range_card->get_id();
$range_html = html_entity_decode(wp_strip_all_tags($fresh($range_card)->get_price_html()));
check('Preisspanne in einer Aktion: normale Spanne durchgestrichen davor', str_contains($fresh($range_card)->get_price_html(), '<del') && str_contains($range_html, '2,50') && str_contains($range_html, '5,00') && str_contains($range_html, '2,25') && str_contains($range_html, '4,50'));
check('bei zwei Aktionen gilt der höhere Rabatt', $fresh($sticker_a)->get_price() === '2.00');
$variation = wc_get_product($aktion_button->get_children()[0]);
$prices = $fresh($aktion_button)->get_variation_prices();
check('Varianten eines Buttons sind reduziert, auch in der Preisspanne', $variation->get_price() === '4.05' && (string) min($prices['price']) === '4.05' && (string) max($prices['regular_price']) === '4.50');
check('beide Aktionen melden die Überschneidung', in_array('Physisch', Campaigns::conflicts($sticker_campaign)['overlaps'], true));

$single = $campaigns->save(['name' => 'Einzeln', 'percent' => '50', ...$when('start', time() + 3600), ...$when('end', time() + 7200), 'scope' => 'products', 'products' => [$sticker_a->get_id()]]);
$campaign_ids[] = $single['id'];
check('geplante Aktion wirkt noch nicht', Campaigns::status($single) === 'planned' && $fresh($sticker_a)->get_price() === '2.00');

$ended = $campaigns->end($sticker_campaign['id']);
check('beendete Aktion wirkt nicht mehr', Campaigns::status($ended) === 'ended' && $fresh($sticker_a)->get_price() === '2.25');
check('beendete Aktion lässt sich nicht mehr ändern', is_wp_error($campaigns->save(['name' => 'Neu', 'percent' => '5', ...$when('start', time()), ...$when('end', time() + 60), 'scope' => 'all'], $ended['id'])));
$cancelled = $campaigns->end($single['id']);
check('abgesagte geplante Aktion startet nie', Campaigns::status($cancelled) === 'ended' && $cancelled['end'] <= $cancelled['start']);

$follow_up = $campaigns->save(['name' => 'Nachfolger', 'percent' => '15', ...$when('start', time() + 120), ...$when('end', time() + 3600), 'scope' => 'products', 'products' => [$sticker_a->get_id()]]);
$campaign_ids[] = $follow_up['id'];
check('Warnung, wenn dieselben Produkte in den 30 Tagen davor reduziert waren', in_array('Stickerwoche', Campaigns::conflicts($follow_up)['reference'], true));
check('abgesagte Aktion zählt nicht als Reduzierung', !in_array('Einzeln', Campaigns::conflicts($follow_up)['reference'], true));
$type_object = get_post_type_object(Campaigns::POST_TYPE);
check('Aktionen sind nicht öffentlich', !$type_object->public && !$type_object->publicly_queryable && !$type_object->show_ui && !$type_object->show_in_rest);

foreach ($campaign_ids as $campaign_id) {
    wp_delete_post($campaign_id, true);
}
Campaigns::flush();
check('ohne Aktionen wieder Normalpreis', $fresh($sticker_a)->get_price() === '2.50' && !$fresh($sticker_a)->is_on_sale());
check('Preisspanne ohne Aktion ohne Durchstreichung', !str_contains($fresh($range_card)->get_price_html(), '<del'));

section('Gutscheine');
$coupons = new Coupons();
$coupon_errors = $coupons->save(['code' => 'ÄÖ', 'kind' => 'percent', 'percent' => '0', 'expires' => '2020-01-01']);
check('Gutschein: Code, Rabatt und Datum werden geprüft', is_wp_error($coupon_errors) && array_keys($coupon_errors->get_error_data()) === ['code', 'percent', 'expires']);
$percent_coupon = $coupons->save(['code' => 'test-zehn', 'kind' => 'percent', 'percent' => '10', 'expires' => wp_date('Y-m-d', time() + 7 * DAY_IN_SECONDS)]);
$shipping_coupon = $coupons->save(['code' => 'TEST-VERSAND', 'kind' => 'shipping', 'once' => '1']);
$coupon_ids = [$percent_coupon['id'], $shipping_coupon['id']];
check('Code wird groß angezeigt und doppelte Codes abgelehnt', $percent_coupon['code'] === 'TEST-ZEHN' && is_wp_error($coupons->save(['code' => 'Test-Zehn', 'kind' => 'percent', 'percent' => '5'])));
$wc_percent = new WC_Coupon($percent_coupon['id']);
check('Prozent-Gutschein gilt nicht für reduzierte Produkte', $wc_percent->get_discount_type() === 'percent' && (int) $wc_percent->get_amount() === 10 && $wc_percent->get_exclude_sale_items());
check('gültig bis einschließlich: Ende um 0 Uhr am Folgetag', $wc_percent->get_date_expires()->getTimestamp() === (new DateTimeImmutable($percent_coupon['expires'] . ' +1 day', wp_timezone()))->getTimestamp());
check('Versand-Gutschein einmal pro Kunde', $shipping_coupon['kind'] === 'shipping' && $shipping_coupon['once'] && (new WC_Coupon($shipping_coupon['id']))->get_usage_limit_per_user() === 1);

wp_set_current_user(get_user_by('login', 'shop')->ID);
WC()->frontend_includes();
WC()->session = new WC_Session_Handler();
WC()->session->init();
WC()->customer = new WC_Customer(get_current_user_id(), true);
WC()->customer->set_shipping_country('DE');
WC()->cart = new WC_Cart();
$coupon_sticker = $service->save(ProductType::get('sticker'), ['sku' => ShopData::next_sku(), 'motif' => 'Gutscheintest', 'price' => '2,5', 'width' => '5', 'height' => '5', 'finish' => 'matt', 'status' => 'publish']);
$coupon_card = $service->save(ProductType::get('card'), ['sku' => ShopData::next_sku(), 'motif' => 'Gutscheintest', 'price' => '2,5', 'format' => 'quer', 'status' => 'publish']);
array_push($cleanup['products'], $coupon_sticker->get_id(), $coupon_card->get_id());
$coupon_card->set_sale_price('2.00');
$coupon_card->save();
WC()->cart->add_to_cart($coupon_sticker->get_id(), 2);
WC()->cart->add_to_cart($coupon_card->get_id(), 1);
WC()->cart->calculate_totals();
$shipping_before = (float) WC()->cart->get_shipping_total();
WC()->cart->apply_coupon('test-zehn');
WC()->cart->calculate_totals();
check('Warenkorb: 10 % nur auf die nicht reduzierten Sticker', abs((float) WC()->cart->get_discount_total() + (float) WC()->cart->get_discount_tax() - 0.5) < 0.001);
$sticker_sale = (new Campaigns())->save(['name' => 'Gutscheintest', 'percent' => '20', 'start_date' => wp_date('Y-m-d', time() - 120), 'start_time' => wp_date('H:i', time() - 120), 'end_date' => wp_date('Y-m-d', time() + 3600), 'end_time' => wp_date('H:i', time() + 3600), 'scope' => 'products', 'products' => [$coupon_sticker->get_id()]]);
WC()->cart->calculate_totals();
check('Warenkorb: kein Gutschein-Rabatt auf Produkte aus einer Aktion', (float) WC()->cart->get_discount_total() === 0.0);
wp_delete_post($sticker_sale['id'], true);
Campaigns::flush();
WC()->cart->apply_coupon('test-versand');
WC()->cart->calculate_totals();
check('Warenkorb: Versand-Gutschein macht den Versand kostenlos', $shipping_before > 0 && (float) WC()->cart->get_shipping_total() === 0.0);
WC()->cart->empty_cart();
wc_clear_notices();
wp_set_current_user(0);

$off = $coupons->set_active($percent_coupon['id'], false);
check('deaktivierter Gutschein ist ein Entwurf und ungültig', !$off['active'] && get_post_status($percent_coupon['id']) === 'draft');
check('wieder aktiviert', $coupons->set_active($percent_coupon['id'], true)['active']);
$foreign = new WC_Coupon();
$foreign->set_code('fremd-test');
$foreign->set_amount(5);
$foreign->save();
$coupon_ids[] = $foreign->get_id();
check('Gutscheine aus WooCommerce bleiben unberührt', is_wp_error($coupons->set_active($foreign->get_id(), false)) && is_wp_error($coupons->save(['code' => 'FREMD-TEST', 'kind' => 'percent', 'percent' => '5'], $foreign->get_id())));
foreach ($coupon_ids as $coupon_id) {
    wp_delete_post($coupon_id, true);
}
remove_filter('novemberkind_produkte_campaigns', $only_test_campaigns);
Campaigns::flush();
