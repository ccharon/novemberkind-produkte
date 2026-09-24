<?php

declare(strict_types=1);

namespace NovemberkindProdukte;

defined('ABSPATH') || exit;

/**
 * Findet Kategorien, Versandklassen, Lieferzeiten und Fotos im Shop über ihren Namen,
 * damit im Code keine IDs der Testumgebung stehen.
 */
final class ShopData
{
    /**
     * IDs aller Kategorien eines Pfads wie ['Physische Produkte', 'Buttons']. Fehlende werden angelegt.
     *
     * @param string[] $path
     * @return int[]
     */
    public static function category_ids(array $path): array
    {
        $ids    = [];
        $parent = 0;
        foreach ($path as $name) {
            $terms = get_terms([
                'taxonomy'   => 'product_cat',
                'name'       => $name,
                'parent'     => $parent,
                'hide_empty' => false,
                'fields'     => 'ids',
            ]);
            if (is_array($terms) && $terms !== []) {
                $parent = (int) $terms[0];
            } else {
                $created = wp_insert_term($name, 'product_cat', ['parent' => $parent]);
                if (is_wp_error($created)) {
                    break;
                }
                $parent = (int) $created['term_id'];
            }
            $ids[] = $parent;
        }

        return $ids;
    }

    public static function shipping_class_id(string $name): int
    {
        $term = get_term_by('name', $name, 'product_shipping_class');

        return $term instanceof \WP_Term ? $term->term_id : 0;
    }

    /**
     * Slug der Germanized-Lieferzeit oder '' ohne Germanized.
     */
    public static function delivery_time_slug(string $name): string
    {
        if (!taxonomy_exists('product_delivery_time')) {
            return '';
        }
        $term = get_term_by('name', $name, 'product_delivery_time');

        return $term instanceof \WP_Term ? $term->slug : '';
    }

    /**
     * Sucht ein Foto der Mediathek über den Anfang seines Dateinamens, z. B. „Saugnapf-gross“.
     */
    public static function attachment_id(string $file_name): int
    {
        static $cache = [];
        if ($file_name === '') {
            return 0;
        }
        if (empty($cache[$file_name])) {
            $ids = get_posts([
                'post_type'      => 'attachment',
                'post_status'    => 'inherit',
                'posts_per_page' => 1,
                'orderby'        => 'ID',
                'order'          => 'ASC',
                'fields'         => 'ids',
                'meta_query'     => [[ // phpcs:ignore WordPress.DB.SlowDBQuery -- nur beim Speichern
                    'key'     => '_wp_attached_file',
                    'value'   => '/' . $file_name,
                    'compare' => 'LIKE',
                ]],
            ]);
            $cache[$file_name] = (int) ($ids[0] ?? 0);
        }

        return $cache[$file_name];
    }

    /**
     * Nächste freie Artikelnummer im Format A000123.
     */
    public static function next_sku(): string
    {
        $highest = 0;
        $ids     = wc_get_products(['limit' => -1, 'status' => 'any', 'type' => ['simple', 'variable', 'grouped', 'external'], 'return' => 'ids']);
        foreach ($ids as $id) {
            $product = wc_get_product($id);
            if ($product && preg_match('/^A(\d{6})$/', $product->get_sku('edit'), $match)) {
                $highest = max($highest, (int) $match[1]);
            }
        }

        return sprintf('A%06d', $highest + 1);
    }
}
