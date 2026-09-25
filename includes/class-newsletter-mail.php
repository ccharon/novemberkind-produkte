<?php

declare(strict_types=1);

namespace NovemberkindProdukte;

defined('ABSPATH') || exit;

/**
 * HTML und Text der Newsletter-Mails und der Versand über wp_mail mit eigenem Absender.
 *
 * @phpstan-type Card array{name: string, url: string, image: string, price: string, regular: string}
 */
final class NewsletterMail
{
    // Wird pro Empfänger durch das Token ersetzt, damit die Mail nur einmal pro Päckchen gebaut wird
    public const TOKEN_PLACEHOLDER = 'nkptokenplatzhalter';
    private const IMAGE_SIZE = 'woocommerce_single';
    // Farben und Schrift wie auf novemberkind.art
    public const COLORS = [
        'text'   => '#526077',
        'muted'  => '#666666',
        'link'   => '#3a5f95',
        'sky'    => '#d2e4fc',
        'page'   => '#feffff',
        'footer' => '#3e464a',
    ];
    public const FONT = 'Helvetica,Arial,sans-serif';
    private const LOGO_WIDTH = 200;
    // Breite des Textbereichs: 600 px Mail abzüglich Innenabstand
    private const CONTENT_WIDTH = 544;

    private static string $alt_body = '';
    private static string $sender = '';

    /**
     * Absender aus NOVEMBERKIND_PRODUKTE_NEWSLETTER_FROM, sonst aus den E-Mail-Einstellungen von WooCommerce.
     *
     * @return array{name: string, email: string}
     */
    public static function from(): array
    {
        $name  = (string) get_option('woocommerce_email_from_name', get_bloginfo('name'));
        $email = (string) get_option('woocommerce_email_from_address', get_option('admin_email'));
        if (defined('NOVEMBERKIND_PRODUKTE_NEWSLETTER_FROM')) {
            $value = trim((string) constant('NOVEMBERKIND_PRODUKTE_NEWSLETTER_FROM'));
            if (preg_match('/^(.*?)\s*<([^>]+)>$/', $value, $match)) {
                $name  = trim($match[1], " \"'");
                $email = $match[2];
            } elseif ($value !== '') {
                $email = $value;
            }
        }

        return ['name' => wp_specialchars_decode($name, ENT_QUOTES), 'email' => sanitize_email($email)];
    }

    /**
     * Absender zur Anzeige, z. B. „Novemberkind <psst@novemberkind.art>“.
     */
    public static function from_label(): string
    {
        $from = self::from();

        return $from['name'] !== '' ? "{$from['name']} <{$from['email']}>" : $from['email'];
    }

    /**
     * Mail mit Link zur Bestätigung der Anmeldung.
     */
    public static function send_confirmation(string $email, string $confirm_url): bool
    {
        $shop    = wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES);
        /* translators: %s: Name des Shops */
        $subject = sprintf(__('Bitte bestätige deine Anmeldung zum Newsletter von %s', 'novemberkind-produkte'), $shop);
        $content = '<p>' . esc_html__('Hallo,', 'novemberkind-produkte') . '</p>'
            . '<p>' . esc_html(sprintf(
                /* translators: %s: Name des Shops */
                __('du hast dich für den Newsletter von %s angemeldet. Bitte bestätige das mit einem Klick auf den Knopf. Erst danach bekommst du den Newsletter.', 'novemberkind-produkte'),
                $shop
            )) . '</p>'
            . self::button($confirm_url, __('Anmeldung bestätigen', 'novemberkind-produkte'))
            . '<p>' . esc_html__('Wenn du dich nicht angemeldet hast, ignoriere diese Mail einfach. Ohne Bestätigung wird deine Adresse nach einer Woche gelöscht.', 'novemberkind-produkte') . '</p>';

        $html = self::layout($subject, '', $content, '');

        return self::send($email, $subject, $html, self::to_text($html));
    }

    /**
     * Baut die Mail einer Ausgabe. Der Abmeldelink enthält TOKEN_PLACEHOLDER.
     *
     * @param array<string, mixed> $issue
     * @return array{html: string, text: string}
     */
    public static function render(array $issue): array
    {
        $unsubscribe = NewsletterSignup::url('abmelden', self::TOKEN_PLACEHOLDER);
        $content     = self::style_content((string) $issue['content']);
        $cards       = array_filter(array_map([self::class, 'card'], (array) $issue['products']));
        $html        = self::layout((string) $issue['subject'], (string) $issue['preheader'], $content . self::cards_html($cards), $unsubscribe);

        return ['html' => $html, 'text' => self::to_text($html)];
    }

    /**
     * Setzt das Token eines Empfängers in die fertige Mail ein.
     */
    public static function personalize(string $body, string $token): string
    {
        return str_replace(self::TOKEN_PLACEHOLDER, $token, $body);
    }

    /**
     * Verschickt eine Mail über wp_mail mit HTML, Textfassung und dem Absender des Newsletters.
     *
     * @param string[] $headers zusätzliche Kopfzeilen
     */
    public static function send(string $to, string $subject, string $html, string $text, array $headers = []): bool
    {
        $from = self::from();
        $headers = [
            'Content-Type: text/html; charset=UTF-8',
            sprintf('From: %s <%s>', self::encode_name($from['name']), $from['email']),
            'Reply-To: ' . $from['email'],
            ...$headers,
        ];

        self::$alt_body = $text;
        self::$sender   = $from['email'];
        add_action('phpmailer_init', [self::class, 'prepare_mailer']);
        try {
            return wp_mail($to, $subject, $html, $headers);
        } finally {
            remove_action('phpmailer_init', [self::class, 'prepare_mailer']);
            self::$alt_body = '';
            self::$sender   = '';
            // Der Umschlag-Absender bliebe sonst für weitere Mails dieser Anfrage gesetzt
            if (isset($GLOBALS['phpmailer']) && $GLOBALS['phpmailer'] instanceof \PHPMailer\PHPMailer\PHPMailer) {
                $GLOBALS['phpmailer']->Sender = '';
            }
        }
    }

    /**
     * Textfassung und Umschlag-Absender, damit Unzustellbar-Meldungen beim Absender landen.
     */
    public static function prepare_mailer(\PHPMailer\PHPMailer\PHPMailer $mailer): void
    {
        $mailer->AltBody = self::$alt_body;
        if (self::$sender !== '') {
            $mailer->Sender = self::$sender;
        }
    }

    /**
     * Kopfzeilen für die Ein-Klick-Abmeldung nach RFC 8058.
     *
     * @return string[]
     */
    public static function unsubscribe_headers(string $token): array
    {
        return [
            'List-Unsubscribe: <' . NewsletterSignup::url('abmelden', $token) . '>',
            'List-Unsubscribe-Post: List-Unsubscribe=One-Click',
        ];
    }

    /**
     * Rahmen der Mail: Kopf mit Logo oder Shopname, Inhalt, Fuß mit Adresse und rechtlichen Links.
     */
    private static function layout(string $subject, string $preheader, string $content, string $unsubscribe_url): string
    {
        $shop    = get_bloginfo('name');
        $home    = home_url('/');
        $footer  = self::footer_lines();
        $imprint = function_exists('wc_gzd_get_page_permalink') ? (string) wc_gzd_get_page_permalink('imprint') : '';
        $privacy = get_privacy_policy_url();
        $logo    = self::logo_url();
        $colors  = self::COLORS;
        $font    = self::FONT;
        $logo_width = self::LOGO_WIDTH;

        ob_start();
        include __DIR__ . '/../templates/newsletter-mail.php';

        return (string) ob_get_clean();
    }

    /**
     * Logo des Shops: WordPress-Logo, sonst das Logo aus den Divi-Einstellungen, sonst keins.
     */
    private static function logo_url(): string
    {
        $logo_id = (int) (get_theme_mod('custom_logo') ?: get_option('site_logo'));
        $url     = $logo_id ? wp_get_attachment_image_url($logo_id, 'medium') : false;
        if (!$url && function_exists('et_get_option')) {
            $url = (string) et_get_option('divi_logo', '');
        }

        return $url ?: '';
    }

    /**
     * Name und Adresse des Shops aus den WooCommerce-Einstellungen.
     *
     * @return string[]
     */
    private static function footer_lines(): array
    {
        $street = trim(get_option('woocommerce_store_address', '') . ' ' . get_option('woocommerce_store_address_2', ''));
        $city   = trim(get_option('woocommerce_store_postcode', '') . ' ' . get_option('woocommerce_store_city', ''));

        return array_values(array_filter([wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES), $street, $city]));
    }

    /**
     * Inline-Stile für den Text aus dem Editor, weil viele Mailprogramme Stilblöcke ignorieren.
     */
    private static function style_content(string $html): string
    {
        $colors = self::COLORS;
        $styles = [
            'p'      => "margin:0 0 16px;font-size:16px;line-height:1.7;color:{$colors['text']};",
            'h2'     => "margin:28px 0 12px;font-size:24px;line-height:1.3;font-weight:normal;color:{$colors['text']};",
            'a'      => "color:{$colors['link']};font-weight:bold;font-style:italic;text-decoration:none;",
            'strong' => 'font-weight:bold;',
        ];
        foreach ($styles as $tag => $style) {
            $html = (string) preg_replace('/<' . $tag . '(\s[^>]*)?>/i', '<' . $tag . '$1 style="' . $style . '">', $html);
        }

        return (string) preg_replace_callback('/<img\s[^>]*>/i', [self::class, 'content_image'], $html);
    }

    /**
     * Foto aus dem Text in voller Breite, als JPEG-Fassung aus der Mediathek. Fremde Adressen fallen weg.
     *
     * @param array<int, string> $found Treffer von preg_replace_callback, [0] ist das ganze img-Element
     */
    private static function content_image(array $found): string
    {
        if (!preg_match('/class="[^"]*\bwp-image-(\d+)\b/', $found[0], $id) || !wp_attachment_is_image((int) $id[1])) {
            return '';
        }
        $url = ImageProcessor::mail_url((int) $id[1], 'full');
        preg_match('/alt="([^"]*)"/', $found[0], $alt);

        return '<img src="' . esc_url($url) . '" alt="' . esc_attr(html_entity_decode($alt[1] ?? '', ENT_QUOTES, 'UTF-8')) . '" width="' . self::CONTENT_WIDTH . '"'
            . ' style="display:block;width:100%;max-width:' . self::CONTENT_WIDTH . 'px;height:auto;border:0;margin:8px 0 16px;">';
    }

    /**
     * Daten einer Produktkarte zum Zeitpunkt des Versands; nicht sichtbare Produkte fallen weg.
     *
     * @return array<string, string>|null
     * @phpstan-return Card|null
     */
    private static function card(mixed $product_id): ?array
    {
        $product = wc_get_product((int) $product_id);
        if (!$product || $product->get_status() !== 'publish' || !$product->is_visible() || $product->get_post_password() !== '') {
            return null;
        }

        $image = $product->get_image_id() ? ImageProcessor::mail_url((int) $product->get_image_id(), self::IMAGE_SIZE) : '';
        [$price, $regular] = self::prices($product);

        return [
            'name'    => $product->get_name(),
            'url'     => (string) get_permalink($product->get_id()),
            'image'   => $image ?: wc_placeholder_img_src(self::IMAGE_SIZE),
            'price'   => $price,
            'regular' => $regular,
        ];
    }

    /**
     * Aktueller Preis und, falls reduziert, der Normalpreis als Text, z. B. „ab 2,50 €“.
     *
     * @return array{0: string, 1: string}
     */
    private static function prices(\WC_Product $product): array
    {
        if ($product instanceof \WC_Product_Variable) {
            $min         = (float) $product->get_variation_price('min', true);
            $max         = (float) $product->get_variation_price('max', true);
            $regular_min = (float) $product->get_variation_regular_price('min', true);
            /* translators: %s: Preis */
            $price = $min === $max ? self::money($min) : sprintf(__('ab %s', 'novemberkind-produkte'), self::money($min));
            /* translators: %s: Preis */
            $regular = $regular_min > $min ? ($min === $max ? self::money($regular_min) : sprintf(__('ab %s', 'novemberkind-produkte'), self::money($regular_min))) : '';

            return [$price, $regular];
        }

        $current = (float) wc_get_price_to_display($product);
        $regular = (float) wc_get_price_to_display($product, ['price' => $product->get_regular_price()]);

        return [self::money($current), $regular > $current ? self::money($regular) : ''];
    }

    private static function money(float $amount): string
    {
        return html_entity_decode(wp_strip_all_tags(wc_price($amount)), ENT_QUOTES, 'UTF-8');
    }

    /**
     * Produkte als Tabelle mit zwei Spalten, weil Mailprogramme kein Grid und kaum Flexbox kennen.
     *
     * @param array<int, array<string, string>> $cards
     */
    private static function cards_html(array $cards): string
    {
        if ($cards === []) {
            return '';
        }

        $cells = array_map(static function (array $card): string {
            $colors = self::COLORS;
            $price = $card['regular'] !== ''
                ? '<del style="color:' . $colors['muted'] . ';">' . esc_html($card['regular']) . '</del> <strong style="color:' . $colors['text'] . ';">' . esc_html($card['price']) . '</strong>'
                : esc_html($card['price']);

            return '<td class="nkp-mail-card" width="50%" valign="top" align="center" style="padding:10px;">'
                . '<a href="' . esc_url($card['url']) . '" style="text-decoration:none;color:' . $colors['text'] . ';">'
                . '<img src="' . esc_url($card['image']) . '" alt="' . esc_attr($card['name']) . '" width="250" style="display:block;width:100%;max-width:250px;height:auto;border:0;margin:0 auto;">'
                . '<span style="display:block;margin-top:10px;font-size:15px;line-height:1.4;">' . esc_html($card['name']) . '</span>'
                . '</a>'
                . '<span style="display:block;margin-top:4px;font-size:14px;color:' . $colors['muted'] . ';">' . $price . '</span>'
                . '</td>';
        }, array_values($cards));

        $rows = '';
        foreach (array_chunk($cells, 2) as $pair) {
            $rows .= '<tr>' . implode('', $pair) . (count($pair) === 1 ? '<td class="nkp-mail-card" width="50%" style="padding:10px;"></td>' : '') . '</tr>';
        }

        return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:16px 0 0;">' . $rows . '</table>';
    }

    /**
     * Knopf als Tabelle, damit er auch in Outlook als Fläche erscheint.
     */
    private static function button(string $url, string $label): string
    {
        return '<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:8px 0 24px;"><tr>'
            . '<td style="border:2px solid ' . self::COLORS['text'] . ';border-radius:3px;">'
            . '<a href="' . esc_url($url) . '" style="display:inline-block;padding:10px 22px;font-size:18px;color:' . self::COLORS['text'] . ';text-decoration:none;font-weight:500;">' . esc_html($label) . '</a>'
            . '</td></tr></table>';
    }

    /**
     * Textfassung aus dem HTML: Absätze als Leerzeilen, Links mit Adresse in Klammern.
     */
    public static function to_text(string $html): string
    {
        $html = (string) preg_replace('#<(head|style|script)\b.*?</\1>#is', '', $html);
        $html = (string) preg_replace('#<span[^>]*class="nkp-mail-preheader"[^>]*>.*?</span>#is', '', $html);
        $html = (string) preg_replace_callback('#<a\s[^>]*href="([^"]+)"[^>]*>(.*?)</a>#is', static function (array $link): string {
            $label = trim(wp_strip_all_tags($link[2]));
            $url   = html_entity_decode($link[1], ENT_QUOTES, 'UTF-8');

            return $label === '' || $label === $url ? $url : "{$label} ({$url})";
        }, $html);
        $html = (string) preg_replace('#<(br|/p|/h[1-6]|/tr|/table|/div)\b[^>]*>#i', "\n", $html);
        $html = (string) preg_replace('#</td>#i', "\n\n", $html);
        $text = html_entity_decode(wp_strip_all_tags($html), ENT_QUOTES, 'UTF-8');
        $text = (string) preg_replace('/[ \t]+/', ' ', $text);
        $text = (string) preg_replace('/ *\n */', "\n", $text);

        return trim((string) preg_replace("/\n{3,}/", "\n\n", $text)) . "\n";
    }

    /**
     * Anzeigename für die From-Zeile, mit Anführungszeichen, falls er Sonderzeichen enthält.
     */
    private static function encode_name(string $name): string
    {
        return preg_match('/[^\w\s-]/u', $name) ? '"' . str_replace('"', '', $name) . '"' : $name;
    }
}
