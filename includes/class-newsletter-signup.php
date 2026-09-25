<?php

declare(strict_types=1);

namespace NovemberkindProdukte;

defined('ABSPATH') || exit;

/**
 * Anmeldung zum Newsletter im Shop: Formular per Shortcode, Haken an der Kasse, Seiten zum Bestätigen und Abmelden.
 */
final class NewsletterSignup
{
    public const SHORTCODE = 'novemberkind_newsletter';
    public const SIGNUP_ACTION = 'novemberkind_produkte_newsletter_signup';
    public const QUERY_ARG = 'nkp-newsletter';
    public const STATUS_ARG = 'nkp-newsletter-status';
    public const CHECKOUT_FIELD = 'nkp_newsletter';
    public const BLOCK_FIELD = 'novemberkind-produkte/newsletter';
    // Grenzen für das öffentliche Formular pro Stunde, gegen Bots, die fremde Adressen eintragen
    public const IP_LIMIT = 5;
    public const HOURLY_LIMIT = 30;
    private const LIMIT_WINDOW = HOUR_IN_SECONDS;
    private const LIMIT_PREFIX = 'novemberkind_produkte_signup_';
    // Unsichtbares Feld: Wer es ausfüllt, ist ein Bot
    private const HONEYPOT = 'nkp_website';

    /**
     * Meldet Shortcode, Formularziel, Kasse und öffentliche Seiten an.
     */
    public function register(): void
    {
        add_shortcode(self::SHORTCODE, [$this, 'shortcode']);
        add_action('admin_post_nopriv_' . self::SIGNUP_ACTION, [$this, 'handle_signup']);
        add_action('admin_post_' . self::SIGNUP_ACTION, [$this, 'handle_signup']);
        add_action('template_redirect', [$this, 'maybe_render_page'], 0);

        // Klassische Kasse
        add_action('woocommerce_review_order_before_submit', [$this, 'checkout_checkbox']);
        add_action('woocommerce_checkout_order_processed', [$this, 'classic_checkout_processed'], 10, 3);
        // Block-Kasse
        add_action('woocommerce_init', [$this, 'register_block_field']);
        add_action('woocommerce_store_api_checkout_order_processed', [$this, 'block_checkout_processed']);
    }

    /**
     * Adresse zum Bestätigen oder Abmelden mit dem Token eines Abonnenten.
     */
    public static function url(string $action, string $token): string
    {
        return add_query_arg([self::QUERY_ARG => $action, 't' => $token], home_url('/'));
    }

    /**
     * Anmeldeformular für Seiten, Beiträge und den Footer.
     */
    public function shortcode(): string
    {
        wp_enqueue_style('novemberkind-produkte-newsletter', plugin_dir_url(PLUGIN_FILE) . 'assets/css/newsletter.css', [], VERSION);

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nur Anzeige der Rückmeldung
        $status  = sanitize_key(wp_unslash($_GET[self::STATUS_ARG] ?? ''));
        $message = match ($status) {
            'ok'      => __('Du bekommst gleich eine Mail. Bitte bestätige darin deine Anmeldung.', 'novemberkind-produkte'),
            'invalid' => __('Bitte gib eine gültige E-Mail-Adresse ein.', 'novemberkind-produkte'),
            'busy'    => __('Gerade kommen sehr viele Anmeldungen. Bitte versuche es in einer Stunde noch einmal.', 'novemberkind-produkte'),
            'error'   => __('Die Anmeldung hat nicht geklappt. Bitte versuche es später noch einmal.', 'novemberkind-produkte'),
            default   => '',
        };
        $privacy = get_privacy_policy_url();

        ob_start();
        ?>
        <form class="nkp-signup" id="nkp-newsletter" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <input type="hidden" name="action" value="<?php echo esc_attr(self::SIGNUP_ACTION); ?>">
            <div class="nkp-signup__row">
                <label class="nkp-signup__label" for="nkp-signup-email"><?php esc_html_e('E-Mail-Adresse', 'novemberkind-produkte'); ?></label>
                <input class="nkp-signup__input" type="email" id="nkp-signup-email" name="email" required autocomplete="email" inputmode="email">
                <button class="nkp-signup__button" type="submit"><?php esc_html_e('Anmelden', 'novemberkind-produkte'); ?></button>
            </div>
            <p class="nkp-signup__trap" aria-hidden="true">
                <label><?php esc_html_e('Bitte leer lassen', 'novemberkind-produkte'); ?> <input type="text" name="<?php echo esc_attr(self::HONEYPOT); ?>" tabindex="-1" autocomplete="off"></label>
            </p>
            <?php if ($message !== '') : ?>
                <p class="nkp-signup__message nkp-signup__message--<?php echo esc_attr($status); ?>" role="status"><?php echo esc_html($message); ?></p>
            <?php endif; ?>
            <p class="nkp-signup__note">
                <?php esc_html_e('Du bekommst eine Mail mit einem Link zur Bestätigung. Abmelden kannst du dich jederzeit über den Link in jedem Newsletter.', 'novemberkind-produkte'); ?>
                <?php if ($privacy !== '') : ?>
                    <a href="<?php echo esc_url($privacy); ?>"><?php esc_html_e('Datenschutzerklärung', 'novemberkind-produkte'); ?></a>
                <?php endif; ?>
            </p>
        </form>
        <?php

        return (string) ob_get_clean();
    }

    /**
     * Nimmt das Formular an und leitet zurück zur Seite mit einer Rückmeldung.
     * Ohne Nonce, weil Seiten-Caches sie für Besucher veralten lassen; Schutz über Honeypot, Wartezeit je Adresse und Grenzen pro Stunde.
     */
    public function handle_signup(): void
    {
        // phpcs:disable WordPress.Security.NonceVerification.Missing -- öffentliches Formular, siehe oben
        $email = sanitize_email(wp_unslash((string) ($_POST['email'] ?? '')));
        $bot   = trim(sanitize_text_field(wp_unslash((string) ($_POST[self::HONEYPOT] ?? '')))) !== '';
        // phpcs:enable

        $ip     = sanitize_text_field(wp_unslash((string) ($_SERVER['REMOTE_ADDR'] ?? '')));
        $status = 'ok';
        if (!$bot && !self::within_limits($ip)) {
            $status = 'busy';
        } elseif (!$bot) {
            $result = (new Subscribers())->subscribe($email, 'form');
            if (is_wp_error($result)) {
                $status = $result->get_error_code() === 'email' ? 'invalid' : 'error';
            }
        }

        $back = wp_get_referer() ?: home_url('/');
        wp_safe_redirect(add_query_arg(self::STATUS_ARG, $status, remove_query_arg(self::STATUS_ARG, $back)) . '#nkp-newsletter');
        exit;
    }

    /**
     * Zählt eine Anmeldung und sagt, ob sie noch erlaubt ist: höchstens IP_LIMIT pro IP-Adresse und HOURLY_LIMIT
     * insgesamt pro Stunde. Die IP-Adresse wird nur als Hash für diese Stunde gemerkt.
     */
    public static function within_limits(string $ip): bool
    {
        $keys = [
            self::LIMIT_PREFIX . 'ip_' . substr(wp_hash($ip), 0, 32) => self::IP_LIMIT,
            self::LIMIT_PREFIX . 'all'                               => self::HOURLY_LIMIT,
        ];
        $windows = [];
        foreach ($keys as $key => $limit) {
            $window = get_transient($key);
            $window = is_array($window) && ($window['start'] ?? 0) > time() - self::LIMIT_WINDOW ? $window : ['start' => time(), 'count' => 0];
            if ($window['count'] >= $limit) {
                return false;
            }
            $windows[$key] = $window;
        }

        foreach ($windows as $key => $window) {
            $window['count']++;
            // Feste Stunde ab der ersten Anmeldung, weitere Anmeldungen verlängern sie nicht
            set_transient($key, $window, max(1, $window['start'] + self::LIMIT_WINDOW - time()));
            if ($key === self::LIMIT_PREFIX . 'all' && $window['count'] === self::HOURLY_LIMIT) {
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Hinweis für den Betrieb auf möglichen Missbrauch
                error_log('novemberkind-produkte: Obergrenze für Newsletter-Anmeldungen erreicht, das Formular pausiert bis zum Ende der Stunde.');
            }
        }

        return true;
    }

    /**
     * Haken an der klassischen Kasse, nicht vorausgewählt.
     */
    public function checkout_checkbox(): void
    {
        woocommerce_form_field(self::CHECKOUT_FIELD, [
            'type'  => 'checkbox',
            'class' => ['form-row-wide', 'nkp-checkout-newsletter'],
            'label' => self::checkout_label(),
        ], '');
    }

    /**
     * @param array<string, mixed> $posted
     */
    public function classic_checkout_processed(int $order_id, array $posted, \WC_Order $order): void
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce hat die Nonce der Kasse geprüft
        if (!empty($_POST[self::CHECKOUT_FIELD])) {
            (new Subscribers())->subscribe($order->get_billing_email(), 'checkout');
        }
    }

    /**
     * Haken an der Block-Kasse über die Zusatzfelder von WooCommerce.
     */
    public function register_block_field(): void
    {
        if (!function_exists('woocommerce_register_additional_checkout_field')) {
            return;
        }
        woocommerce_register_additional_checkout_field([
            'id'       => self::BLOCK_FIELD,
            'label'    => self::checkout_label(),
            'location' => 'order',
            'type'     => 'checkbox',
        ]);
    }

    public function block_checkout_processed(\WC_Order $order): void
    {
        // WooCommerce speichert Zusatzfelder der Bestellung unter _wc_other/<id>
        if (wc_string_to_bool((string) $order->get_meta('_wc_other/' . self::BLOCK_FIELD))) {
            (new Subscribers())->subscribe($order->get_billing_email(), 'checkout');
        }
    }

    /**
     * Seiten zum Bestätigen und Abmelden. Ein Aufruf zeigt nur einen Knopf, erst das Absenden wirkt,
     * weil Virenscanner in Postfächern Links aufrufen. Mailprogramme melden per POST mit Ein-Klick ab (RFC 8058).
     */
    public function maybe_render_page(): void
    {
        // phpcs:disable WordPress.Security.NonceVerification -- das Token aus der Mail ist der Nachweis
        $action = sanitize_key(wp_unslash($_GET[self::QUERY_ARG] ?? ''));
        $token  = (string) preg_replace('/[^A-Za-z0-9]/', '', sanitize_text_field(wp_unslash((string) ($_GET['t'] ?? ''))));
        // phpcs:enable
        if (!in_array($action, ['bestaetigen', 'abmelden'], true)) {
            return;
        }

        $posted      = isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST';
        $subscribers = new Subscribers();
        $subscriber  = Subscribers::by_token($token);

        if ($action === 'abmelden') {
            $state = match (true) {
                $subscriber === null => 'gone',
                $posted              => $subscribers->unsubscribe($token) ? 'done' : 'gone',
                default              => 'ask',
            };
        } else {
            $state = match (true) {
                $subscriber === null                   => 'invalid',
                $subscriber['status'] === 'confirmed'  => 'done',
                $posted                                => $subscribers->confirm($token) ? 'done' : 'invalid',
                default                                => 'ask',
            };
        }

        nocache_headers();
        status_header(200);
        // Vor der eigenen Content-Security-Policy, weil WordPress hier ebenfalls eine setzt
        send_frame_options_header();
        header('X-Robots-Tag: noindex, nofollow');
        // Die Adresse enthält das Token, es soll nicht über Links weitergegeben werden
        header('Referrer-Policy: no-referrer');
        // Die Seite braucht nur eigenes CSS und Bilder, kein Skript
        header("Content-Security-Policy: default-src 'none'; style-src 'self'; img-src 'self' data:; form-action 'self'; frame-ancestors 'self'; base-uri 'none'");
        header('X-Content-Type-Options: nosniff');
        $this->render_page($action, $state);
        exit;
    }

    private function render_page(string $action, string $state): void
    {
        $texts = [
            'bestaetigen' => [
                'ask'     => [__('Anmeldung bestätigen', 'novemberkind-produkte'), __('Mit einem Klick auf den Knopf bestätigst du deine Anmeldung zum Newsletter.', 'novemberkind-produkte')],
                'done'    => [__('Danke, du bist angemeldet', 'novemberkind-produkte'), __('Ab jetzt bekommst du den Newsletter. Abmelden kannst du dich jederzeit über den Link in jeder Mail.', 'novemberkind-produkte')],
                'invalid' => [__('Link nicht mehr gültig', 'novemberkind-produkte'), __('Dieser Link ist abgelaufen oder wurde schon verwendet. Melde dich bei Bedarf einfach noch einmal an.', 'novemberkind-produkte')],
            ],
            'abmelden' => [
                'ask'  => [__('Vom Newsletter abmelden', 'novemberkind-produkte'), __('Mit einem Klick auf den Knopf meldest du dich ab. Deine Adresse wird dabei gelöscht.', 'novemberkind-produkte')],
                'done' => [__('Du bist abgemeldet', 'novemberkind-produkte'), __('Deine Adresse ist gelöscht, du bekommst keinen Newsletter mehr.', 'novemberkind-produkte')],
                'gone' => [__('Schon abgemeldet', 'novemberkind-produkte'), __('Diese Adresse ist nicht mehr für den Newsletter angemeldet.', 'novemberkind-produkte')],
            ],
        ];
        [$heading, $text] = $texts[$action][$state];
        $button = $state !== 'ask' ? '' : ($action === 'abmelden' ? __('Abmelden', 'novemberkind-produkte') : __('Anmeldung bestätigen', 'novemberkind-produkte'));
        $shop   = get_bloginfo('name');

        wp_register_style('novemberkind-produkte-app', plugin_dir_url(PLUGIN_FILE) . 'assets/css/app.css', [], VERSION);
        include __DIR__ . '/../templates/newsletter-page.php';
    }

    private static function checkout_label(): string
    {
        return __('Ja, ich möchte den Newsletter mit Neuigkeiten aus dem Shop bekommen. Abmelden geht jederzeit.', 'novemberkind-produkte');
    }
}
