<?php

declare(strict_types=1);

namespace NovemberkindProdukte;

defined('ABSPATH') || exit;

/**
 * Login-Seite im Stil der Produktverwaltung und Weiterleitung nach der Anmeldung.
 */
final class Login
{
    /**
     * Meldet Weiterleitung und Gestaltung der Login-Seite an.
     */
    public function register(): void
    {
        add_filter('login_redirect', [$this, 'login_redirect'], 10, 3);
        add_action('login_enqueue_scripts', [$this, 'login_style']);
        add_filter('login_headerurl', static fn(): string => home_url('/'));
        add_filter('login_headertext', static fn(): string => get_bloginfo('name'));
    }

    /**
     * Shop-Manager landen nach dem Login direkt in der Produktverwaltung, Administratoren im Backend.
     * Ohne Typangaben, weil auch andere Plugins diesen Filter auslösen; ein Typfehler würde die Anmeldung abbrechen.
     */
    public function login_redirect(mixed $redirect_to, mixed $requested = '', mixed $user = null): mixed
    {
        if (!is_string($requested) || !$user instanceof \WP_User || $user->has_cap('manage_options') || !$user->has_cap(Plugin::CAPABILITY)) {
            return $redirect_to;
        }
        if ($requested === '' || untrailingslashit($requested) === untrailingslashit(admin_url())) {
            return App::url();
        }

        return $redirect_to;
    }

    /**
     * Gestaltet die WordPress-Login-Seite wie die Produktverwaltung.
     */
    public function login_style(): void
    {
        wp_enqueue_style('novemberkind-produkte-login', Plugin::asset_url('assets/css/login.css'), [], Plugin::asset_version('assets/css/login.css'));
    }
}
