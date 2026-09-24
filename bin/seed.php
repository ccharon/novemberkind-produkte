<?php

/**
 * Beispieldaten für die Testumgebung, angelehnt an den echten Shop.
 * Aufruf über bin/setup (wp eval-file). Läuft nur, wenn es noch keine Produkte gibt.
 */

use NovemberkindProdukte\ImageProcessor;
use NovemberkindProdukte\ProductService;
use NovemberkindProdukte\ProductType;
use NovemberkindProdukte\ShopData;

defined('ABSPATH') || exit(1);

/**
 * Einfaches Testbild: Farbverlauf mit Kreis, als PNG in einer temporären Datei.
 */
function ep_seed_image(string $name, array $top, array $bottom, array $dot, int $width = 1200, int $height = 1200): string
{
    $path  = trailingslashit(wp_upload_dir()['path']) . wp_unique_filename(wp_upload_dir()['path'], $name . '.png');
    $image = imagecreatetruecolor($width, $height);
    for ($y = 0; $y < $height; $y += 4) {
        $t = $y / $height;
        $c = array_map(static fn($a, $b) => (int) ($a + ($b - $a) * $t), $top, $bottom);
        imagefilledrectangle($image, 0, $y, $width, $y + 4, imagecolorallocate($image, ...$c));
    }
    imagefilledellipse($image, (int) ($width / 2), (int) ($height / 2), (int) ($width * 0.5), (int) ($width * 0.5), imagecolorallocate($image, ...$dot));
    imagepng($image, $path);
    imagedestroy($image);

    return $path;
}

$processor = new ImageProcessor();

// Rückseitenfotos der Buttons, benannt wie im echten Shop
foreach (ProductType::get('button')->config('variations')['options'] as $i => $option) {
    if (!ShopData::attachment_id($option['image'])) {
        $processor->import(ep_seed_image($option['image'], [235, 232, 226], [205, 200, 192], [120 + $i * 12, 120, 130], 800, 800), $option['name']);
    }
}

if (wc_get_products(['limit' => 1, 'status' => 'any', 'return' => 'ids']) !== []) {
    echo "Produkte vorhanden, keine Beispielprodukte angelegt.\n";
    return;
}

wp_set_current_user(get_user_by('login', 'admin')->ID);
$service = new ProductService();

$samples = [
    ['button', ['motif' => 'Auf Abenteuerreise', 'tags' => 'Mädchen, bär, abenteuer', 'stock' => '5', 'status' => 'publish'], [[196, 150, 120], [128, 190, 196], [176, 85, 58]]],
    ['button', ['motif' => 'Charly Chamäleon', 'tags' => 'tier, chamäleon', 'stock' => '4', 'status' => 'publish'], [[214, 230, 190], [150, 190, 140], [90, 140, 80]]],
    ['card', ['motif' => 'Rubie Weltentdeckerin', 'format' => 'quer', 'stock' => '5', 'status' => 'publish'], [[240, 220, 200], [200, 160, 140], [60, 90, 140]]],
    ['card', ['motif' => 'Anouk Flowerpower', 'format' => 'hoch', 'stock' => '2', 'status' => 'publish'], [[250, 230, 235], [230, 180, 190], [220, 120, 60]]],
    ['sticker', ['motif' => 'Otter und Baby', 'finish' => 'matt', 'width' => '7,5', 'height' => '6,5', 'tags' => 'otter, baby', 'stock' => '9', 'status' => 'publish'], [[210, 225, 235], [160, 190, 210], [110, 80, 60]]],
    ['sticker', ['motif' => 'Lotta klein aber oho!', 'finish' => 'glaenzend', 'width' => '8', 'height' => '5,5', 'stock' => '3', 'status' => 'draft'], [[245, 235, 210], [225, 200, 160], [200, 70, 90]]],
    ['bookmark', ['motif' => 'Kaffee to go', 'width' => '7', 'tags' => 'kaffee', 'stock' => '6', 'status' => 'publish'], [[235, 220, 200], [190, 160, 130], [100, 60, 40]]],
    ['original', ['motif' => 'Herbstwald im Nebel', 'technique' => 'Aquarell', 'width' => '24', 'height' => '32', 'year' => gmdate('Y'),
        'text' => "Ein Morgen im Oktober, als der Nebel noch zwischen den Buchen hing.\n\nGemalt auf 300g Aquarellpapier, nicht gerahmt.", 'price' => '180', 'status' => 'publish'], [[230, 225, 215], [180, 160, 130], [190, 110, 50]]],
];

foreach ($samples as [$key, $data, $colors]) {
    $type  = ProductType::get($key);
    $image = $processor->import(ep_seed_image(sanitize_title($data['motif']), ...$colors), $data['motif']);
    $data += ['sku' => ShopData::next_sku(), 'price' => $type->config('price'), 'image_id' => is_int($image) ? $image : 0];
    $product = $service->save($type, $data);
    echo is_wp_error($product) ? "Fehler bei {$data['motif']}: " . print_r($product->get_error_data(), true) : "Angelegt: {$product->get_name()}\n";
}

// Ein Produkt ohne Vorlage, das in der WooCommerce-Maske bearbeitet wird
$mug = new WC_Product_Simple();
$mug->set_name('Tasse: Inga und Lisa');
$mug->set_regular_price('14.95');
$mug->set_category_ids(ShopData::category_ids(['Physische Produkte', 'Tassen']));
$mug->set_status('publish');
$mug->save();
echo "Angelegt: {$mug->get_name()}\n";
