<?php

declare(strict_types=1);

namespace NovemberkindProdukte;

defined('ABSPATH') || exit;

/**
 * Gutscheincodes über die WooCommerce-Gutscheine: Prozent-Rabatt oder kostenloser Versand mit den vorhandenen Versandarten.
 * Gutscheine werden nie gelöscht, nur deaktiviert.
 *
 * @phpstan-type Coupon array{id: int, code: string, kind: string, percent: int, expires: string, once: bool, active: bool, expired: bool, usage: int, own: bool, edit_url: string}
 */
final class Coupons
{
    // Kennzeichnet Gutscheine aus diesem Plugin; andere werden in WooCommerce bearbeitet
    public const META_OWN = '_novemberkind_produkte_coupon';
    public const CAPABILITY = 'edit_shop_coupons';
    public const KINDS = ['percent', 'shipping'];
    public const CODE_MIN_LENGTH = 3;
    public const CODE_MAX_LENGTH = 30;
    private const CODE_PATTERN = '/^[A-Z0-9-]{' . self::CODE_MIN_LENGTH . ',' . self::CODE_MAX_LENGTH . '}$/';
    public const MAX_PERCENT = 100;
    // Nach anderen Versandfiltern (Standard 10), damit am Ende wirklich 0 steht
    private const FILTER_PRIORITY = 20;

    /**
     * Meldet den Filter für kostenlosen Versand an.
     */
    public function register(): void
    {
        add_filter('woocommerce_package_rates', [$this, 'free_shipping_rates'], self::FILTER_PRIORITY);
    }

    /**
     * Versand-Gutscheine setzen die Kosten aller Versandarten auf 0. So braucht der Shop keine eigene Versandart „Kostenloser Versand“.
     * Nimmt beliebige Werte an, weil auch andere Plugins diesen Filter auslösen.
     */
    public function free_shipping_rates(mixed $rates): mixed
    {
        $cart = function_exists('WC') ? WC()->cart : null;
        if (!$cart || !is_array($rates)) {
            return $rates;
        }
        $free = false;
        foreach ($cart->get_coupons() as $coupon) {
            $free = $free || ($coupon->get_free_shipping() && $coupon->get_meta(self::META_OWN) === 'yes');
        }
        if (!$free) {
            return $rates;
        }

        foreach ($rates as $rate) {
            if ($rate instanceof \WC_Shipping_Rate) {
                $rate->set_cost('0');
                $rate->set_taxes(array_map(static fn(): float => 0.0, $rate->get_taxes()));
            }
        }

        return $rates;
    }

    /**
     * Alle Gutscheine des Shops, auch die aus WooCommerce, neueste zuerst.
     *
     * @return array<int, array<string, mixed>>
     * @phpstan-return array<int, Coupon>
     */
    public static function all(): array
    {
        $posts = get_posts([
            'post_type'      => 'shop_coupon',
            'post_status'    => ['publish', 'draft', 'pending', 'private', 'future'],
            'posts_per_page' => -1,
            'orderby'        => 'date',
            'order'          => 'DESC',
            'no_found_rows'  => true,
        ]);

        return array_map(static fn(\WP_Post $post): array => self::data(new \WC_Coupon($post->ID)), $posts);
    }

    /**
     * Ein Gutschein oder null, wenn die ID kein Gutschein ist.
     *
     * @return array<string, mixed>|null
     * @phpstan-return Coupon|null
     */
    public static function get(int $id): ?array
    {
        if ($id <= 0 || get_post_type($id) !== 'shop_coupon') {
            return null;
        }

        return self::data(new \WC_Coupon($id));
    }

    /**
     * Werte eines Gutscheins für Liste und Formular.
     *
     * @return array<string, mixed>
     * @phpstan-return Coupon
     */
    public static function data(\WC_Coupon $coupon): array
    {
        $expires = $coupon->get_date_expires();
        $shipping = $coupon->get_free_shipping() && (float) $coupon->get_amount() === 0.0;

        return [
            'id'       => $coupon->get_id(),
            'code'     => strtoupper($coupon->get_code()),
            'kind'     => $shipping ? 'shipping' : ($coupon->get_discount_type() === 'percent' ? 'percent' : 'other'),
            'percent'  => (int) $coupon->get_amount(),
            // WooCommerce lässt den Gutschein um 0 Uhr am Ablaufdatum enden, das Formular zeigt den letzten gültigen Tag
            'expires'  => $expires ? wp_date('Y-m-d', $expires->getTimestamp() - DAY_IN_SECONDS) : '',
            'once'     => $coupon->get_usage_limit_per_user() === 1,
            'active'   => $coupon->get_status() === 'publish',
            'expired'  => $expires !== null && $expires->getTimestamp() <= time(),
            'usage'    => $coupon->get_usage_count(),
            'own'      => $coupon->get_meta(self::META_OWN) === 'yes',
            'edit_url' => (string) get_edit_post_link($coupon->get_id(), 'raw'),
        ];
    }

    /**
     * Plakette für den Zustand eines Gutscheins: aktiv, abgelaufen oder deaktiviert.
     *
     * @param array<string, mixed> $coupon
     * @phpstan-param Coupon $coupon
     * @return array{badge: string, label: string}
     */
    public static function badge(array $coupon): array
    {
        return match (true) {
            !$coupon['active'] => ['badge' => 'draft', 'label' => __('Deaktiviert', 'novemberkind-produkte')],
            $coupon['expired'] => ['badge' => 'draft', 'label' => __('Abgelaufen', 'novemberkind-produkte')],
            default            => ['badge' => 'online', 'label' => __('Aktiv', 'novemberkind-produkte')],
        };
    }

    /**
     * Legt einen eigenen Gutschein an oder ändert ihn. Gutscheine aus WooCommerce ändert das Plugin nicht.
     *
     * @param array<string, mixed> $data Rohdaten aus dem Formular
     * @return array<string, mixed>|\WP_Error
     * @phpstan-return Coupon|\WP_Error
     */
    public function save(array $data, int $id = 0): array|\WP_Error
    {
        $existing = $id ? self::get($id) : null;
        if ($id && ($existing === null || !$existing['own'])) {
            return new \WP_Error('not_found', __('Diesen Gutschein gibt es nicht oder er wird in WooCommerce bearbeitet.', 'novemberkind-produkte'));
        }

        $errors = [];
        $code   = strtoupper(Input::text($data, 'code'));
        if (!preg_match(self::CODE_PATTERN, $code)) {
            $errors['code'] = sprintf(
                /* translators: 1: kleinste, 2: größte Anzahl Zeichen */
                __('Der Code braucht %1$d bis %2$d Zeichen: Buchstaben ohne Umlaute, Ziffern und Bindestriche, z. B. HERBST10.', 'novemberkind-produkte'),
                self::CODE_MIN_LENGTH,
                self::CODE_MAX_LENGTH
            );
        } elseif (wc_get_coupon_id_by_code($code, $id) !== 0) {
            /* translators: %s: Gutscheincode */
            $errors['code'] = sprintf(__('Den Code %s gibt es schon.', 'novemberkind-produkte'), $code);
        }

        $kind    = Input::choice($data, 'kind', self::KINDS, 'percent');
        $percent = 0;
        if ($kind === 'percent') {
            $percent = Input::percent($data, 'percent', self::MAX_PERCENT) ?? 0;
            if ($percent === 0) {
                $errors['percent'] = Input::percent_error(self::MAX_PERCENT);
            }
        }

        $expires_raw = Input::text($data, 'expires');
        $expires     = null;
        if ($expires_raw !== '') {
            $day = \DateTimeImmutable::createFromFormat('!Y-m-d', $expires_raw, wp_timezone());
            if ($day === false || $day->format('Y-m-d') !== $expires_raw) {
                $errors['expires'] = __('Bitte wähle ein gültiges Datum.', 'novemberkind-produkte');
            } elseif ($day->modify('+1 day')->getTimestamp() <= time()) {
                $errors['expires'] = __('Das Datum liegt in der Vergangenheit.', 'novemberkind-produkte');
            } else {
                $expires = $day->modify('+1 day')->getTimestamp();
            }
        }

        if ($errors !== []) {
            return Input::invalid($errors);
        }

        $coupon = new \WC_Coupon($id);
        $coupon->set_code($code);
        if ($kind === 'shipping') {
            $coupon->set_discount_type('fixed_cart');
            $coupon->set_amount(0);
            $coupon->set_free_shipping(true);
            $coupon->set_exclude_sale_items(false);
        } else {
            $coupon->set_discount_type('percent');
            $coupon->set_amount($percent);
            $coupon->set_free_shipping(false);
            // Gilt nur für Produkte zum Normalpreis; Preise aus einer Aktion zählen als reduziert
            $coupon->set_exclude_sale_items(true);
        }
        $coupon->set_date_expires($expires);
        $coupon->set_usage_limit_per_user(Input::value($data, 'once') === '1' ? 1 : 0);
        if ($id === 0) {
            $coupon->set_status('publish');
        }
        $coupon->update_meta_data(self::META_OWN, 'yes');
        $coupon->save();

        return $coupon->get_id() ? self::data($coupon) : new \WP_Error('save', __('Der Gutschein konnte nicht gespeichert werden.', 'novemberkind-produkte'));
    }

    /**
     * Schaltet einen Gutschein ein oder aus. Ausgeschaltet ist er ein Entwurf und im Shop ungültig.
     *
     * @return array<string, mixed>|\WP_Error
     * @phpstan-return Coupon|\WP_Error
     */
    public function set_active(int $id, bool $active): array|\WP_Error
    {
        $existing = self::get($id);
        if ($existing === null || !$existing['own']) {
            return new \WP_Error('not_found', __('Diesen Gutschein gibt es nicht oder er wird in WooCommerce bearbeitet.', 'novemberkind-produkte'));
        }

        $coupon = new \WC_Coupon($id);
        $coupon->set_status($active ? 'publish' : 'draft');
        $coupon->save();

        return self::data($coupon);
    }

    /**
     * Ob Gutscheincodes in den WooCommerce-Einstellungen eingeschaltet sind.
     */
    public static function enabled(): bool
    {
        return wc_coupons_enabled();
    }
}
