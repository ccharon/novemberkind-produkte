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

    /**
     * Meldet die Hooks für verkaufte Unikate an.
     */
    public function register(): void
    {
        add_action('woocommerce_product_set_stock_status', [$this, 'update_visibility'], 10, 3);
        add_filter('woocommerce_get_availability_text', [$this, 'availability_text'], 10, 2);
    }

    /**
     * Ob ein Unikat verkauft ist (nicht mehr vorrätig).
     */
    public static function is_sold(\WC_Product $product): bool
    {
        return (bool) ProductType::detect($product)?->is_unique() && !$product->is_in_stock();
    }

    /**
     * Nimmt ein verkauftes Unikat aus Shop, Kategorie und Suche und zeigt es wieder, wenn es erneut vorrätig ist.
     * Ohne Typangaben, weil auch andere Plugins diesen Hook auslösen.
     */
    public function update_visibility(mixed $product_id, mixed $stock_status = '', mixed $product = null): void
    {
        $product = is_numeric($product_id) ? wc_get_product((int) $product_id) : null;
        if ($this->updating || !$product || !is_string($stock_status) || !ProductType::detect($product)?->is_unique()) {
            return;
        }

        $visibility = $stock_status === 'outofstock' ? 'hidden' : 'visible';
        if ($product->get_catalog_visibility() === $visibility) {
            return;
        }

        $this->updating = true;
        try {
            $product->set_catalog_visibility($visibility);
            $product->save();
        } finally {
            $this->updating = false;
        }
    }

    /**
     * Zeigt bei verkauften Unikaten „Verkauft“ statt „Nicht vorrätig“. Ohne Typangaben, weil auch andere Plugins
     * diesen Filter auslösen; ein Typfehler würde die Produktseite abbrechen.
     */
    public function availability_text(mixed $text, mixed $product = null): mixed
    {
        return $product instanceof \WC_Product && self::is_sold($product) ? __('Verkauft', 'novemberkind-produkte') : $text;
    }
}
