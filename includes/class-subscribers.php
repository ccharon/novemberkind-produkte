<?php

declare(strict_types=1);

namespace NovemberkindProdukte;

defined('ABSPATH') || exit;

/**
 * Abonnenten des Newsletters mit Double-Opt-In. Abgemeldete und ausgetragene Adressen werden gelöscht.
 *
 * @phpstan-type Subscriber array{id: int, email: string, status: string, created: int, confirmed: int, source: string, token: string}
 */
final class Subscribers
{
    public const POST_TYPE = 'novemberkind_abo';
    public const META = '_novemberkind_produkte_subscriber';
    public const META_TOKEN = '_novemberkind_produkte_token';
    // Die Adresse steht im Metafeld, weil WordPress im Titel für Besucher & zu &amp; macht
    public const META_EMAIL = '_novemberkind_produkte_email';
    public const SOURCES = ['form', 'checkout'];
    public const PENDING_DAYS = 7;
    // Schützt Postfächer davor, über das öffentliche Formular mit Bestätigungsmails überhäuft zu werden
    public const RESEND_SECONDS = 600;
    public const CSV_ACTION = 'novemberkind_produkte_subscribers_csv';
    public const CLEANUP_HOOK = 'novemberkind_produkte_subscribers_cleanup';
    private const TOKEN_LENGTH = 32;
    // Längste zulässige Adresse nach RFC 5321
    private const EMAIL_MAX_LENGTH = 254;

    /**
     * Meldet den Inhaltstyp und den CSV-Export an.
     */
    public function register(): void
    {
        add_action('init', [$this, 'register_post_type']);
        add_action('admin_post_' . self::CSV_ACTION, [$this, 'download_csv']);
        // Täglich, damit unbestätigte Adressen auch ohne neue Anmeldungen nach der Frist verschwinden
        add_action(self::CLEANUP_HOOK, [$this, 'cleanup']);
        add_action('init', static function (): void {
            if (!wp_next_scheduled(self::CLEANUP_HOOK)) {
                wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', self::CLEANUP_HOOK);
            }
        });
    }

    /**
     * Privater Inhaltstyp ohne Backend-Oberfläche, REST und Export.
     */
    public function register_post_type(): void
    {
        register_post_type(self::POST_TYPE, [
            'label'               => __('Newsletter-Abonnenten', 'novemberkind-produkte'),
            'public'              => false,
            'publicly_queryable'  => false,
            'exclude_from_search' => true,
            'show_ui'             => false,
            'show_in_rest'        => false,
            'show_in_nav_menus'   => false,
            'rewrite'             => false,
            'query_var'           => false,
            'can_export'          => false,
            'supports'            => ['title'],
            'capability_type'     => 'product',
            'map_meta_cap'        => true,
        ]);
    }

    /**
     * @return array<int, array<string, mixed>> alle Abonnenten, neueste zuerst
     * @phpstan-return array<int, Subscriber>
     */
    public static function all(): array
    {
        $posts = get_posts([
            'post_type'      => self::POST_TYPE,
            'post_status'    => 'private',
            'posts_per_page' => -1,
            'orderby'        => 'date',
            'order'          => 'DESC',
            'no_found_rows'  => true,
        ]);

        return array_values(array_filter(array_map([self::class, 'from_post'], $posts)));
    }

    /**
     * @return array<int, array<string, mixed>>
     * @phpstan-return array<int, Subscriber>
     */
    public static function confirmed(): array
    {
        return array_values(array_filter(self::all(), static fn(array $subscriber): bool => $subscriber['status'] === 'confirmed'));
    }

    /**
     * @return array{confirmed: int, pending: int}
     */
    public static function counts(): array
    {
        $counts = ['confirmed' => 0, 'pending' => 0];
        foreach (self::all() as $subscriber) {
            $counts[$subscriber['status'] === 'confirmed' ? 'confirmed' : 'pending']++;
        }

        return $counts;
    }

    /**
     * @return array<string, mixed>|null
     * @phpstan-return Subscriber|null
     */
    public static function get(int $id): ?array
    {
        $post = get_post($id);

        return $post instanceof \WP_Post && $post->post_type === self::POST_TYPE ? self::from_post($post) : null;
    }

    /**
     * @return array<string, mixed>|null
     * @phpstan-return Subscriber|null
     */
    public static function find(string $email): ?array
    {
        $posts = get_posts([
            'post_type'      => self::POST_TYPE,
            'post_status'    => 'private',
            'meta_key'       => self::META_EMAIL, // phpcs:ignore WordPress.DB.SlowDBQuery -- wenige Einträge
            'meta_value'     => strtolower($email), // phpcs:ignore WordPress.DB.SlowDBQuery -- wenige Einträge
            'posts_per_page' => 1,
            'no_found_rows'  => true,
        ]);

        return $posts ? self::from_post($posts[0]) : null;
    }

    /**
     * @return array<string, mixed>|null
     * @phpstan-return Subscriber|null
     */
    public static function by_token(string $token): ?array
    {
        if (strlen($token) !== self::TOKEN_LENGTH || !ctype_alnum($token)) {
            return null;
        }
        $posts = get_posts([
            'post_type'      => self::POST_TYPE,
            'post_status'    => 'private',
            'meta_key'       => self::META_TOKEN, // phpcs:ignore WordPress.DB.SlowDBQuery -- wenige Einträge
            'meta_value'     => $token, // phpcs:ignore WordPress.DB.SlowDBQuery -- wenige Einträge
            'posts_per_page' => 1,
            'no_found_rows'  => true,
        ]);
        $subscriber = $posts ? self::from_post($posts[0]) : null;

        return $subscriber && hash_equals($subscriber['token'], $token) ? $subscriber : null;
    }

    /**
     * Nimmt eine Anmeldung an und schickt die Bestätigungsmail. Bekannte Adressen bekommen keine weitere Mail,
     * damit das öffentliche Formular nicht verrät, wer angemeldet ist.
     */
    public function subscribe(string $email, string $source): bool|\WP_Error
    {
        $email = strtolower(trim(sanitize_email($email)));
        if (strlen($email) > self::EMAIL_MAX_LENGTH || !is_email($email)) {
            return new \WP_Error('email', __('Bitte gib eine gültige E-Mail-Adresse ein.', 'novemberkind-produkte'));
        }
        if (!in_array($source, self::SOURCES, true)) {
            $source = 'form';
        }
        $this->cleanup();

        $existing = self::find($email);
        if ($existing !== null && ($existing['status'] === 'confirmed' || $existing['created'] > time() - self::RESEND_SECONDS)) {
            return true;
        }

        $id = $existing['id'] ?? wp_insert_post([
            'post_type'   => self::POST_TYPE,
            'post_status' => 'private',
            'post_title'  => $email,
        ], true);
        if (is_wp_error($id) || $id === 0) {
            return new \WP_Error('save', __('Die Anmeldung hat nicht geklappt. Bitte versuche es später noch einmal.', 'novemberkind-produkte'));
        }
        $token = $existing['token'] ?? wp_generate_password(self::TOKEN_LENGTH, false);
        update_post_meta($id, self::META_EMAIL, wp_slash($email));
        update_post_meta($id, self::META_TOKEN, $token);
        update_post_meta($id, self::META, ['status' => 'pending', 'created' => time(), 'confirmed' => 0, 'source' => $source]);

        if (!NewsletterMail::send_confirmation($email, NewsletterSignup::url('bestaetigen', $token))) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Hinweis für den Betrieb, die Besucherin sieht eine allgemeine Meldung
            error_log('novemberkind-produkte: Bestätigungsmail für den Newsletter konnte nicht verschickt werden.');
        }

        return true;
    }

    /**
     * Bestätigt eine Anmeldung über den Link aus der Mail.
     *
     * @return array<string, mixed>|null
     * @phpstan-return Subscriber|null
     */
    public function confirm(string $token): ?array
    {
        $subscriber = self::by_token($token);
        if ($subscriber === null || $subscriber['status'] === 'confirmed') {
            return $subscriber;
        }

        update_post_meta($subscriber['id'], self::META, [
            'status'    => 'confirmed',
            'created'   => $subscriber['created'],
            'confirmed' => time(),
            'source'    => $subscriber['source'],
        ]);

        return self::get($subscriber['id']);
    }

    /**
     * Meldet eine Adresse über den Link aus der Mail ab und löscht sie.
     */
    public function unsubscribe(string $token): bool
    {
        $subscriber = self::by_token($token);
        if ($subscriber === null) {
            return false;
        }
        // Zwei gleichzeitige Anmeldungen können dieselbe Adresse doppelt anlegen, abgemeldet werden alle
        foreach (self::all() as $other) {
            if ($other['email'] === $subscriber['email']) {
                $this->remove($other['id']);
            }
        }

        return true;
    }

    /**
     * Löscht einen Abonnenten. Löscht ausschließlich Einträge dieses Inhaltstyps.
     */
    public function remove(int $id): bool
    {
        if (self::get($id) === null) {
            return false;
        }

        return (bool) wp_delete_post($id, true);
    }

    /**
     * Löscht Anmeldungen, die nicht innerhalb der Frist bestätigt wurden.
     */
    public function cleanup(): void
    {
        foreach (self::all() as $subscriber) {
            if ($subscriber['status'] === 'pending' && $subscriber['created'] < time() - self::PENDING_DAYS * DAY_IN_SECONDS) {
                $this->remove($subscriber['id']);
            }
        }
    }

    /**
     * Adresse für den CSV-Export, mit Nonce.
     */
    public static function csv_url(): string
    {
        return add_query_arg([
            'action'   => self::CSV_ACTION,
            '_wpnonce' => wp_create_nonce(self::CSV_ACTION),
        ], admin_url('admin-post.php'));
    }

    /**
     * Bestätigte Abonnenten als CSV mit Semikolon, wie es Excel und Numbers auf Deutsch erwarten.
     */
    public static function csv(): string
    {
        $rows = [[__('E-Mail-Adresse', 'novemberkind-produkte'), __('Angemeldet', 'novemberkind-produkte'), __('Bestätigt', 'novemberkind-produkte'), __('Quelle', 'novemberkind-produkte')]];
        foreach (self::confirmed() as $subscriber) {
            $rows[] = [
                $subscriber['email'],
                wp_date('Y-m-d H:i', $subscriber['created']),
                wp_date('Y-m-d H:i', $subscriber['confirmed']),
                self::source_label($subscriber['source']),
            ];
        }

        // Ein Apostroph vor =, +, - und @ verhindert, dass Tabellenprogramme den Wert als Formel ausführen
        $cell = static fn(string $value): string => '"' . str_replace('"', '""', preg_match('/^[=+\-@\t\r]/', $value) ? "'" . $value : $value) . '"';

        return implode("\r\n", array_map(static fn(array $row): string => implode(';', array_map($cell, $row)), $rows)) . "\r\n";
    }

    /**
     * Liefert die CSV-Datei aus, nur mit Nonce und Rechten für den Newsletter.
     */
    public function download_csv(): void
    {
        check_admin_referer(self::CSV_ACTION);
        if (!current_user_can(Plugin::CAPABILITY) || !current_user_can(Newsletters::CAPABILITY)) {
            wp_die(esc_html__('Dafür fehlen dir die Berechtigungen.', 'novemberkind-produkte'), '', ['response' => 403]);
        }

        nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        header('Content-Disposition: attachment; filename="newsletter-abonnenten-' . wp_date('Y-m-d') . '.csv"');
        // BOM, damit Excel die Umlaute richtig liest
        echo "\xEF\xBB\xBF" . self::csv(); // phpcs:ignore WordPress.Security.EscapeOutput -- CSV mit Content-Type text/csv und nosniff
        exit;
    }

    public static function source_label(string $source): string
    {
        return $source === 'checkout' ? __('Kasse', 'novemberkind-produkte') : __('Formular', 'novemberkind-produkte');
    }

    /**
     * @return array<string, mixed>|null
     * @phpstan-return Subscriber|null
     */
    private static function from_post(\WP_Post $post): ?array
    {
        $meta = get_post_meta($post->ID, self::META, true);
        if (!is_array($meta)) {
            return null;
        }

        return [
            'id'        => $post->ID,
            'email'     => (string) get_post_meta($post->ID, self::META_EMAIL, true),
            'status'    => ($meta['status'] ?? '') === 'confirmed' ? 'confirmed' : 'pending',
            'created'   => (int) ($meta['created'] ?? 0),
            'confirmed' => (int) ($meta['confirmed'] ?? 0),
            'source'    => in_array($meta['source'] ?? '', self::SOURCES, true) ? (string) $meta['source'] : 'form',
            'token'     => (string) get_post_meta($post->ID, self::META_TOKEN, true),
        ];
    }
}
