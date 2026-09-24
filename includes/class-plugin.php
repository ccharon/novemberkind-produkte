<?php

declare(strict_types=1);

namespace EasyProduct;

defined('ABSPATH') || exit;

final class Plugin
{
    public const CAPABILITY = 'edit_products';

    public static function init(): void
    {
        if (!class_exists(\WooCommerce::class)) {
            return;
        }

        (new App())->register();
        (new AdminPage())->register();
        (new Ajax())->register();
        (new Originals())->register();
        add_action('admin_notices', [self::class, 'webp_notice']);
    }

    public static function activate(): void
    {
        update_option('easy_product_webp_supported', self::webp_supported() ? 'yes' : 'no');
    }

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
            esc_html__('Easy Product: Der Server kann keine WebP-Bilder erzeugen. Bitte beim Hoster die GD- oder Imagick-Erweiterung mit WebP-Unterstützung aktivieren lassen.', 'easy-product')
        );
    }
}
