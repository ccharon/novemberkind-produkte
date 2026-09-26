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
    // Die Adresse steht nur im Metafeld, weil WordPress im Titel für Besucher & zu &amp; macht
    public const META_EMAIL = '_novemberkind_produkte_email';
    private const TITLE = 'Abonnent';
    private const PRIVACY_GROUP = 'novemberkind-produkte-newsletter';
    public const SOURCES = ['form', 'checkout'];
    public const PENDING_DAYS = 7;
    // Schützt Postfächer davor, über das öffentliche Formular mit Bestätigungsmails überhäuft zu werden
    public const RESEND_SECONDS = 10 * MINUTE_IN_SECONDS;
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
        // Werkzeuge > Personenbezogene Daten exportieren und löschen. Nach Priorität 10, weil Germanized
        // dort eine neue Liste zurückgibt und damit alle vorher angemeldeten verwirft.
        add_filter('wp_privacy_personal_data_exporters', [$this, 'register_exporter'], 20);
        add_filter('wp_privacy_personal_data_erasers', [$this, 'register_eraser'], 20);
        add_action('admin_init', [$this, 'add_privacy_policy_content']);
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
        Plugin::register_private_post_type(self::POST_TYPE, __('Newsletter-Abonnenten', 'novemberkind-produkte'));
    }

    /**
     * Alle Abonnenten, bestätigt und unbestätigt, neueste zuerst.
     *
     * @return array<int, array<string, mixed>>
     * @phpstan-return array<int, Subscriber>
     */
    public static function all(): array
    {
        $posts = Plugin::private_posts(self::POST_TYPE, ['orderby' => 'date', 'order' => 'DESC']);

        return array_values(array_filter(array_map([self::class, 'from_post'], $posts)));
    }

    /**
     * Abonnenten, die ihre Anmeldung bestätigt haben.
     *
     * @return array<int, array<string, mixed>>
     * @phpstan-return array<int, Subscriber>
     */
    public static function confirmed(): array
    {
        return array_values(array_filter(self::all(), static fn(array $subscriber): bool => $subscriber['status'] === 'confirmed'));
    }

    /**
     * Zahl der bestätigten und der unbestätigten Anmeldungen.
     *
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
     * Ein Abonnent oder null, wenn es ihn nicht gibt.
     *
     * @return array<string, mixed>|null
     * @phpstan-return Subscriber|null
     */
    public static function get(int $id): ?array
    {
        $post = get_post($id);

        return $post instanceof \WP_Post && $post->post_type === self::POST_TYPE ? self::from_post($post) : null;
    }

    /**
     * Der älteste Eintrag einer Adresse oder null.
     *
     * @return array<string, mixed>|null
     * @phpstan-return Subscriber|null
     */
    public static function find(string $email): ?array
    {
        $ids = self::ids_for_email($email);

        return $ids ? self::get($ids[0]) : null;
    }

    /**
     * Alle Einträge einer Adresse. Zwei gleichzeitige Anmeldungen können dieselbe Adresse doppelt anlegen.
     *
     * @return int[]
     */
    private static function ids_for_email(string $email): array
    {
        return Plugin::private_post_ids(self::POST_TYPE, [
            'meta_key'   => self::META_EMAIL, // phpcs:ignore WordPress.DB.SlowDBQuery -- wenige Einträge
            'meta_value' => strtolower(trim($email)), // phpcs:ignore WordPress.DB.SlowDBQuery -- wenige Einträge
            'orderby'    => 'ID',
            'order'      => 'ASC',
        ]);
    }

    /**
     * Abonnent zum Token aus einem Link, verglichen in konstanter Zeit, oder null.
     *
     * @return array<string, mixed>|null
     * @phpstan-return Subscriber|null
     */
    public static function by_token(string $token): ?array
    {
        if (strlen($token) !== self::TOKEN_LENGTH || !ctype_alnum($token)) {
            return null;
        }
        $posts = Plugin::private_posts(self::POST_TYPE, [
            'meta_key'       => self::META_TOKEN, // phpcs:ignore WordPress.DB.SlowDBQuery -- wenige Einträge
            'meta_value'     => $token, // phpcs:ignore WordPress.DB.SlowDBQuery -- wenige Einträge
            'posts_per_page' => 1,
        ]);
        $subscriber = $posts ? self::from_post($posts[0]) : null;

        return $subscriber && hash_equals($subscriber['token'], $token) ? $subscriber : null;
    }

    /**
     * Nimmt eine Anmeldung an und schickt die Bestätigungsmail. Bekannte Adressen bekommen keine weitere Mail,
     * damit das öffentliche Formular nicht verrät, wer angemeldet ist.
     *
     * @return true|\WP_Error
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
            'post_title'  => self::TITLE,
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
        $this->remove_email($subscriber['email']);

        return true;
    }

    /**
     * Löscht alle Einträge einer Adresse.
     *
     * @return int Zahl der gelöschten Einträge
     */
    public function remove_email(string $email): int
    {
        $removed = 0;
        foreach (self::ids_for_email($email) as $id) {
            $removed += (int) $this->remove($id);
        }

        return $removed;
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
        Plugin::require_capability(Plugin::CAPABILITY);
        Plugin::require_capability(Newsletters::CAPABILITY);

        // BOM, damit Excel die Umlaute richtig liest
        Plugin::send_file('newsletter-abonnenten-' . wp_date('Y-m-d') . '.csv', 'text/csv; charset=utf-8', "\xEF\xBB\xBF" . self::csv());
    }

    /**
     * Meldet den Export der Anmeldung bei den Datenschutz-Werkzeugen von WordPress an.
     *
     * @return array<mixed>
     */
    public function register_exporter(mixed $exporters): array
    {
        return (is_array($exporters) ? $exporters : []) + [self::PRIVACY_GROUP => [
            'exporter_friendly_name' => __('Newsletter', 'novemberkind-produkte'),
            'callback'               => [$this, 'export_personal_data'],
        ]];
    }

    /**
     * Meldet das Löschen der Anmeldung bei den Datenschutz-Werkzeugen von WordPress an.
     *
     * @return array<mixed>
     */
    public function register_eraser(mixed $erasers): array
    {
        return (is_array($erasers) ? $erasers : []) + [self::PRIVACY_GROUP => [
            'eraser_friendly_name' => __('Newsletter', 'novemberkind-produkte'),
            'callback'             => [$this, 'erase_personal_data'],
        ]];
    }

    /**
     * Anmeldung einer Adresse für den Datenexport von WordPress.
     *
     * @return array{data: array<int, array<string, mixed>>, done: bool}
     */
    public function export_personal_data(mixed $email): array
    {
        $items = [];
        foreach (self::ids_for_email(is_string($email) ? $email : '') as $id) {
            $subscriber = self::get($id);
            if ($subscriber === null) {
                continue;
            }
            $items[] = [
                'group_id'    => self::PRIVACY_GROUP,
                'group_label' => __('Newsletter', 'novemberkind-produkte'),
                'item_id'     => 'newsletter-' . $id,
                'data'        => [
                    ['name' => __('E-Mail-Adresse', 'novemberkind-produkte'), 'value' => $subscriber['email']],
                    ['name' => __('Status', 'novemberkind-produkte'), 'value' => $subscriber['status'] === 'confirmed' ? __('Bestätigt', 'novemberkind-produkte') : __('Nicht bestätigt', 'novemberkind-produkte')],
                    ['name' => __('Angemeldet', 'novemberkind-produkte'), 'value' => wp_date('Y-m-d H:i', $subscriber['created'])],
                    ['name' => __('Bestätigt', 'novemberkind-produkte'), 'value' => $subscriber['confirmed'] ? wp_date('Y-m-d H:i', $subscriber['confirmed']) : ''],
                    ['name' => __('Quelle', 'novemberkind-produkte'), 'value' => self::source_label($subscriber['source'])],
                ],
            ];
        }

        return ['data' => $items, 'done' => true];
    }

    /**
     * Löscht die Anmeldung einer Adresse für die Löschanfrage von WordPress.
     *
     * @return array{items_removed: bool, items_retained: bool, messages: string[], done: bool}
     */
    public function erase_personal_data(mixed $email): array
    {
        $removed = $this->remove_email(is_string($email) ? $email : '');

        return ['items_removed' => $removed > 0, 'items_retained' => false, 'messages' => [], 'done' => true];
    }

    /**
     * Textvorschlag für die Datenschutzerklärung unter Einstellungen > Datenschutz.
     */
    public function add_privacy_policy_content(): void
    {
        if (!function_exists('wp_add_privacy_policy_content')) {
            return;
        }
        $paragraphs = [
            __('Wenn du dich für den Newsletter anmeldest, speichern wir deine E-Mail-Adresse, die Zeitpunkte von Anmeldung und Bestätigung und ob du dich über das Formular oder an der Kasse angemeldet hast. Rechtsgrundlage ist deine Einwilligung nach Art. 6 Abs. 1 lit. a DSGVO.', 'novemberkind-produkte'),
            sprintf(
                /* translators: %d: Tage bis zur Löschung */
                __('Nach der Anmeldung bekommst du eine Mail mit einem Link zur Bestätigung (Double-Opt-In). Ohne Bestätigung löschen wir die Adresse nach %d Tagen.', 'novemberkind-produkte'),
                self::PENDING_DAYS
            ),
            __('Zum Schutz vor Missbrauch merken wir uns beim Anmelden über das Formular für eine Stunde einen nicht umkehrbaren Hashwert deiner IP-Adresse. Die IP-Adresse selbst speichern wir nicht.', 'novemberkind-produkte'),
            __('Wir werten nicht aus, ob du den Newsletter öffnest oder Links darin anklickst.', 'novemberkind-produkte'),
            __('Abmelden kannst du dich jederzeit über den Link in jeder Mail. Deine Adresse wird dann gelöscht.', 'novemberkind-produkte'),
        ];
        wp_add_privacy_policy_content(
            __('Novemberkind Produkte: Newsletter', 'novemberkind-produkte'),
            '<h2>' . esc_html__('Newsletter', 'novemberkind-produkte') . '</h2><p>' . implode('</p><p>', array_map('esc_html', $paragraphs)) . '</p>'
        );
    }

    /**
     * Bezeichnung der Quelle einer Anmeldung: Kasse oder Formular.
     */
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
