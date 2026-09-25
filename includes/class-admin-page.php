<?php

declare(strict_types=1);

namespace NovemberkindProdukte;

defined('ABSPATH') || exit;

/**
 * Menüeintrag im Backend, der zur eigenständigen Produktverwaltung weiterleitet.
 */
final class AdminPage
{
    public const SLUG = 'novemberkind-produkte';

    /**
     * Hängt den Menüeintrag ins Backend.
     */
    public function register(): void
    {
        add_action('admin_menu', [$this, 'add_menu']);
    }

    /**
     * Menüeintrag „Meine Produkte“, der beim Aufruf zur Produktverwaltung weiterleitet.
     */
    public function add_menu(): void
    {
        $hook_suffix = add_menu_page(
            __('Meine Produkte', 'novemberkind-produkte'),
            __('Meine Produkte', 'novemberkind-produkte'),
            Plugin::CAPABILITY,
            self::SLUG,
            '__return_null',
            'dashicons-store',
            3
        );

        add_action("load-{$hook_suffix}", static function (): void {
            wp_safe_redirect(App::url());
            exit;
        });
    }
}
