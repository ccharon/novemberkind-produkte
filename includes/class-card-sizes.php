<?php

declare(strict_types=1);

namespace NovemberkindProdukte;

defined('ABSPATH') || exit;

/**
 * Karten in A6 und wahlweise A4 als Variantenprodukt mit dem Attribut „Größe“.
 * A4 wird beim Abschalten nur deaktiviert (Status „private“), nie gelöscht.
 */
final class CardSizes
{
    public const ATTRIBUTE = 'Größe';
    public const A6 = 'A6';
    public const A4 = 'A4';
    // Reihenfolge der Varianten und Endung ihrer Artikelnummer (A000123-1, -2)
    private const POSITIONS = [self::A6 => 1, self::A4 => 2];

    /** Maße in cm im Querformat: Breite, Höhe */
    private const DIMENSIONS = [self::A6 => ['15', '10.5'], self::A4 => ['29.7', '21']];

    /**
     * Schlüssel des Attributs „Größe“, wie WooCommerce ihn an Varianten speichert.
     */
    public static function attribute_key(): string
    {
        return sanitize_title(self::ATTRIBUTE);
    }

    /**
     * Ob ein Variantenprodukt die Größen A6/A4 hat (auch bei Karten aus der Zeit vor dem Plugin).
     */
    public static function has_sizes(\WC_Product $product): bool
    {
        if (!$product instanceof \WC_Product_Variable) {
            return false;
        }
        foreach ($product->get_attributes() as $attribute) {
            if ($attribute instanceof \WC_Product_Attribute && $attribute->get_name() === self::ATTRIBUTE && in_array(self::A4, $attribute->get_options(), true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Variante einer Karte für eine Größe (A6 oder A4), falls vorhanden.
     */
    public static function variation(\WC_Product $product, string $size): ?\WC_Product_Variation
    {
        foreach ($product->get_children() as $child_id) {
            $variation = wc_get_product($child_id);
            if ($variation instanceof \WC_Product_Variation && ($variation->get_attributes()[self::attribute_key()] ?? '') === $size) {
                return $variation;
            }
        }

        return null;
    }

    /**
     * Ob eine Karte gerade auch in A4 angeboten wird.
     */
    public static function a4_enabled(\WC_Product $product): bool
    {
        $a4 = self::has_sizes($product) ? self::variation($product, self::A4) : null;

        return $a4 !== null && $a4->get_status('edit') === 'publish';
    }

    /**
     * Breite und Höhe in cm für eine Größe und ein Format.
     *
     * @return array{0: string, 1: string}
     */
    public static function dimensions(string $size, string $format): array
    {
        [$width, $height] = self::DIMENSIONS[$size];

        return $format === 'hoch' ? [$height, $width] : [$width, $height];
    }

    /**
     * Aktuelle Werte für das Formular.
     *
     * @return array{price: string, stock: string, price_a4: string, stock_a4: string}
     */
    public static function values(\WC_Product $product, string $default_a4_price): array
    {
        $stock = static fn(?\WC_Product $item): string => $item && $item->managing_stock() ? (string) $item->get_stock_quantity() : '';
        if (!self::has_sizes($product)) {
            return [
                'price'    => (string) $product->get_regular_price('edit'),
                'stock'    => $stock($product),
                'price_a4' => $default_a4_price,
                'stock_a4' => '',
            ];
        }

        $a6 = self::variation($product, self::A6);
        $a4 = self::variation($product, self::A4);

        return [
            'price'    => $a6 ? (string) $a6->get_regular_price('edit') : '',
            'stock'    => $stock($a6),
            'price_a4' => $a4 ? (string) $a4->get_regular_price('edit') : $default_a4_price,
            'stock_a4' => $stock($a4),
        ];
    }

    /**
     * Legt Attribut und Varianten an oder aktualisiert sie. Das Produkt muss schon gespeichert sein.
     *
     * @param array{price: string, stock: ?int, price_a4: ?string, stock_a4: ?int, sale: ?string, sale_a4: ?string} $values
     */
    public function apply(\WC_Product_Variable $product, string $format, bool $with_a4, array $values, bool $sku_changed): void
    {
        if (!self::has_sizes($product)) {
            $attribute = new \WC_Product_Attribute();
            $attribute->set_name(self::ATTRIBUTE);
            $attribute->set_options([self::A6, self::A4]);
            $attribute->set_visible(true);
            $attribute->set_variation(true);
            $product->set_attributes([$attribute]);
            $product->set_default_attributes([self::attribute_key() => self::A6]);
            $product->set_manage_stock(false);
            $product->save();
        }

        foreach (self::POSITIONS as $size => $position) {
            $variation = self::variation($product, $size);
            $is_new    = $variation === null;
            if ($is_new) {
                if ($size === self::A4 && !$with_a4) {
                    continue;
                }
                $variation = new \WC_Product_Variation();
                $variation->set_parent_id($product->get_id());
                $variation->set_attributes([self::attribute_key() => $size]);
                $variation->set_menu_order($position);
                $variation->set_tax_class('parent');
            }
            if ($is_new || $sku_changed) {
                $variation->set_sku($product->get_sku() . '-' . $position);
            }

            if ($size === self::A4 && !$with_a4) {
                // Deaktivieren statt löschen, damit Preis und Bestand beim Wiedereinschalten erhalten sind
                $variation->set_status('private');
                $variation->save();
                continue;
            }

            [$width, $height] = self::dimensions($size, $format);
            $price = $size === self::A6 ? $values['price'] : (string) $values['price_a4'];
            $stock = $size === self::A6 ? $values['stock'] : $values['stock_a4'];
            $sale  = $size === self::A6 ? $values['sale'] : $values['sale_a4'];
            $variation->set_status('publish');
            $variation->set_regular_price($price);
            if ($sale !== null) {
                $variation->set_sale_price($sale);
            }
            $variation->set_width(ProductType::to_shop_unit($width));
            $variation->set_height(ProductType::to_shop_unit($height));
            $variation->set_manage_stock($stock !== null);
            $variation->set_stock_quantity($stock);
            if ($stock === null) {
                $variation->set_stock_status('instock');
            }
            $variation->save();
        }

        \WC_Product_Variable::sync($product->get_id());
    }
}
