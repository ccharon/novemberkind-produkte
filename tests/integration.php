<?php

/**
 * Integrationstests gegen die laufende Testumgebung.
 * Aufruf über bin/test (führt `wp eval-file` aus). Räumt alle angelegten Daten wieder auf.
 */

use NovemberkindProdukte\Campaigns;
use NovemberkindProdukte\Coupons;
use NovemberkindProdukte\ImageProcessor;
use NovemberkindProdukte\Originals;
use NovemberkindProdukte\ProductService;
use NovemberkindProdukte\ProductType;
use NovemberkindProdukte\ShopData;

defined('ABSPATH') || exit(1);

$GLOBALS['ep_failures'] = 0;
$cleanup = ['products' => [], 'attachments' => []];

function check(string $label, bool $condition): void
{
    echo ($condition ? "  ✓ " : "  ✗ ") . $label . PHP_EOL;
    $GLOBALS['ep_failures'] += $condition ? 0 : 1;
}

function section(string $title): void
{
    echo PHP_EOL . $title . PHP_EOL;
}

function make_png(int $width, int $height): string
{
    $path  = wp_tempnam('nkp-test') . '.png';
    $image = imagecreatetruecolor($width, $height);
    imagefill($image, 0, 0, imagecolorallocate($image, 176, 85, 58));
    imagepng($image, $path);
    imagedestroy($image);

    return $path;
}

function same_ids(array $a, array $b): bool
{
    sort($a);
    sort($b);

    return $a === $b;
}

function file_name(int $attachment_id): string
{
    return wp_basename((string) get_attached_file($attachment_id));
}

function tag_names(WC_Product $product): array
{
    $names = wp_list_pluck(wc_get_object_terms($product->get_id(), 'product_tag'), 'name');
    sort($names, SORT_STRING | SORT_FLAG_CASE);

    return $names;
}

wp_set_current_user(get_user_by('login', 'shop')->ID);
$service   = new ProductService();
$germanized = function_exists('wc_gzd_get_product');

section('Eingaben einlesen');
foreach (['24,90' => '24.90', '24.90' => '24.90', '1.234,50' => '1234.50', '5' => '5.00', ' 9,5 € ' => '9.50'] as $in => $out) {
    check("Preis „{$in}“ → {$out}", ProductService::parse_price($in) === $out);
}
foreach (['', 'abc', '-3', '1,234'] as $in) {
    check("Preis „{$in}“ wird abgelehnt", ProductService::parse_price($in) === null);
}
check('Maß „7,5“ → 7.5', ProductType::parse_number('7,5') === 7.5);
check('Maß „0“ wird abgelehnt', ProductType::parse_number('0') === null);
check('Maß 10.5 wird als „10,5“ angezeigt', ProductType::format_number('10.5') === '10,5');
check('Schlagwörter ohne Dubletten', ProductService::parse_tags('Otter, tier ,otter,, ') === ['Otter', 'tier']);
check('Motiv aus „Button: Sophie“', ProductType::get('button')->motif_from_name('Button: Sophie') === 'Sophie');
check('Motiv beim Lesezeichen ist der Name', ProductType::get('bookmark')->motif_from_name('Kaffee to go') === 'Kaffee to go');

section('Pflichtfelder');
$errors = static fn($result): array => is_wp_error($result) ? array_keys((array) $result->get_error_data()) : [];
check('Button ohne Motiv und Preis', $errors($service->save(ProductType::get('button'), ['price' => ''])) === ['motif', 'sku', 'price']);
check('Karte ohne Format', in_array('format', $errors($service->save(ProductType::get('card'), ['motif' => 'x', 'price' => '1'])), true));
check('Sticker ohne Oberfläche und Maße', array_values(array_intersect(['finish', 'width', 'height'], $errors($service->save(ProductType::get('sticker'), ['motif' => 'x', 'price' => '1'])))) === ['finish', 'width', 'height']);
check('Original ohne Technik, Jahr, Text und Preis', array_values(array_intersect(['technique', 'year', 'text', 'price'], $errors($service->save(ProductType::get('original'), ['motif' => 'x'])))) === ['technique', 'year', 'text', 'price']);
check('Artikelnummer im falschen Format', in_array('sku', $errors($service->save(ProductType::get('card'), ['sku' => 'B12'])), true));
check('Artikelnummer klein geschrieben wird akzeptiert', !in_array('sku', $errors($service->save(ProductType::get('card'), ['sku' => 'a999998'])), true));
check('Jahr in der Zukunft wird abgelehnt', in_array('year', $errors($service->save(ProductType::get('original'), ['year' => (string) ((int) gmdate('Y') + 1)])), true));

section('Bilder');
$processor = new ImageProcessor();
$source    = make_png(2000, 1500);
$image_id  = $processor->import($source, 'Testbild');
check('Import liefert Attachment-ID', is_int($image_id));
if (is_int($image_id)) {
    $cleanup['attachments'][] = $image_id;
    $meta = wp_get_attachment_metadata($image_id);
    check('Mime-Typ ist WebP', get_post_mime_type($image_id) === 'image/webp');
    check('auf 1024 px verkleinert', ($meta['width'] ?? 0) === 1024 && ($meta['height'] ?? 0) === 768);
    check('Original-PNG gelöscht', !file_exists($source));
    check('Vorschaubilder sind WebP', ($meta['sizes'] ?? []) !== [] && array_reduce(
        $meta['sizes'],
        static fn(bool $ok, array $size): bool => $ok && $size['mime-type'] === 'image/webp',
        true
    ));
}
$gallery_id = $processor->import(make_png(800, 800));
check('kleines Bild bleibt 800 px breit', is_int($gallery_id) && wp_get_attachment_metadata($gallery_id)['width'] === 800);
$cleanup['attachments'][] = $gallery_id;

section('Button anlegen');
$expected_sku = ShopData::next_sku();
$button = $service->save(ProductType::get('button'), [
    'sku'         => $expected_sku,
    'motif'       => 'Test \\"Otter\\"',
    'price'       => '4,50',
    'stock'       => '5',
    'tags'        => 'otter, Tier',
    'status'      => 'draft',
    'image_id'    => (string) $image_id,
    'gallery_ids' => [(string) $gallery_id, (string) $image_id],
]);
check('angelegt', $button instanceof WC_Product_Variable);
if ($button instanceof WC_Product_Variable) {
    $cleanup['products'][] = $button->get_id();
    $backs = $service->variation_image_ids(ProductType::get('button'));
    check('Name mit Motiv, ohne Backslashes', $button->get_name() === 'Button: Test "Otter"');
    check("Artikelnummer {$expected_sku}", $button->get_sku() === $expected_sku);
    check('Kategorien Physische Produkte > Buttons', same_ids($button->get_category_ids(), ShopData::category_ids(['Physische Produkte', 'Buttons'])));
    // Vorhandene Schlagwörter werden unabhängig von der Schreibweise wiederverwendet
    check('nur Motiv-Schlagwörter', array_map('mb_strtolower', tag_names($button)) === ['otter', 'tier']);
    check('Versandklasse Päckchen', $button->get_shipping_class_id() === ShopData::shipping_class_id('Päckchen'));
    check('Gewicht und Maße', $button->get_weight() === '0.02' && [$button->get_length(), $button->get_width(), $button->get_height()] === ['5.9', '5.9', '1']);
    check('Bestand am Hauptprodukt', $button->managing_stock() && $button->get_stock_quantity() === 5);
    check('Galerie: eigene Fotos, dann 8 Rückseiten', count($backs) === 8 && $button->get_gallery_image_ids() === [$gallery_id, ...$backs]);
    check('Fotos nach Artikelnummer benannt', file_name($image_id) === "{$expected_sku}-1-1024.webp" && file_name($gallery_id) === "{$expected_sku}-2-800.webp");
    check('Rückseitenfotos behalten ihren Namen', !str_starts_with(file_name($backs[0]), $expected_sku));
    check('Alternativtext ist der Produktname', get_post_meta($image_id, '_wp_attachment_image_alt', true) === 'Button: Test "Otter"');
    check('Beschreibung nennt das Motiv', str_contains($button->get_description(), '„Test &quot;Otter&quot;“'));

    $children = array_map('wc_get_product', $button->get_children());
    check('8 Varianten', count($children) === 8);
    check('alle zu 4,50 €', array_unique(array_map(static fn($v) => $v->get_regular_price(), $children)) === ['4.50']);
    check('Artikelnummern -1 bis -8', array_map(static fn($v) => $v->get_sku(), $children) === array_map(static fn($i) => "{$expected_sku}-{$i}", range(1, 8)));
    check('Standard ist Sicherheitsnadel', array_values($button->get_default_attributes()) === ['Sicherheitsnadel']);
    if ($germanized) {
        $magnet = array_values(array_filter($children, static fn($v) => in_array('Kleidungsmagnet', $v->get_attributes(), true)))[0];
        check('Sicherheitshinweis am Kleidungsmagnet', str_contains((string) wc_gzd_get_product($magnet)->get_safety_instructions(), 'Herzschrittmacher'));
        check('Lieferzeit 1-3 Werktage', wc_gzd_get_product($button)->get_default_delivery_time_slug() === ShopData::delivery_time_slug('1-3 Werktage'));
        check('Warenkorbtext', html_entity_decode(wp_strip_all_tags((string) wc_gzd_get_product($button)->get_mini_desc())) === 'Button: Test "Otter"');
    }

    section('Button ändern');
    check('Beschreibung aus der Vorlage gilt nicht als angepasst', !ProductType::get('button')->has_custom_description($button));
    $button->set_description('Eigener Text aus der WooCommerce-Maske');
    check('in WooCommerce geänderte Beschreibung gilt als angepasst', ProductType::get('button')->has_custom_description($button));
    $button->set_category_ids([...$button->get_category_ids(), ...ShopData::category_ids(['Physische Produkte', 'Franzi'])]);
    $button->save();
    $updated = $service->save(ProductType::get('button'), [
        'sku'         => $expected_sku,
        'motif'       => 'Test "Otter"',
        'price'       => '5',
        'stock'       => '3',
        'tags'        => 'otter',
        'status'      => 'publish',
        'image_id'    => (string) $image_id,
        'gallery_ids' => [],
        'description' => '<p>Mein <strong>eigener</strong> Text</p><script>alert(1)</script>',
        'description_custom' => '1',
    ], $button->get_id());
    check('gespeichert', $updated instanceof WC_Product_Variable);
    check('eigene Beschreibung aus dem Formular, ohne Skript', $updated->get_description() === '<p>Mein <strong>eigener</strong> Text</p>alert(1)');
    check('eigene Beschreibung ist markiert', ProductType::get('button')->has_custom_description($updated));
    check('zusätzliche Kategorie bleibt erhalten', in_array(ShopData::category_ids(['Physische Produkte', 'Franzi'])[1], $updated->get_category_ids(), true));
    check('neuer Preis bei allen Varianten', array_unique(array_map(static fn($id) => wc_get_product($id)->get_regular_price(), $updated->get_children())) === ['5.00']);
    check('Rückseiten bleiben in der Galerie', $updated->get_gallery_image_ids() === $backs);
    check('Schlagwörter ersetzt', tag_names($updated) === ['otter']);
    check('Artikelnummer unverändert', $updated->get_sku() === $expected_sku);

    $renamed = $service->save(ProductType::get('button'), ['motif' => 'Neuer Otter', 'sku' => $expected_sku, 'price' => '5'], $button->get_id());
    check('ohne eigene Beschreibung wieder die Vorlage', !ProductType::get('button')->has_custom_description($renamed));
    check('neues Motiv erzeugt Name und Beschreibung neu', $renamed->get_name() === 'Button: Neuer Otter' && str_contains($renamed->get_description(), '„Neuer Otter“'));
    $new_sku = ShopData::next_sku();
    $resku   = $service->save(ProductType::get('button'), ['motif' => 'Neuer Otter', 'sku' => $new_sku, 'price' => '5'], $button->get_id());
    check('neue Artikelnummer auch bei den Varianten', $resku->get_sku() === $new_sku && wc_get_product($resku->get_children()[7])->get_sku() === "{$new_sku}-8");
    check('falsche Produktart wird abgelehnt', is_wp_error($service->save(ProductType::get('card'), ['motif' => 'x', 'format' => 'quer', 'price' => '1'], $button->get_id())));
}

section('Dateinamen der Fotos');
$single = $processor->import(make_png(1200, 900), 'IMG_4711');
$photo_card = $service->save(ProductType::get('card'), ['sku' => ShopData::next_sku(), 'motif' => 'Fotokarte', 'format' => 'quer', 'price' => '2,5', 'image_id' => (string) $single]);
$cleanup['products'][] = $photo_card->get_id();
$sku = $photo_card->get_sku();
$old_sizes = wp_get_attachment_metadata($single)['sizes'];
check('ein Foto: Artikelnummer-1024.webp', file_name($single) === "{$sku}-1024.webp");
check('Vorschaubilder unter neuem Namen', str_starts_with(wp_get_attachment_metadata($single)['sizes']['thumbnail']['file'], "{$sku}-1024-"));
check('Foto dem Produkt zugeordnet', wp_get_post_parent_id($single) === $photo_card->get_id());
check('Titel ist der Dateiname ohne Endung', get_the_title($single) === "{$sku}-1024");
$second = $processor->import(make_png(1200, 900), 'IMG_4712');
$photo_card = $service->save(ProductType::get('card'), ['sku' => $sku, 'motif' => 'Fotokarte', 'format' => 'quer', 'price' => '2,5', 'image_id' => (string) $single, 'gallery_ids' => [(string) $second]], $photo_card->get_id());
check('zweites Foto bekommt die 2, das erste bleibt', file_name($single) === "{$sku}-1024.webp" && file_name($second) === "{$sku}-2-1024.webp");
check('keine alten Dateien übrig', glob(dirname((string) get_attached_file($second)) . '/IMG_471*') === []);
$cleanup['attachments'][] = $single;
$cleanup['attachments'][] = $second;

section('Karten in A6 und A4');
$backups = new NovemberkindProdukte\Backups();
$card_a4 = $service->save(ProductType::get('card'), [
    'sku' => ShopData::next_sku(), 'motif' => 'Großkarte', 'format' => 'quer', 'price' => '2,50', 'stock' => '3',
    'a4' => '1', 'price_a4' => '5,00', 'stock_a4' => '1', 'status' => 'publish',
]);
$cleanup['products'][] = $card_a4->get_id();
$a6 = NovemberkindProdukte\CardSizes::variation($card_a4, NovemberkindProdukte\CardSizes::A6);
$a4 = NovemberkindProdukte\CardSizes::variation($card_a4, NovemberkindProdukte\CardSizes::A4);
check('Karte mit A4 ist ein Variantenprodukt mit A6 und A4', $card_a4 instanceof WC_Product_Variable && $a6 && $a4);
check('Preise und Bestände je Größe', $a6->get_regular_price() === '2.50' && $a4->get_regular_price() === '5.00' && $a6->get_stock_quantity() === 3 && $a4->get_stock_quantity() === 1);
check('Artikelnummern -1 (A6) und -2 (A4)', $a6->get_sku() === $card_a4->get_sku() . '-1' && $a4->get_sku() === $card_a4->get_sku() . '-2');
check('Maße A6 15 × 10,5 und A4 29,7 × 21', [$a6->get_width(), $a6->get_height(), $a4->get_width(), $a4->get_height()] === ['15', '10.5', '29.7', '21']);
check('Standard ist A6', array_values($card_a4->get_default_attributes()) === ['A6']);
check('Beschreibung nennt beide Größen', str_contains($card_a4->get_description(), 'Größe A4: 29,7 × 21 cm') && str_contains($card_a4->get_description(), 'Zur A6-Karte gibt es'));
check('wird als Karte erkannt, A4 aktiv', ProductType::detect($card_a4)?->key() === 'card' && NovemberkindProdukte\CardSizes::a4_enabled($card_a4));
check('Formularwerte', NovemberkindProdukte\CardSizes::values($card_a4, '5.00') === ['price' => '2.50', 'stock' => '3', 'price_a4' => '5.00', 'stock_a4' => '1']);
check('ohne Preis A4 kein Speichern', in_array('price_a4', $errors($service->save(ProductType::get('card'), ['sku' => ShopData::next_sku(), 'motif' => 'x', 'format' => 'quer', 'price' => '2', 'a4' => '1'])), true));

$card_off = $service->save(ProductType::get('card'), ['sku' => $card_a4->get_sku(), 'motif' => 'Großkarte', 'format' => 'quer', 'price' => '2,50', 'stock' => '3', 'status' => 'publish'], $card_a4->get_id());
$a4_off = NovemberkindProdukte\CardSizes::variation($card_off, NovemberkindProdukte\CardSizes::A4);
check('A4 aus: Variante nur deaktiviert, nicht gelöscht', $a4_off && $a4_off->get_status() === 'private' && $a4_off->get_regular_price() === '5.00');
check('A4 aus: nur A6 kaufbar, Beschreibung ohne A4', !NovemberkindProdukte\CardSizes::a4_enabled($card_off) && !str_contains($card_off->get_description(), 'A4') && $card_off->get_price() === '2.50');
$card_on = $service->save(ProductType::get('card'), ['sku' => $card_a4->get_sku(), 'motif' => 'Großkarte', 'format' => 'hoch', 'price' => '2,50', 'stock' => '3', 'a4' => '1', 'price_a4' => '6', 'stock_a4' => ''], $card_a4->get_id());
$a4_on = NovemberkindProdukte\CardSizes::variation($card_on, NovemberkindProdukte\CardSizes::A4);
check('A4 wieder an: dieselbe Variante, neuer Preis', $a4_on->get_id() === $a4->get_id() && $a4_on->get_status() === 'publish' && $a4_on->get_regular_price() === '6.00');
check('Hochformat: A4 21 × 29,7, Bestand A4 nicht gezählt', [$a4_on->get_width(), $a4_on->get_height()] === ['21', '29.7'] && !$a4_on->managing_stock());
check('keine zusätzlichen Varianten entstanden', count($card_on->get_children()) === 2);

$simple_card = $service->save(ProductType::get('card'), ['sku' => ShopData::next_sku(), 'motif' => 'Einfachkarte', 'format' => 'quer', 'price' => '2,50', 'stock' => '4', 'image_id' => (string) $gallery_id]);
$cleanup['products'][] = $simple_card->get_id();
$converted = $service->save(ProductType::get('card'), [
    'sku' => $simple_card->get_sku(), 'motif' => 'Einfachkarte', 'format' => 'quer', 'price' => '2,80', 'stock' => '4', 'a4' => '1', 'price_a4' => '5', 'stock_a4' => '2', 'image_id' => (string) $gallery_id,
], $simple_card->get_id());
check('einfache Karte wird bei A4 umgewandelt, gleiche ID, Artikelnummer und Foto', $converted instanceof WC_Product_Variable && $converted->get_id() === $simple_card->get_id() && $converted->get_sku() === $simple_card->get_sku() && (int) $converted->get_image_id() === $gallery_id);
check('umgewandelt: A6 mit Preis und Bestand aus dem Formular', NovemberkindProdukte\CardSizes::values($converted, '5.00') === ['price' => '2.80', 'stock' => '4', 'price_a4' => '5.00', 'stock_a4' => '2']);
check('umgewandelt: Sicherung enthält den einfachen Stand', (json_decode(NovemberkindProdukte\Backups::snapshot($backups->for_product($simple_card->get_id())[0]->ID), true)['product']['regular_price'] ?? '') === '2.50');
foreach ([$card_a4->get_id(), $simple_card->get_id()] as $backup_parent) {
    foreach ($backups->for_product($backup_parent) as $leftover) {
        wp_delete_post($leftover->ID, true);
    }
}

section('Seitenlayout im Theme Divi');
$layout_card = $service->save(ProductType::get('card'), ['sku' => ShopData::next_sku(), 'motif' => 'Layouttest', 'format' => 'quer', 'price' => '2']);
$cleanup['products'][] = $layout_card->get_id();
check('neues Produkt ohne Seitenleiste', $layout_card->get_meta('_et_pb_page_layout') === 'et_no_sidebar' && $layout_card->get_meta('_et_pb_side_nav') === 'off' && $layout_card->get_meta('_et_pb_post_hide_nav') === 'default');
$layout_card->update_meta_data('_et_pb_page_layout', 'et_right_sidebar');
$layout_card->update_meta_data('_et_pb_side_nav', 'on');
$layout_card->save();
$layout_card = $service->save(ProductType::get('card'), ['sku' => $layout_card->get_sku(), 'motif' => 'Layouttest', 'format' => 'quer', 'price' => '2'], $layout_card->get_id());
check('geändertes Produkt wieder ohne Seitenleiste', $layout_card->get_meta('_et_pb_page_layout') === 'et_no_sidebar');
check('übrige Divi-Einstellungen bleiben', $layout_card->get_meta('_et_pb_side_nav') === 'on');
foreach ((new NovemberkindProdukte\Backups())->for_product($layout_card->get_id()) as $leftover) {
    wp_delete_post($leftover->ID, true);
}

section('Karte, Sticker, Lesezeichen');
$next_sku = ShopData::next_sku();
$card = $service->save(ProductType::get('card'), ['sku' => $next_sku, 'motif' => 'Testkarte', 'format' => 'hoch', 'price' => '2,5', 'stock' => '']);
$cleanup['products'][] = $card->get_id();
check('Karte hochkant 10,5 × 15', [$card->get_width(), $card->get_height()] === ['10.5', '15'] && str_contains($card->get_description(), 'Breite: 10,5 cm'));
check('Karte: feste Schlagwörter', tag_names($card) === ['karte', 'matt', 'Papier', 'postkarte']);
check('Karte: Versandklasse Brief', $card->get_shipping_class_id() === ShopData::shipping_class_id('Brief'));
check('Karte: leerer Bestand wird nicht gezählt', !$card->managing_stock());
check('Karte: eingegebene Artikelnummer', $card->get_sku() === $next_sku);
$duplicate = $service->save(ProductType::get('card'), ['sku' => strtolower($next_sku), 'motif' => 'Doppelt', 'format' => 'quer', 'price' => '1']);
check('vergebene Artikelnummer wird abgelehnt', in_array('sku', $errors($duplicate), true) && str_contains($duplicate->get_error_data()['sku'], 'Karte: Testkarte'));

$sticker = $service->save(ProductType::get('sticker'), ['sku' => ShopData::next_sku(), 'motif' => 'Teststicker', 'finish' => 'glaenzend', 'width' => '7,5', 'height' => '6', 'price' => '3']);
$cleanup['products'][] = $sticker->get_id();
check('Sticker glänzend im Text und als Schlagwort', str_contains($sticker->get_description(), 'Glänzender Sticker') && in_array('glänzend', tag_names($sticker), true));
check('Sticker-Maße', [$sticker->get_width(), $sticker->get_height()] === ['7.5', '6'] && str_contains($sticker->get_description(), 'Breite: 7,5 cm'));
$sticker = $service->save(ProductType::get('sticker'), ['sku' => $sticker->get_sku(), 'motif' => 'Teststicker', 'finish' => 'matt', 'width' => '7,5', 'height' => '6', 'price' => '3'], $sticker->get_id());
check('Wechsel auf matt ändert Text und Schlagwort', str_contains($sticker->get_description(), 'Matter Sticker') && in_array('matt', tag_names($sticker), true) && !in_array('glänzend', tag_names($sticker), true));

$bookmark = $service->save(ProductType::get('bookmark'), ['sku' => ShopData::next_sku(), 'motif' => 'Testzeichen', 'width' => '5', 'price' => '4,9']);
$cleanup['products'][] = $bookmark->get_id();
check('Lesezeichen heißt wie das Motiv', $bookmark->get_name() === 'Testzeichen' && $bookmark->get_short_description() === 'Magnetisches Lesezeichen: Testzeichen');
check('Lesezeichen 5 × 12 cm', [$bookmark->get_width(), $bookmark->get_height()] === ['5', '12']);

section('Maßeinheit des Shops');
update_option('woocommerce_dimension_unit', 'in');
$inch = $service->save(ProductType::get('sticker'), ['sku' => ShopData::next_sku(), 'motif' => 'Zollsticker', 'finish' => 'matt', 'width' => '7,5', 'height' => '5', 'price' => '3']);
update_option('woocommerce_dimension_unit', 'cm');
$cleanup['products'][] = $inch->get_id();
check('bei Zoll-Einstellung umgerechnet gespeichert', [$inch->get_width(), $inch->get_height()] === ['2.9528', '1.9685']);
$inch->delete_meta_data(ProductType::META_CONTEXT);
$inch->save();
update_option('woocommerce_dimension_unit', 'in');
$read = ProductType::get('sticker')->context_from_product(wc_get_product($inch->get_id()));
update_option('woocommerce_dimension_unit', 'cm');
check('beim Lesen zurück in cm', [$read['width'], $read['height']] === ['7.5', '5']);
check('Text nennt cm', str_contains($inch->get_description(), 'Breite: 7,5 cm'));

section('Originalzeichnung');
$original = $service->save(ProductType::get('original'), [
    'sku' => ShopData::next_sku(),
    'motif' => 'Testaquarell', 'technique' => 'Aquarell', 'width' => '24', 'height' => '32', 'year' => '2025',
    'text' => "Erster Absatz.\n\nZweiter <b>Absatz</b>.", 'price' => '180', 'status' => 'publish',
]);
$cleanup['products'][] = $original->get_id();
check('Name „Original: Testaquarell“', $original->get_name() === 'Original: Testaquarell');
check('Bestand 1, nur einzeln verkaufbar', $original->get_stock_quantity() === 1 && $original->get_sold_individually());
check('Text in Absätzen, ohne HTML', str_contains($original->get_description(), '<p>Zweiter Absatz.</p>'));
check('Technik, Maße und Jahr im Text', str_contains($original->get_description(), 'Technik: Aquarell') && str_contains($original->get_description(), 'Entstanden: 2025'));
check('Schlagwörter mit Technik', tag_names($original) === ['Aquarell', 'handgemalt', 'Original', 'Unikat']);
check('Kategorie Originalzeichnungen', same_ids($original->get_category_ids(), ShopData::category_ids(['Physische Produkte', 'Originalzeichnungen'])));
check('sichtbar im Katalog', $original->get_catalog_visibility() === 'visible');

wc_update_product_stock($original, 0);
$sold = wc_get_product($original->get_id());
check('verkauft: aus dem Katalog genommen', $sold->get_catalog_visibility() === 'hidden' && Originals::is_sold($sold));
check('verkauft: Hinweis „Verkauft“', $sold->get_availability()['availability'] === 'Verkauft');
check('verkauft: Seite bleibt veröffentlicht', $sold->get_status() === 'publish');
$edited = $service->save(ProductType::get('original'), [
    'sku' => $original->get_sku(),
    'motif' => 'Testaquarell', 'technique' => 'Aquarell', 'width' => '24', 'height' => '32', 'year' => '2025',
    'text' => "Erster Absatz.\n\nZweiter Absatz.", 'price' => '200', 'status' => 'publish',
], $original->get_id());
check('Ändern nach Verkauf lässt den Bestand bei 0', $edited->get_stock_quantity() === 0);
wc_update_product_stock($edited, 1);
check('wieder vorrätig: wieder im Katalog', wc_get_product($original->get_id())->get_catalog_visibility() === 'visible');

section('Erkennung bestehender Produkte');
$legacy = new WC_Product_Simple();
$legacy->set_name('Sticker: Altbestand');
$legacy->set_width('6');
$legacy->set_height('8');
$legacy->set_category_ids(ShopData::category_ids(['Physische Produkte', 'Sticker']));
$legacy->save();
wp_set_object_terms($legacy->get_id(), ['sticker', 'glänzend', 'eule'], 'product_tag');
$cleanup['products'][] = $legacy->get_id();
$legacy = wc_get_product($legacy->get_id());
check('Sticker ohne Plugin-Daten wird erkannt', ProductType::detect($legacy)?->key() === 'sticker');
check('Werte werden aus dem Produkt gelesen', ProductType::get('sticker')->context_from_product($legacy) === ['motif' => 'Altbestand', 'finish' => 'glaenzend', 'width' => '6', 'height' => '8']);
check('Motiv-Schlagwörter ohne feste', ProductService::motif_tags($legacy, ProductType::get('sticker')) === ['eule']);

$variable_card = new WC_Product_Variable();
$variable_card->set_name('Karte: mit Varianten');
$variable_card->set_category_ids(ShopData::category_ids(['Physische Produkte', 'Karten']));
$variable_card->save();
$cleanup['products'][] = $variable_card->get_id();
check('Karte mit Varianten hat keine Vorlage', ProductType::detect($variable_card) === null);

section('Vorschläge von Claude (ohne API-Aufruf)');
$suggestions = new NovemberkindProdukte\Suggestions();
$sent = null;
$fake = static function ($pre, array $message) use (&$sent) {
    $sent = $message;
    return [
        'mode'        => 'neu',
        'title'       => 'Sticker: Eule Emma',
        'description' => '<p>Eine <strong>Eule</strong>.</p><script>alert(1)</script><div class="x">Text</div>',
        'tags'        => ['Eule', 'Vinyl', 'nacht', 'eule', 'Sticker'],
    ];
};
add_filter('novemberkind_produkte_pre_suggestion', $fake, 10, 2);
$result = $suggestions->suggest(ProductType::get('sticker'), [
    'motif' => 'Eule', 'finish' => 'matt', 'width' => '7,5', 'height' => '6',
    'description' => '<p>eule, nachts, niedlich</p>', 'tags' => 'eule', 'image_id' => (string) $image_id,
]);
remove_filter('novemberkind_produkte_pre_suggestion', $fake, 10);
check('Vorschlag geliefert', is_array($result));
check('Titel ohne Produktart', $result['title'] === 'Eule Emma');
check('Beschreibung ohne Skript', !str_contains($result['description'], 'script') && str_contains($result['description'], '<strong>Eule</strong>'));
check('Schlagwörter ohne feste und ohne Dubletten', $result['tags'] === ['eule', 'nacht']);
check('Anfrage enthält Angaben, bisherigen Text und Vorlage', str_contains($sent['text'], "<angaben>\nOberfläche: matt\nMaße: 7,5 × 6 cm") && str_contains($sent['text'], '<p>eule, nachts, niedlich</p>') && str_contains($sent['text'], '<vorlage>'));
check('Anfrage enthält das Foto als WebP', is_string($sent['image']) && $sent['mime'] === 'image/webp');
check('Systemprompt mit Shopname', str_contains($suggestions->system_prompt(), '„' . get_bloginfo('name') . '“'));

$empty = static fn() => ['mode' => 'verbessert', 'title' => '', 'description' => '', 'tags' => []];
add_filter('novemberkind_produkte_pre_suggestion', $empty);
check('leere Antwort wird abgelehnt', is_wp_error($suggestions->suggest(ProductType::get('card'), ['motif' => 'x'])));
remove_filter('novemberkind_produkte_pre_suggestion', $empty);

foreach (['Sticker Set: Wolken im Kopf' => ['Physische Produkte', 'Sticker'], 'Kartenset: Wolken im Kopf' => ['Physische Produkte', 'Karten']] as $set_name => $set_category) {
    $set = new WC_Product_Simple();
    $set->set_name($set_name);
    $set->set_category_ids(ShopData::category_ids($set_category));
    $set->save();
    $cleanup['products'][] = $set->get_id();
    check("„{$set_name}“ hat keine Vorlage", ProductType::detect($set) === null);
}

section('Button aus dem Shop mit doppelt hochgeladener Rückseite');
$dup_path = trailingslashit(get_temp_dir()) . 'Saugnapf-gross-1.png';
copy(make_png(400, 400), $dup_path);
$dup_back  = $processor->import($dup_path);
$own_photo = $processor->import(make_png(900, 900));
$cleanup['attachments'][] = $dup_back;
$cleanup['attachments'][] = $own_photo;
$old_button = new WC_Product_Variable();
$old_button->set_name('Button: Altbestand');
$old_button->set_sku(ShopData::next_sku());
$old_button->set_category_ids(ShopData::category_ids(['Physische Produkte', 'Buttons']));
$old_button->set_gallery_image_ids([$own_photo, $dup_back]);
$old_button->save();
$cleanup['products'][] = $old_button->get_id();
check('Dublette „-1“ wird als Rückseite erkannt', $service->is_variation_image(ProductType::get('button'), $dup_back));
check('eigenes Foto ist keine Rückseite', !$service->is_variation_image(ProductType::get('button'), $own_photo));
$saved_old = $service->save(ProductType::get('button'), [
    'sku' => $old_button->get_sku(), 'motif' => 'Altbestand', 'price' => '4,5', 'gallery_ids' => [(string) $own_photo],
], $old_button->get_id());
check('vorhandene Rückseite bleibt, keine zweite kommt dazu', $saved_old->get_gallery_image_ids() === [$own_photo, $dup_back]);

section('Schutz fremder Medien');
$foreign_path = trailingslashit(wp_upload_dir()['path']) . 'fremdes-logo.png';
copy(make_png(300, 100), $foreign_path);
$foreign = wp_insert_attachment(['post_mime_type' => 'image/png', 'post_title' => 'Logo', 'post_status' => 'inherit'], $foreign_path);
wp_update_attachment_metadata($foreign, wp_generate_attachment_metadata($foreign, $foreign_path));
$cleanup['attachments'][] = $foreign;
$guarded = $service->save(ProductType::get('card'), [
    'sku' => ShopData::next_sku(), 'motif' => 'Schutztest', 'format' => 'quer', 'price' => '2', 'image_id' => (string) $foreign, 'gallery_ids' => [(string) $foreign],
]);
$cleanup['products'][] = $guarded->get_id();
check('fremdes Medium wird nicht übernommen', !$guarded->get_image_id() && $guarded->get_gallery_image_ids() === []);
check('fremdes Medium wird nicht umbenannt', file_name($foreign) === 'fremdes-logo.png' && wp_get_post_parent_id($foreign) === 0);

$legacy_photo = wp_insert_attachment(['post_mime_type' => 'image/png', 'post_title' => 'Altfoto', 'post_status' => 'inherit'], $foreign_path);
$cleanup['attachments'][] = $legacy_photo;
$guarded->set_image_id($legacy_photo);
$guarded->save();
$kept = $service->save(ProductType::get('card'), [
    'sku' => $guarded->get_sku(), 'motif' => 'Schutztest', 'format' => 'quer', 'price' => '2', 'image_id' => (string) $legacy_photo,
], $guarded->get_id());
check('Foto, das schon am Produkt hängt, bleibt', (int) $kept->get_image_id() === $legacy_photo);
check('älteres Foto wird nicht umbenannt', file_name($legacy_photo) === 'fremdes-logo.png');

$sent_image = 'unverändert';
$spy = static function ($pre, array $message) use (&$sent_image) {
    $sent_image = $message['image'];
    return ['mode' => 'neu', 'title' => 'x', 'description' => '<p>x</p>', 'tags' => []];
};
add_filter('novemberkind_produkte_pre_suggestion', $spy, 10, 2);
$suggestions->suggest(ProductType::get('card'), ['motif' => 'x', 'image_id' => (string) $foreign]);
remove_filter('novemberkind_produkte_pre_suggestion', $spy, 10);
check('fremdes Medium geht nicht an Claude', $sent_image === null);

$huge = $processor->import(make_png(9000, 10));
check('Foto mit über 8000 px wird abgelehnt', is_wp_error($huge));

section('Sicherungen');
$backups = new NovemberkindProdukte\Backups();
$backup_button = $service->save(ProductType::get('button'), ['sku' => ShopData::next_sku(), 'motif' => 'Sicherungstest', 'price' => '4,5', 'stock' => '5']);
$cleanup['products'][] = $backup_button->get_id();
check('neues Produkt: keine Sicherung', $backups->for_product($backup_button->get_id()) === []);

$first_variation = wc_get_product($backup_button->get_children()[0]);
$first_variation->set_sale_price('3.99');
$first_variation->save();
foreach (['6', '7', '8', '9'] as $stock) {
    $service->save(ProductType::get('button'), ['sku' => $backup_button->get_sku(), 'motif' => 'Sicherungstest', 'price' => '4,5', 'stock' => $stock], $backup_button->get_id());
}
$kept_backups = $backups->for_product($backup_button->get_id());
check('nach vier Änderungen genau drei Sicherungen', count($kept_backups) === 3);
$newest = json_decode(NovemberkindProdukte\Backups::snapshot($kept_backups[0]->ID), true);
$oldest = json_decode(NovemberkindProdukte\Backups::snapshot($kept_backups[2]->ID), true);
check('neueste Sicherung enthält den Stand vor der letzten Änderung', ($newest['product']['stock_quantity'] ?? null) === 8);
check('älteste behaltene Sicherung ist der Stand mit Bestand 6', ($oldest['product']['stock_quantity'] ?? null) === 6);
check('Sicherung enthält Artikelnummer und Angebotspreis der Variante', ($newest['product']['sku'] ?? '') === $backup_button->get_sku() && ($newest['variations'][0]['sale_price'] ?? '') === '3.99');
check('Speichern lässt den Angebotspreis der Variante stehen', wc_get_product($first_variation->get_id())->get_sale_price() === '3.99');
check('Sicherung enthält 8 Varianten', count($newest['variations'] ?? []) === 8);
check('Sicherung enthält Metadaten und Kategorien', in_array('Buttons', $newest['product']['categories'] ?? [], true) && !empty($newest['product']['meta_data']));
check('Aufräumen hat das Produkt nicht berührt', wc_get_product($backup_button->get_id())->get_stock_quantity() === 9 && count(wc_get_product($backup_button->get_id())->get_children()) === 8);
$type_object = get_post_type_object(NovemberkindProdukte\Backups::POST_TYPE);
check('Sicherungen sind nicht öffentlich', !$type_object->public && !$type_object->publicly_queryable && !$type_object->show_ui && !$type_object->can_export && get_post_status($kept_backups[0]) === 'private');
check('Sicherungen erscheinen nicht in Produktabfragen', !in_array($kept_backups[0]->ID, wc_get_products(['limit' => -1, 'status' => 'any', 'return' => 'ids']), true));

$failed_before = count($backups->for_product($backup_button->get_id()));
$service->save(ProductType::get('button'), ['sku' => $backup_button->get_sku(), 'motif' => '', 'price' => '4,5'], $backup_button->get_id());
check('ungültige Eingaben: keine neue Sicherung', count($backups->for_product($backup_button->get_id())) === $failed_before);

add_filter('wp_insert_post_empty_content', $refuse = static fn($empty, $data) => $data['post_type'] === NovemberkindProdukte\Backups::POST_TYPE ? true : $empty, 10, 2);
$blocked = $service->save(ProductType::get('button'), ['sku' => $backup_button->get_sku(), 'motif' => 'Sollte nicht ankommen', 'price' => '4,5', 'stock' => '1'], $backup_button->get_id());
remove_filter('wp_insert_post_empty_content', $refuse, 10);
check('ohne Sicherung wird nichts gespeichert', is_wp_error($blocked) && wc_get_product($backup_button->get_id())->get_name() === 'Button: Sicherungstest');
foreach ($backups->for_product($backup_button->get_id()) as $leftover) {
    wp_delete_post($leftover->ID, true);
}

section('Geplant online stellen');
$old_timezone = get_option('timezone_string');
update_option('timezone_string', 'Europe/Berlin');
$tomorrow = (new DateTimeImmutable('tomorrow 18:00', wp_timezone()));
$planned_data = ['sku' => ShopData::next_sku(), 'motif' => 'Planungstest', 'price' => '4,5', 'status' => 'future', 'publish_at' => $tomorrow->format('Y-m-d\TH:i')];
$planned = $service->save(ProductType::get('button'), $planned_data);
$cleanup['products'][] = $planned->get_id();
check('geplantes Produkt hat Status „geplant“', get_post_status($planned->get_id()) === 'future');
check('Zeitpunkt in der Zeitzone des Shops', $planned->get_date_created()->getTimestamp() === $tomorrow->getTimestamp());
check('WordPress hat die Veröffentlichung eingeplant', wp_next_scheduled('publish_future_post', [$planned->get_id()]) === $tomorrow->getTimestamp());
$past = $service->save(ProductType::get('button'), ['publish_at' => (new DateTimeImmutable('-1 hour', wp_timezone()))->format('Y-m-d\TH:i')] + $planned_data, $planned->get_id());
check('Zeitpunkt in der Vergangenheit wird abgelehnt', is_wp_error($past) && isset($past->get_error_data()['publish_at']));
$invalid = $service->save(ProductType::get('button'), ['publish_at' => '2030-02-31T10:00'] + $planned_data, $planned->get_id());
check('ungültiges Datum wird abgelehnt', is_wp_error($invalid) && isset($invalid->get_error_data()['publish_at']));
$missing = $service->save(ProductType::get('button'), ['publish_at' => ''] + $planned_data, $planned->get_id());
check('fehlender Zeitpunkt wird abgelehnt', is_wp_error($missing) && isset($missing->get_error_data()['publish_at']));
$now_online = $service->save(ProductType::get('button'), ['status' => 'publish'] + $planned_data, $planned->get_id());
check('sofort online statt geplant', get_post_status($planned->get_id()) === 'publish' && $now_online->get_date_created()->getTimestamp() <= time());
check('keine Veröffentlichung mehr eingeplant', wp_next_scheduled('publish_future_post', [$planned->get_id()]) === false);
update_option('timezone_string', $old_timezone);

section('Rabattaktionen');
$campaigns = new Campaigns();
$local = static fn(int $timestamp): string => wp_date('Y-m-d\TH:i', $timestamp);
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

$invalid = $campaigns->save(['name' => '', 'percent' => '95', 'start' => $local(time()), 'end' => $local(time() - 3600), 'scope' => 'categories']);
check('Aktion: Pflichtfelder und Grenzen werden geprüft', is_wp_error($invalid) && array_keys($invalid->get_error_data()) === ['name', 'percent', 'end', 'categories']);

$sticker_term = ShopData::category_ids(['Physische Produkte', 'Sticker'])[1];
$sticker_campaign = $campaigns->save(['name' => 'Stickerwoche', 'percent' => '20', 'start' => $local(time() - 120), 'end' => $local(time() + 3600), 'scope' => 'categories', 'categories' => [$sticker_term]]);
$campaign_ids[] = $sticker_campaign['id'];
check('Aktion läuft', Campaigns::status($sticker_campaign) === 'running');
check('Sticker kostet 20 % weniger und gilt als Angebot', $fresh($sticker_a)->get_price() === '2.00' && $fresh($sticker_a)->is_on_sale());
check('Normalpreis und gespeicherte Werte bleiben unverändert', $fresh($sticker_a)->get_regular_price() === '2.50' && $fresh($sticker_a)->get_sale_price('edit') === '' && get_post_meta($sticker_a->get_id(), '_price', true) === '2.50');
check('Produkt mit eigenem Angebotspreis ist ausgenommen', $fresh($sticker_b)->get_price() === '1.99');
check('Karte außerhalb der Kategorie bleibt beim Normalpreis', $fresh($aktion_card)->get_price() === '2.50');

$parent_campaign = $campaigns->save(['name' => 'Physisch', 'percent' => '10', 'start' => $local(time() - 120), 'end' => $local(time() + 3600), 'scope' => 'categories', 'categories' => [ShopData::category_ids(['Physische Produkte'])[0]]]);
$campaign_ids[] = $parent_campaign['id'];
check('übergeordnete Kategorie erfasst die Unterkategorien', $fresh($aktion_card)->get_price() === '2.25');
check('bei zwei Aktionen gilt der höhere Rabatt', $fresh($sticker_a)->get_price() === '2.00');
$variation = wc_get_product($aktion_button->get_children()[0]);
$prices = $fresh($aktion_button)->get_variation_prices();
check('Varianten eines Buttons sind reduziert, auch in der Preisspanne', $variation->get_price() === '4.05' && (string) min($prices['price']) === '4.05' && (string) max($prices['regular_price']) === '4.50');
check('beide Aktionen melden die Überschneidung', Campaigns::conflicts($sticker_campaign)['overlaps'] === ['Physisch']);

$single = $campaigns->save(['name' => 'Einzeln', 'percent' => '50', 'start' => $local(time() + 3600), 'end' => $local(time() + 7200), 'scope' => 'products', 'products' => [$sticker_a->get_id()]]);
$campaign_ids[] = $single['id'];
check('geplante Aktion wirkt noch nicht', Campaigns::status($single) === 'planned' && $fresh($sticker_a)->get_price() === '2.00');

$ended = $campaigns->end($sticker_campaign['id']);
check('beendete Aktion wirkt nicht mehr', Campaigns::status($ended) === 'ended' && $fresh($sticker_a)->get_price() === '2.25');
check('beendete Aktion lässt sich nicht mehr ändern', is_wp_error($campaigns->save(['name' => 'Neu', 'percent' => '5', 'start' => $local(time()), 'end' => $local(time() + 60), 'scope' => 'all'], $ended['id'])));
$cancelled = $campaigns->end($single['id']);
check('abgesagte geplante Aktion startet nie', Campaigns::status($cancelled) === 'ended' && $cancelled['end'] <= $cancelled['start']);

$follow_up = $campaigns->save(['name' => 'Nachfolger', 'percent' => '15', 'start' => $local(time() + 120), 'end' => $local(time() + 3600), 'scope' => 'products', 'products' => [$sticker_a->get_id()]]);
$campaign_ids[] = $follow_up['id'];
check('Warnung, wenn dieselben Produkte in den 30 Tagen davor reduziert waren', Campaigns::conflicts($follow_up)['reference'] === ['Stickerwoche']);
check('abgesagte Aktion zählt nicht als Reduzierung', !in_array('Einzeln', Campaigns::conflicts($follow_up)['reference'], true));
$type_object = get_post_type_object(Campaigns::POST_TYPE);
check('Aktionen sind nicht öffentlich', !$type_object->public && !$type_object->publicly_queryable && !$type_object->show_ui && !$type_object->show_in_rest);

foreach ($campaign_ids as $campaign_id) {
    wp_delete_post($campaign_id, true);
}
Campaigns::flush();
check('ohne Aktionen wieder Normalpreis', $fresh($sticker_a)->get_price() === '2.50' && !$fresh($sticker_a)->is_on_sale());

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
$sticker_sale = (new Campaigns())->save(['name' => 'Gutscheintest', 'percent' => '20', 'start' => wp_date('Y-m-d\TH:i', time() - 120), 'end' => wp_date('Y-m-d\TH:i', time() + 3600), 'scope' => 'products', 'products' => [$coupon_sticker->get_id()]]);
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

section('Updates aus GitHub-Releases (ohne echte Anfrage)');
$github = null;
$fake_github = static function ($pre, array $args, string $url) use (&$github) {
    return str_contains($url, 'api.github.com/repos/ccharon/novemberkind-produkte') ? $github : $pre;
};
add_filter('pre_http_request', $fake_github, 10, 3);
$release_json = static fn(string $asset_url) => ['response' => ['code' => 200, 'message' => 'OK'], 'headers' => [], 'body' => wp_json_encode([
    'tag_name' => 'v9.9.9', 'html_url' => 'https://github.com/ccharon/novemberkind-produkte/releases/tag/v9.9.9',
    'body' => "Neu: Testrelease\n<script>x</script>", 'published_at' => '2026-09-24T12:00:00Z',
    'assets' => [['name' => 'novemberkind-produkte.zip', 'browser_download_url' => $asset_url]],
])];
$updater = new NovemberkindProdukte\Updater();
$plugin_file = plugin_basename(NovemberkindProdukte\PLUGIN_FILE);

delete_site_transient('novemberkind_produkte_release');
$github = $release_json('https://github.com/ccharon/novemberkind-produkte/releases/download/v9.9.9/novemberkind-produkte.zip');
$offer = $updater->check(false, ['Version' => '0.1.0', 'RequiresPHP' => '8.1'], $plugin_file);
check('neues Release wird angeboten', is_array($offer) && $offer['version'] === '9.9.9' && str_ends_with($offer['package'], '/v9.9.9/novemberkind-produkte.zip'));
check('andere Plugins bleiben unberührt', $updater->check(false, ['Version' => '1.0'], 'anderes/anderes.php') === false);

$github = ['response' => ['code' => 500, 'message' => 'Fehler'], 'headers' => [], 'body' => ''];
check('Antwort kommt aus dem Zwischenspeicher', $updater->latest_release()['version'] === '9.9.9');

delete_site_transient('novemberkind_produkte_release');
$github = $release_json('https://github.com/fremd/boeses-plugin/releases/download/v9.9.9/novemberkind-produkte.zip');
check('Paket aus fremdem Repository wird abgelehnt', $updater->latest_release() === null && str_contains(implode(' ', $updater->row_notice([], $plugin_file)), 'ohne Plugin-Paket'));

delete_site_transient('novemberkind_produkte_release');
$github = ['response' => ['code' => 404, 'message' => 'Not Found'], 'headers' => [], 'body' => '{}'];
$offline = $updater->check(false, ['Version' => '0.1.0'], $plugin_file);
check('ohne Antwort von GitHub als aktuell gemeldet, ohne Paket', is_array($offline) && $offline['version'] === '0.1.0' && $offline['package'] === '');
check('Hinweis mit Fehlergrund in der Plugin-Liste', str_contains(implode(' ', $updater->row_notice([], $plugin_file)), 'HTTP 404 Not Found'));
check('kein Hinweis bei anderen Plugins', $updater->row_notice([], 'anderes/anderes.php') === []);
delete_site_transient('update_plugins');
wp_update_plugins();
check('WordPress führt das Plugin trotzdem als aktualisierbar', isset(get_site_transient('update_plugins')->no_update[$plugin_file]));

delete_site_transient('novemberkind_produkte_release');
$github = $release_json('https://github.com/ccharon/novemberkind-produkte/releases/download/v9.9.9/novemberkind-produkte.zip');
$details = $updater->details(false, 'plugin_information', (object) ['slug' => 'novemberkind-produkte']);
check('„Details ansehen“ mit Änderungen, ohne Skript', is_object($details) && str_contains($details->sections['changelog'], 'Neu: Testrelease') && !str_contains($details->sections['changelog'], '<script>'));

delete_site_transient('update_plugins');
wp_update_plugins();
$updates = get_site_transient('update_plugins');
check('WordPress meldet das Update in der Plugin-Liste', ($updates->response[$plugin_file]->new_version ?? '') === '9.9.9');
check('nach Erfolg kein Fehlerhinweis', $updater->row_notice([], $plugin_file) === []);
remove_filter('pre_http_request', $fake_github, 10);
delete_site_transient('novemberkind_produkte_release');
delete_site_transient('update_plugins');

check('unbekannte Produkt-ID liefert Fehler', is_wp_error($service->save(ProductType::get('card'), ['motif' => 'x'], 999999)));

foreach ($cleanup['products'] as $id) {
    ($product = wc_get_product($id)) && $product->delete(true);
}
wp_delete_term(ShopData::category_ids(['Physische Produkte', 'Franzi'])[1], 'product_cat');
foreach (array_filter($cleanup['attachments']) as $id) {
    wp_delete_attachment($id, true);
}

$failures = $GLOBALS['ep_failures'];
echo PHP_EOL . ($failures === 0 ? 'Alle Integrationstests bestanden.' : "{$failures} Integrationstest(s) fehlgeschlagen.") . PHP_EOL;
exit($failures === 0 ? 0 : 1);
