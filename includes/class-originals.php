<?php

declare(strict_types=1);

namespace NovemberkindProdukte;

defined('ABSPATH') || exit;

/**
 * Unikate verschwinden nach dem Verkauf aus Shop, Kategorie und Suche. Die Produktseite
 * bleibt über ihren Link erreichbar und zeigt „Verkauft“.
 */
final class Originals
{
    private bool $updating = false;

    public function register(): void
    {
        add_action('woocommerce_product_set_stock_status', [$this, 'update_visibility'], 10, 3);
        add_filter('woocommerce_get_availability_text', [$this, 'availability_text'], 10, 2);
    }

    public static function is_sold(\WC_Product $product): bool
    {
        return (bool) ProductType::detect($product)?->is_unique() && !$product->is_in_stock();
    }

    public function update_visibility(int $product_id, string $stock_status, ?\WC_Product $product = null): void
    {
        $product = wc_get_product($product_id);
        if ($this->updating || !$product || !ProductType::detect($product)?->is_unique()) {
            return;
        }

        $visibility = $stock_status === 'outofstock' ? 'hidden' : 'visible';
        if ($product->get_catalog_visibility() === $visibility) {
            return;
        }

        $this->updating = true;
        $product->set_catalog_visibility($visibility);
        $product->save();
        $this->updating = false;
    }

    public function availability_text(string $text, \WC_Product $product): string
    {
        return self::is_sold($product) ? __('Verkauft', 'novemberkind-produkte') : $text;
    }
}
