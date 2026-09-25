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
    public const KINDS = ['percent', 'shipping'];

    public function register(): void
    {
        add_filter('woocommerce_package_rates', [$this, 'free_shipping_rates'], 20);
    }

    /**
     * Versand-Gutscheine setzen die Kosten aller Versandarten auf 0. So braucht der Shop keine eigene Versandart „Kostenloser Versand“.
     *
     * @param array<string, mixed> $rates
     * @return array<string, mixed>
     */
    public function free_shipping_rates(array $rates): array
    {
        $cart = function_exists('WC') ? WC()->cart : null;
        if (!$cart) {
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
     * @return array<int, array<string, mixed>> alle Gutscheine, neueste zuerst
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
        $code   = strtoupper(trim(sanitize_text_field(wp_unslash((string) ($data['code'] ?? '')))));
        if (!preg_match('/^[A-Z0-9-]{3,30}$/', $code)) {
            $errors['code'] = __('Der Code braucht 3 bis 30 Zeichen: Buchstaben ohne Umlaute, Ziffern und Bindestriche, z. B. HERBST10.', 'novemberkind-produkte');
        } elseif (wc_get_coupon_id_by_code($code, $id) !== 0) {
            /* translators: %s: Gutscheincode */
            $errors['code'] = sprintf(__('Den Code %s gibt es schon.', 'novemberkind-produkte'), $code);
        }

        $kind = (string) ($data['kind'] ?? '');
        if (!in_array($kind, self::KINDS, true)) {
            $kind = 'percent';
        }
        $percent = 0;
        if ($kind === 'percent') {
            $raw     = trim((string) ($data['percent'] ?? ''));
            $percent = ctype_digit($raw) ? (int) $raw : 0;
            if ($percent < 1 || $percent > 100) {
                $errors['percent'] = __('Bitte gib einen Rabatt zwischen 1 und 100 Prozent ein.', 'novemberkind-produkte');
            }
        }

        $expires_raw = trim((string) ($data['expires'] ?? ''));
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
            return new \WP_Error('invalid', __('Bitte prüfe die markierten Felder.', 'novemberkind-produkte'), $errors);
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
            // Kein Gutschein auf Produkte, die schon reduziert sind, auch nicht durch eine Aktion
            $coupon->set_exclude_sale_items(true);
        }
        $coupon->set_date_expires($expires);
        $coupon->set_usage_limit_per_user(!empty($data['once']) ? 1 : 0);
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

    public static function enabled(): bool
    {
        return wc_coupons_enabled();
    }
}
