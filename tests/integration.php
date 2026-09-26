<?php

/**
 * Integrationstests gegen die laufende Testumgebung.
 * Aufruf über bin/test (führt `wp eval-file` aus). Räumt alle angelegten Daten wieder auf.
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

// Die Teile laufen der Reihe nach und teilen sich Variablen wie $service und $cleanup
$tests_dir = dirname(NovemberkindProdukte\PLUGIN_FILE) . '/tests/integration';
require $tests_dir . '/01-grundlagen.php';
require $tests_dir . '/02-produkte.php';
require $tests_dir . '/03-aktionen-gutscheine.php';
require $tests_dir . '/04-newsletter.php';
require $tests_dir . '/05-updater.php';

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
