<?php

declare(strict_types=1);

namespace NovemberkindProdukte;

defined('ABSPATH') || exit;

/**
 * Einstieg des Plugins: startet die Teile, prüft WebP und zeigt Hinweise im Backend.
 */
final class Plugin
{
    public const CAPABILITY = 'edit_products';

    /**
     * Startet alle Teile des Plugins; ohne WooCommerce nur den Updater.
     */
    public static function init(): void
    {
        // Updates auch ohne aktives WooCommerce, damit sich ein fehlerhaftes Release beheben lässt
        (new Updater())->register();

        if (!class_exists(\WooCommerce::class)) {
            return;
        }

        (new Backups())->register();
        (new Campaigns())->register();
        (new Coupons())->register();
        (new App())->register();
        (new AdminPage())->register();
        (new Ajax())->register();
        (new Originals())->register();
        add_action('admin_notices', [self::class, 'webp_notice']);
        add_action('admin_notices', [self::class, 'permalink_notice']);
    }

    /**
     * Die Adresse /produkte-verwalten/ funktioniert nur mit sprechenden Permalinks.
     */
    public static function permalink_notice(): void
    {
        if (!current_user_can('manage_options') || get_option('permalink_structure') !== '') {
            return;
        }

        printf(
            '<div class="notice notice-error"><p>%s <a href="%s">%s</a></p></div>',
            esc_html__('Novemberkind Produkte braucht sprechende Permalinks, sonst ist /produkte-verwalten/ nicht erreichbar.', 'novemberkind-produkte'),
            esc_url(admin_url('options-permalink.php')),
            esc_html__('Permalinks einstellen', 'novemberkind-produkte')
        );
    }

    /**
     * Merkt sich bei der Aktivierung, ob der Server WebP schreiben kann.
     */
    public static function activate(): void
    {
        update_option('novemberkind_produkte_webp_supported', self::webp_supported() ? 'yes' : 'no');
    }

    /**
     * Ob der Bildeditor des Servers WebP erzeugen kann.
     */
    public static function webp_supported(): bool
    {
        return wp_image_editor_supports(['mime_type' => 'image/webp']);
    }

    /**
     * Hinweis im Backend, wenn der Server keine WebP-Bilder erzeugen kann.
     */
    public static function webp_notice(): void
    {
        if (!current_user_can(self::CAPABILITY) || self::webp_supported()) {
            return;
        }

        printf(
            '<div class="notice notice-error"><p>%s</p></div>',
            esc_html__('Novemberkind Produkte: Der Server kann keine WebP-Bilder erzeugen. Bitte beim Hoster die GD- oder Imagick-Erweiterung mit WebP-Unterstützung aktivieren lassen.', 'novemberkind-produkte')
        );
    }
}
