<?php

declare(strict_types=1);

namespace NovemberkindProdukte;

defined('ABSPATH') || exit;

/**
 * Sicherungen eines Produkts vor jedem Speichern, als nicht öffentlicher Inhaltstyp mit JSON.
 */
final class Backups
{
    public const POST_TYPE = 'novemberkind_backup';
    public const KEEP = 3;
    // Im Metafeld, weil WordPress den Beitragsinhalt als HTML filtert und damit das JSON beschädigen würde
    public const META_SNAPSHOT = '_novemberkind_produkte_snapshot';
    private const DOWNLOAD_ACTION = 'novemberkind_produkte_backup';

    public function register(): void
    {
        add_action('init', [$this, 'register_post_type']);
        add_action('admin_post_' . self::DOWNLOAD_ACTION, [$this, 'download']);
    }

    public function register_post_type(): void
    {
        register_post_type(self::POST_TYPE, [
            'label'               => __('Produkt-Sicherungen', 'novemberkind-produkte'),
            'public'              => false,
            'publicly_queryable'  => false,
            'exclude_from_search' => true,
            'show_ui'             => false,
            'show_in_rest'        => false,
            'show_in_nav_menus'   => false,
            'rewrite'             => false,
            'query_var'           => false,
            'can_export'          => false,
            'supports'            => [],
            // Rechte wie bei Produkten, damit nur Rollen mit Produktrechten an Sicherungen kommen
            'capability_type'     => 'product',
            'map_meta_cap'        => true,
        ]);
    }

    /**
     * Legt eine Sicherung des aktuellen Stands an und behält danach nur die neuesten drei.
     */
    public function create(\WC_Product $product): int|\WP_Error
    {
        $snapshot = [
            'format'     => 1,
            'created'    => gmdate('c'),
            'user'       => wp_get_current_user()->user_login,
            'product'    => self::export($product),
            'variations' => array_values(array_filter(array_map(
                static fn(int $id): ?array => ($variation = wc_get_product($id)) ? self::export($variation) : null,
                $product->get_children()
            ))),
        ];

        $json = wp_json_encode($snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            return new \WP_Error('backup', __('Die Sicherung konnte nicht erstellt werden. Es wurde nichts geändert.', 'novemberkind-produkte'));
        }

        $backup_id = wp_insert_post([
            'post_type'    => self::POST_TYPE,
            'post_status'  => 'private',
            'post_parent'  => $product->get_id(),
            'post_title'   => sprintf('%s (%s)', $product->get_name(), $product->get_sku()),
        ], true);
        if (is_wp_error($backup_id) || !update_post_meta($backup_id, self::META_SNAPSHOT, wp_slash($json))) {
            return new \WP_Error('backup', __('Die Sicherung konnte nicht erstellt werden. Es wurde nichts geändert.', 'novemberkind-produkte'));
        }

        $this->prune($product->get_id());

        return $backup_id;
    }

    public static function snapshot(int $backup_id): string
    {
        return (string) get_post_meta($backup_id, self::META_SNAPSHOT, true);
    }

    /**
     * @return \WP_Post[] neueste zuerst
     */
    public function for_product(int $product_id): array
    {
        return get_posts([
            'post_type'      => self::POST_TYPE,
            'post_status'    => 'private',
            'post_parent'    => $product_id,
            'posts_per_page' => -1,
            'orderby'        => ['date' => 'DESC', 'ID' => 'DESC'],
        ]);
    }

    /**
     * Liste für das Formular: Zeitpunkt und Links zum Ansehen und Herunterladen.
     *
     * @return array<int, array{date: string, view: string, download: string}>
     */
    public function summary(int $product_id): array
    {
        return array_map(static fn(\WP_Post $backup): array => [
            'date'     => (string) get_post_time('j. n. Y, H:i', false, $backup, true),
            'view'     => self::download_url($backup->ID, true),
            'download' => self::download_url($backup->ID),
        ], $this->for_product($product_id));
    }

    public static function download_url(int $backup_id, bool $inline = false): string
    {
        // add_query_arg statt wp_nonce_url, weil die Adresse auch per JavaScript gesetzt wird und dort kein &amp; enthalten darf
        return add_query_arg([
            'action'   => self::DOWNLOAD_ACTION,
            'backup'   => $backup_id,
            'inline'   => $inline ? '1' : '0',
            '_wpnonce' => wp_create_nonce(self::DOWNLOAD_ACTION . '_' . $backup_id),
        ], admin_url('admin-post.php'));
    }

    /**
     * Liefert eine Sicherung als JSON, zum Ansehen im Browser oder als Datei.
     */
    public function download(): void
    {
        $backup_id = absint($_GET['backup'] ?? 0); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Prüfung in der nächsten Zeile
        check_admin_referer(self::DOWNLOAD_ACTION . '_' . $backup_id);

        $backup = get_post($backup_id);
        if (!$backup || $backup->post_type !== self::POST_TYPE || !current_user_can('edit_post', $backup->post_parent)) {
            wp_die(esc_html__('Diese Sicherung gibt es nicht oder sie gehört zu einem Produkt, das du nicht bearbeiten darfst.', 'novemberkind-produkte'), '', ['response' => 403]);
        }

        $product  = wc_get_product($backup->post_parent);
        $filename = sprintf('%s-sicherung-%s.json', $product ? $product->get_sku() : 'produkt', get_post_time('Y-m-d-His', false, $backup));
        $inline   = sanitize_key(wp_unslash($_GET['inline'] ?? '')) === '1'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- oben geprüft

        nocache_headers();
        header('Content-Type: application/json; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        header(sprintf('Content-Disposition: %s; filename="%s"', $inline ? 'inline' : 'attachment', sanitize_file_name($filename)));
        echo self::snapshot($backup->ID); // phpcs:ignore WordPress.Security.EscapeOutput -- JSON mit Content-Type application/json und nosniff
        exit;
    }

    /**
     * Löscht Sicherungen über die neuesten drei hinaus, ausschließlich Einträge des Sicherungstyps.
     */
    private function prune(int $product_id): void
    {
        foreach (array_slice($this->for_product($product_id), self::KEEP) as $old) {
            if (get_post_type($old) === self::POST_TYPE) {
                wp_delete_post($old->ID, true);
            }
        }
    }

    /**
     * Alle Daten eines Produkts oder einer Variante in JSON-tauglicher Form.
     *
     * @return array<string, mixed>
     */
    private static function export(\WC_Product $product): array
    {
        $data = self::plain($product->get_data());
        $data['type']       = $product->get_type();
        $data['categories'] = wp_list_pluck(wc_get_object_terms($product->get_id(), 'product_cat'), 'name');
        $data['tags']       = wp_list_pluck(wc_get_object_terms($product->get_id(), 'product_tag'), 'name');

        return $data;
    }

    /**
     * Wandelt WooCommerce-Objekte (Datum, Metadaten, Attribute) in einfache Werte um.
     */
    private static function plain(mixed $value): mixed
    {
        return match (true) {
            $value instanceof \WC_DateTime          => $value->date('c'),
            $value instanceof \WC_Meta_Data         => ['key' => $value->key, 'value' => self::plain($value->value)],
            $value instanceof \WC_Product_Attribute => self::plain($value->get_data()),
            is_array($value)                        => array_map(static fn($item) => self::plain($item), $value),
            is_object($value)                       => self::plain(get_object_vars($value)),
            default                                 => $value,
        };
    }
}
