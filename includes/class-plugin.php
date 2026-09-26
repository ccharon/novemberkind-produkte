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
    private const DB_VERSION_OPTION = 'novemberkind_produkte_db_version';

    /**
     * Startet alle Teile des Plugins; ohne WooCommerce nur den Updater.
     */
    public static function init(): void
    {
        // Updates auch ohne aktives WooCommerce, damit sich ein fehlerhaftes Release beheben lässt
        (new Updater())->register();
        self::upgrade();

        if (!class_exists(\WooCommerce::class)) {
            return;
        }

        (new Backups())->register();
        (new Campaigns())->register();
        (new Coupons())->register();
        (new Subscribers())->register();
        (new ImageProcessor())->register();
        (new Newsletters())->register();
        (new NewsletterSignup())->register();
        (new App())->register();
        (new Login())->register();
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
     * Passt gespeicherte Daten einmalig an den Stand dieser Version an. Updates über WordPress
     * lösen keinen Aktivierungs-Hook aus, deshalb bei jedem Laden mit einer gemerkten Version.
     */
    public static function upgrade(): void
    {
        $version = (int) get_option(self::DB_VERSION_OPTION, 0);
        $steps   = [
            1 => static fn() => delete_option('novemberkind_produkte_webp_supported'),
        ];
        foreach ($steps as $step => $run) {
            if ($version < $step) {
                $run();
                update_option(self::DB_VERSION_OPTION, $step, true);
            }
        }
    }

    /**
     * Nicht öffentlicher Inhaltstyp ohne Adresse, Backend-Oberfläche, REST und Export.
     * Rechte wie bei Produkten, damit nur Rollen mit Produktrechten an die Einträge kommen.
     *
     * @param string[] $supports
     */
    public static function register_private_post_type(string $post_type, string $label, array $supports = ['title']): void
    {
        register_post_type($post_type, [
            'label'               => $label,
            'public'              => false,
            'publicly_queryable'  => false,
            'exclude_from_search' => true,
            'show_ui'             => false,
            'show_in_rest'        => false,
            'show_in_nav_menus'   => false,
            'rewrite'             => false,
            'query_var'           => false,
            'can_export'          => false,
            'supports'            => $supports,
            'capability_type'     => 'product',
            'map_meta_cap'        => true,
        ]);
    }

    /**
     * Beendet die Seite mit 403, wenn ein Recht fehlt. Die Meldung nennt den Bereich.
     */
    public static function require_capability(string $capability): void
    {
        if (current_user_can($capability)) {
            return;
        }
        wp_die(
            esc_html(match ($capability) {
                Coupons::CAPABILITY     => __('Für Gutscheine fehlen dir die Berechtigungen.', 'novemberkind-produkte'),
                Newsletters::CAPABILITY => __('Für den Newsletter fehlen dir die Berechtigungen.', 'novemberkind-produkte'),
                self::CAPABILITY        => __('Für die Produktverwaltung fehlen dir die Berechtigungen.', 'novemberkind-produkte'),
                default                 => __('Dafür fehlen dir die Berechtigungen.', 'novemberkind-produkte'),
            }),
            esc_html__('Keine Berechtigung', 'novemberkind-produkte'),
            ['response' => 403, 'back_link' => true]
        );
    }

    /**
     * Liefert eine Datei zum Herunterladen oder Ansehen aus. Mit festem Typ und nosniff, damit der Browser
     * den Inhalt nie als HTML ausführt.
     */
    public static function send_file(string $filename, string $content_type, string $body, bool $inline = false): never
    {
        nocache_headers();
        header('Content-Type: ' . $content_type);
        header('X-Content-Type-Options: nosniff');
        header(sprintf('Content-Disposition: %s; filename="%s"', $inline ? 'inline' : 'attachment', sanitize_file_name($filename)));
        echo $body; // phpcs:ignore WordPress.Security.EscapeOutput -- Dateiinhalt mit festem Content-Type und nosniff
        exit;
    }

    /**
     * Adresse einer Datei des Plugins, z. B. „assets/css/app.css“.
     */
    public static function asset_url(string $file): string
    {
        return plugin_dir_url(PLUGIN_FILE) . $file;
    }

    /**
     * Plugin-Version plus Änderungszeit der Datei, damit Browser nach jeder Änderung die neue Fassung laden.
     */
    public static function asset_version(string $file): string
    {
        $path = dirname(PLUGIN_FILE) . '/' . $file;

        return VERSION . '.' . (is_readable($path) ? (string) filemtime($path) : '0');
    }

    /**
     * Stile der Produktverwaltung, auch für die öffentlichen Seiten zum Bestätigen und Abmelden.
     */
    public static function register_app_style(): void
    {
        wp_register_style('novemberkind-produkte-app', self::asset_url('assets/css/app.css'), [], self::asset_version('assets/css/app.css'));
    }

    /**
     * Entfernt beim Deaktivieren die eigenen Aufgaben aus WP-Cron.
     */
    public static function deactivate(): void
    {
        wp_clear_scheduled_hook(Subscribers::CLEANUP_HOOK);
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
