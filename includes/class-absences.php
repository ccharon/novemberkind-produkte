<?php

declare(strict_types=1);

namespace NovemberkindProdukte;

defined('ABSPATH') || exit;

/**
 * Abwesenheiten: Hinweis im Shop, dass Bestellungen später verschickt werden, und angepasste Lieferzeit.
 * Der Shop bleibt geöffnet. Beginn und Ende prüft jede Anfrage selbst.
 *
 * @phpstan-type Absence array{id: int, text: string, start: int, end: int, announce: int}
 */
final class Absences
{
    public const POST_TYPE = 'novemberkind_pause';
    public const META = '_novemberkind_produkte_absence';
    public const SHORTCODE = 'novemberkind_abwesenheit';
    public const TEXT_MAX_LENGTH = 300;
    private const STYLE = 'novemberkind-produkte-absence';

    /** @var array<int, array<string, mixed>>|null alle Abwesenheiten dieser Anfrage */
    private static ?array $cache = null;

    /**
     * Meldet Inhaltstyp, Shortcode, Hinweise in Warenkorb und Kasse und die Lieferzeit an.
     */
    public function register(): void
    {
        add_action('init', [$this, 'register_post_type']);
        add_shortcode(self::SHORTCODE, [$this, 'shortcode']);
        add_action('woocommerce_before_cart', [$this, 'print_cart_notice']);
        add_action('woocommerce_before_checkout_form', [$this, 'print_cart_notice']);
        add_filter('render_block_woocommerce/cart', [$this, 'prepend_to_block']);
        add_filter('render_block_woocommerce/checkout', [$this, 'prepend_to_block']);
        add_filter('woocommerce_germanized_delivery_time_html', [$this, 'delivery_time_html'], 10, 4);
        add_action('save_post_' . self::POST_TYPE, [self::class, 'flush']);
    }

    /**
     * Privater Inhaltstyp für Abwesenheiten, ohne Backend-Oberfläche, REST und Export.
     */
    public function register_post_type(): void
    {
        Plugin::register_private_post_type(self::POST_TYPE, __('Abwesenheiten', 'novemberkind-produkte'));
    }

    /**
     * Verwirft den Zwischenspeicher dieser Anfrage nach einer Änderung.
     */
    public static function flush(): void
    {
        self::$cache = null;
    }

    /**
     * Alle Abwesenheiten, zuletzt beginnende zuerst.
     *
     * @return array<int, array<string, mixed>>
     * @phpstan-return array<int, Absence>
     */
    public static function all(): array
    {
        if (self::$cache === null) {
            self::$cache = [];
            foreach (Plugin::private_posts(self::POST_TYPE) as $post) {
                $absence = self::from_post($post);
                if ($absence !== null) {
                    self::$cache[$absence['id']] = $absence;
                }
            }
            uasort(self::$cache, static fn(array $a, array $b): int => $b['start'] <=> $a['start'] ?: $b['id'] <=> $a['id']);
            // Erlaubt Tests, nur ihre eigenen Abwesenheiten zu berücksichtigen
            self::$cache = (array) apply_filters('novemberkind_produkte_absences', self::$cache);
        }

        return self::$cache;
    }

    /**
     * Eine Abwesenheit oder null, wenn es sie nicht gibt.
     *
     * @return array<string, mixed>|null
     * @phpstan-return Absence|null
     */
    public static function get(int $id): ?array
    {
        return self::all()[$id] ?? null;
    }

    /**
     * Status zum Zeitpunkt `$now`, ohne Angabe jetzt. Ein Ende von 0 heißt: offen.
     *
     * @phpstan-param Absence $absence
     * @param array<string, mixed> $absence
     * @return string planned, running oder ended
     */
    public static function status(array $absence, ?int $now = null): string
    {
        $now ??= time();

        return match (true) {
            $absence['end'] !== 0 && ($absence['end'] <= $now || $absence['end'] <= $absence['start']) => 'ended',
            $absence['start'] > $now                                                                 => 'planned',
            default                                                                                  => 'running',
        };
    }

    /**
     * Plakette für den Status einer Abwesenheit.
     *
     * @return array{badge: string, label: string}
     */
    public static function badge(string $status): array
    {
        return match ($status) {
            'running' => ['badge' => 'campaign-running', 'label' => __('Läuft gerade', 'novemberkind-produkte')],
            'planned' => ['badge' => 'campaign-planned', 'label' => __('Geplant', 'novemberkind-produkte')],
            default   => ['badge' => 'campaign-ended', 'label' => __('Beendet', 'novemberkind-produkte')],
        };
    }

    /**
     * Die Abwesenheit, die der Shop gerade zeigt: die laufende, sonst eine angekündigte.
     *
     * @return array<string, mixed>|null
     * @phpstan-return Absence|null
     */
    public static function current(?int $now = null): ?array
    {
        $now ??= time();
        $announced = null;
        foreach (self::all() as $absence) {
            $status = self::status($absence, $now);
            if ($status === 'running') {
                return $absence;
            }
            if ($status === 'planned' && $absence['announce'] !== 0 && $absence['announce'] <= $now && ($announced === null || $absence['start'] < $announced['start'])) {
                $announced = $absence;
            }
        }

        return $announced;
    }

    /**
     * Legt eine Abwesenheit an oder ändert sie. Bei einer laufenden bleibt der Beginn, wie er war.
     *
     * @param array<string, mixed> $data Rohdaten aus dem Formular
     * @return array<string, mixed>|\WP_Error
     * @phpstan-return Absence|\WP_Error
     */
    public function save(array $data, int $id = 0): array|\WP_Error
    {
        $existing = $id ? self::get($id) : null;
        if ($id && $existing === null) {
            return new \WP_Error('not_found', __('Diese Abwesenheit gibt es nicht mehr.', 'novemberkind-produkte'));
        }
        if ($existing !== null && self::status($existing) === 'ended') {
            return new \WP_Error('ended', __('Beendete Abwesenheiten lassen sich nicht mehr ändern. Lege bei Bedarf eine neue an.', 'novemberkind-produkte'));
        }

        $now    = time();
        $errors = [];
        $text   = Input::textarea($data, 'text');
        if (mb_strlen($text) > self::TEXT_MAX_LENGTH) {
            /* translators: %d: größte Anzahl Zeichen */
            $errors['text'] = sprintf(__('Der Text darf höchstens %d Zeichen lang sein.', 'novemberkind-produkte'), self::TEXT_MAX_LENGTH);
        }

        if ($existing !== null && self::status($existing) === 'running') {
            $start = $existing['start'];
        } elseif (Input::choice($data, 'start', ['now', 'later'], 'now') === 'now') {
            $start = $now;
        } else {
            $start = Input::datetime($data, 'start_at');
            if ($start === null) {
                $errors['start_at'] = __('Bitte wähle, wann die Abwesenheit beginnt.', 'novemberkind-produkte');
            }
        }

        $end = 0;
        if (Input::choice($data, 'end', ['open', 'until'], 'open') === 'until') {
            $end = Input::datetime($data, 'end_at', '00:00');
            if ($end === null) {
                $errors['end_at'] = __('Bitte wähle, wann du wieder da bist.', 'novemberkind-produkte');
            } elseif ($end <= $now) {
                $errors['end_at'] = __('Das Ende liegt in der Vergangenheit.', 'novemberkind-produkte');
            } elseif ($start !== null && $end <= $start) {
                $errors['end_at'] = __('Das Ende muss nach dem Beginn liegen.', 'novemberkind-produkte');
            }
        }

        $announce = 0;
        if ($start !== null && $start > $now && Input::text($data, 'announce_date') !== '') {
            $announce = Input::datetime($data, 'announce', '00:00');
            if ($announce === null) {
                $errors['announce'] = __('Bitte gib ein gültiges Datum ein.', 'novemberkind-produkte');
            } elseif ($announce >= $start) {
                $errors['announce'] = __('Die Ankündigung muss vor dem Beginn liegen.', 'novemberkind-produkte');
            }
        }

        if ($errors === [] && $start !== null && $end !== null) {
            $other = self::overlapping($id, $start, $end);
            if ($other !== null) {
                $errors[$existing !== null && self::status($existing) === 'running' ? 'end_at' : 'start_at'] = sprintf(
                    /* translators: %s: Zeitraum der anderen Abwesenheit */
                    __('Überschneidet sich mit der Abwesenheit %s. Bitte passe eine von beiden an.', 'novemberkind-produkte'),
                    self::period_label($other)
                );
            }
        }

        if ($errors !== []) {
            return Input::invalid($errors);
        }

        $post_id = wp_insert_post([
            'ID'          => $id,
            'post_type'   => self::POST_TYPE,
            'post_status' => 'private',
            'post_title'  => __('Abwesenheit', 'novemberkind-produkte'),
        ], true);
        if (is_wp_error($post_id)) {
            return new \WP_Error('save', __('Die Abwesenheit konnte nicht gespeichert werden.', 'novemberkind-produkte'));
        }

        return $this->store($post_id, ['text' => $text, 'start' => (int) $start, 'end' => (int) $end, 'announce' => (int) $announce]);
    }

    /**
     * Beendet eine laufende Abwesenheit sofort. Eine geplante beginnt gar nicht erst.
     *
     * @return array<string, mixed>|\WP_Error
     * @phpstan-return Absence|\WP_Error
     */
    public function end(int $id): array|\WP_Error
    {
        $absence = self::get($id);
        if ($absence === null) {
            return new \WP_Error('not_found', __('Diese Abwesenheit gibt es nicht mehr.', 'novemberkind-produkte'));
        }
        if (self::status($absence) === 'ended') {
            return $absence;
        }

        $now = time();
        $absence['start'] = min($absence['start'], $now);
        $absence['end']   = $now;

        return $this->store($id, $absence);
    }

    /**
     * Zeitraum für Listen und Meldungen, z. B. „10.10.2026 09:00 bis 24.10.2026 18:00“.
     *
     * @phpstan-param Absence $absence
     * @param array<string, mixed> $absence
     */
    public static function period_label(array $absence): string
    {
        $start = wp_date('d.m.Y H:i', $absence['start']);
        if ($absence['end'] === 0) {
            /* translators: %s: Beginn */
            return sprintf(__('ab %s, Ende offen', 'novemberkind-produkte'), $start);
        }

        /* translators: 1: Beginn, 2: Ende */
        return sprintf(__('%1$s bis %2$s', 'novemberkind-produkte'), $start, wp_date('d.m.Y H:i', $absence['end']));
    }

    /**
     * Satz mit den Daten, der im Shop unter dem eigenen Text steht.
     *
     * @phpstan-param Absence $absence
     * @param array<string, mixed> $absence
     */
    public static function shipping_line(array $absence, ?int $now = null): string
    {
        $day = static fn(int $timestamp): string => wp_date('j. F', $timestamp);
        if (self::status($absence, $now) === 'planned') {
            return $absence['end'] === 0
                /* translators: %s: Beginn, z. B. 10. Oktober */
                ? sprintf(__('Ab dem %s werden Bestellungen vorerst nicht verschickt.', 'novemberkind-produkte'), $day($absence['start']))
                /* translators: 1: Beginn, 2: Ende */
                : sprintf(__('Vom %1$s bis %2$s werden keine Bestellungen verschickt.', 'novemberkind-produkte'), $day($absence['start']), $day($absence['end']));
        }

        return $absence['end'] === 0
            ? __('Bestellungen sind möglich und werden verschickt, sobald ich zurück bin.', 'novemberkind-produkte')
            /* translators: %s: Ende, z. B. 24. Oktober */
            : sprintf(__('Bestellungen sind möglich und werden ab dem %s verschickt.', 'novemberkind-produkte'), $day($absence['end']));
    }

    /**
     * Hinweis für die Startseite; ohne laufende oder angekündigte Abwesenheit leer.
     */
    public function shortcode(): string
    {
        return self::notice_html();
    }

    /**
     * Hinweis über Warenkorb und Kasse, wenn etwas verschickt werden muss.
     */
    public function print_cart_notice(): void
    {
        echo self::cart_notice(); // phpcs:ignore WordPress.Security.EscapeOutput -- in notice_html() escapt
    }

    /**
     * Setzt den Hinweis vor den Block von Warenkorb oder Kasse.
     * Nimmt beliebige Werte an, weil auch andere Plugins diese Filter auslösen.
     */
    public function prepend_to_block(mixed $content): mixed
    {
        return is_string($content) ? self::cart_notice() . $content : $content;
    }

    /**
     * Ergänzt die Lieferzeit von Germanized während einer laufenden Abwesenheit,
     * z. B. „1-3 Werktage ab 25.10.“. Nimmt beliebige Werte an, weil auch andere Plugins den Filter auslösen.
     */
    public function delivery_time_html(mixed $html, mixed $template = '', mixed $name = '', mixed $gzd_product = null): mixed
    {
        if (!is_string($html) || !is_string($name) || $name === '' || !is_object($gzd_product) || !method_exists($gzd_product, 'get_wc_product')) {
            return $html;
        }
        $product = $gzd_product->get_wc_product();
        if (!$product instanceof \WC_Product || $product->is_virtual()) {
            return $html;
        }
        $absence = self::current();
        if ($absence === null || self::status($absence) !== 'running') {
            return $html;
        }

        $adjusted = $absence['end'] === 0
            /* translators: %s: übliche Lieferzeit, z. B. 1-3 Werktage */
            ? sprintf(__('%s nach meiner Rückkehr', 'novemberkind-produkte'), $name)
            /* translators: 1: übliche Lieferzeit, z. B. 1-3 Werktage, 2: Datum */
            : sprintf(__('%1$s ab %2$s', 'novemberkind-produkte'), $name, wp_date('d.m.', $absence['end']));

        return str_replace('>' . $name . '</span>', '>' . esc_html($adjusted) . '</span>', $html);
    }

    private static function cart_notice(): string
    {
        $cart = function_exists('WC') ? WC()->cart : null;
        if ($cart instanceof \WC_Cart && !$cart->is_empty() && !$cart->needs_shipping()) {
            return '';
        }

        return self::notice_html();
    }

    private static function notice_html(): string
    {
        $absence = self::current();
        if ($absence === null) {
            return '';
        }
        wp_enqueue_style(self::STYLE, Plugin::asset_url('assets/css/absence.css'), [], Plugin::asset_version('assets/css/absence.css'));

        $html = '<div class="nkp-absence" role="note">';
        if ($absence['text'] !== '') {
            $html .= '<p class="nkp-absence__text">' . nl2br(esc_html($absence['text'])) . '</p>';
        }
        $html .= '<p class="nkp-absence__shipping">' . esc_html(self::shipping_line($absence)) . '</p>';

        return $html . '</div>';
    }

    /**
     * Eine andere nicht beendete Abwesenheit, deren Zeitraum sich mit dem angegebenen überschneidet.
     *
     * @return array<string, mixed>|null
     * @phpstan-return Absence|null
     */
    private static function overlapping(int $id, int $start, int $end): ?array
    {
        foreach (self::all() as $other) {
            if ($other['id'] === $id || self::status($other) === 'ended') {
                continue;
            }
            $other_end = $other['end'] === 0 ? PHP_INT_MAX : $other['end'];
            if ($other['start'] < ($end === 0 ? PHP_INT_MAX : $end) && $other_end > $start) {
                return $other;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $values
     * @return array<string, mixed>|\WP_Error
     * @phpstan-return Absence|\WP_Error
     */
    private function store(int $post_id, array $values): array|\WP_Error
    {
        update_post_meta($post_id, self::META, [
            'text'     => (string) $values['text'],
            'start'    => (int) $values['start'],
            'end'      => (int) $values['end'],
            'announce' => (int) $values['announce'],
        ]);
        self::flush();

        return self::get($post_id) ?? new \WP_Error('save', __('Die Abwesenheit konnte nicht gespeichert werden.', 'novemberkind-produkte'));
    }

    /**
     * @return array<string, mixed>|null
     * @phpstan-return Absence|null
     */
    private static function from_post(\WP_Post $post): ?array
    {
        $meta = get_post_meta($post->ID, self::META, true);
        if (!is_array($meta)) {
            return null;
        }

        return [
            'id'       => $post->ID,
            'text'     => (string) ($meta['text'] ?? ''),
            'start'    => (int) ($meta['start'] ?? 0),
            'end'      => (int) ($meta['end'] ?? 0),
            'announce' => (int) ($meta['announce'] ?? 0),
        ];
    }
}
