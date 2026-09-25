<?php

/**
 * Plugin Name:          Novemberkind Produkte
 * Description:          Einfache Produktverwaltung für WooCommerce: Übersicht, Anlegen und Bearbeiten mit automatischer Bildverkleinerung.
 * Version:              0.4.0
 * Requires at least:    6.5
 * Requires PHP:         8.1
 * Requires Plugins:     woocommerce
 * WC requires at least: 9.0
 * WC tested up to:      11.1
 * Author:               Christian Charon
 * License:              GPL-2.0-or-later
 * License URI:          https://www.gnu.org/licenses/gpl-2.0.html
 * Update URI:           https://github.com/ccharon/novemberkind-produkte
 * Text Domain:          novemberkind-produkte
 */

declare(strict_types=1);

namespace NovemberkindProdukte;

defined('ABSPATH') || exit;

const VERSION = '0.4.0';
const PLUGIN_FILE = __FILE__;

// Anthropic-SDK für Vorschläge; ohne vendor/ läuft das Plugin ohne diese Funktion
if (is_readable(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
}

require_once __DIR__ . '/includes/class-plugin.php';
require_once __DIR__ . '/includes/class-admin-page.php';
require_once __DIR__ . '/includes/class-ajax.php';
require_once __DIR__ . '/includes/class-app.php';
require_once __DIR__ . '/includes/class-backups.php';
require_once __DIR__ . '/includes/class-campaigns.php';
require_once __DIR__ . '/includes/class-coupons.php';
require_once __DIR__ . '/includes/class-card-sizes.php';
require_once __DIR__ . '/includes/class-image-processor.php';
require_once __DIR__ . '/includes/class-newsletter-mail.php';
require_once __DIR__ . '/includes/class-newsletter-signup.php';
require_once __DIR__ . '/includes/class-newsletters.php';
require_once __DIR__ . '/includes/class-product-service.php';
require_once __DIR__ . '/includes/class-product-type.php';
require_once __DIR__ . '/includes/class-shop-data.php';
require_once __DIR__ . '/includes/class-originals.php';
require_once __DIR__ . '/includes/class-subscribers.php';
require_once __DIR__ . '/includes/class-suggestions.php';
require_once __DIR__ . '/includes/class-updater.php';
if (interface_exists(\Psr\Http\Client\ClientInterface::class)) {
    require_once __DIR__ . '/includes/class-wp-http-client.php';
    require_once __DIR__ . '/includes/class-wp-http-network-exception.php';
    require_once __DIR__ . '/includes/class-wp-http-discovery-strategy.php';
}

register_activation_hook(__FILE__, [Plugin::class, 'activate']);

add_action('before_woocommerce_init', static function (): void {
    if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
    }
});

add_action('plugins_loaded', [Plugin::class, 'init']);
