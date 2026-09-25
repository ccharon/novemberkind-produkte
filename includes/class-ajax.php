<?php

declare(strict_types=1);

namespace NovemberkindProdukte;

defined('ABSPATH') || exit;

/**
 * AJAX-Aktionen für das Produktformular. Jede Aktion prüft Nonce und Rechte.
 */
final class Ajax
{
    public const NONCE = 'novemberkind_produkte';

    public function register(): void
    {
        add_action('wp_ajax_novemberkind_produkte_save', [$this, 'save']);
        add_action('wp_ajax_novemberkind_produkte_upload', [$this, 'upload']);
        add_action('wp_ajax_novemberkind_produkte_preview', [$this, 'preview']);
        add_action('wp_ajax_novemberkind_produkte_suggest', [$this, 'suggest']);
        add_action('wp_ajax_novemberkind_produkte_save_campaign', [$this, 'save_campaign']);
        add_action('wp_ajax_novemberkind_produkte_end_campaign', [$this, 'end_campaign']);
        add_action('wp_ajax_novemberkind_produkte_save_coupon', [$this, 'save_coupon']);
        add_action('wp_ajax_novemberkind_produkte_toggle_coupon', [$this, 'toggle_coupon']);
    }

    public function save_coupon(): void
    {
        $this->authorize('edit_shop_coupons');

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- in authorize() geprüft
        $result = (new Coupons())->save($_POST, absint($_POST['id'] ?? 0));
        if (is_wp_error($result)) {
            wp_send_json_error([
                'message' => $result->get_error_message(),
                'fields'  => $result->get_error_data() ?: new \stdClass(),
            ], 422);
        }

        wp_send_json_success([
            'id'      => $result['id'],
            'url'     => App::coupons_url($result['id']),
            'message' => $result['active']
                /* translators: %s: Gutscheincode */
                ? sprintf(__('Gespeichert. Der Code %s ist im Shop einlösbar.', 'novemberkind-produkte'), $result['code'])
                : __('Gespeichert. Der Gutschein bleibt deaktiviert.', 'novemberkind-produkte'),
        ]);
    }

    public function toggle_coupon(): void
    {
        $this->authorize('edit_shop_coupons');

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- in authorize() geprüft
        $active = sanitize_key(wp_unslash($_POST['value'] ?? '')) === 'on';
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- in authorize() geprüft
        $result = (new Coupons())->set_active(absint($_POST['id'] ?? 0), $active);
        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()], 422);
        }

        wp_send_json_success([
            'id'      => $result['id'],
            'url'     => App::coupons_url($result['id']),
            'message' => $active
                ? __('Der Gutschein ist wieder einlösbar.', 'novemberkind-produkte')
                : __('Der Gutschein ist deaktiviert und im Shop nicht mehr einlösbar.', 'novemberkind-produkte'),
        ]);
    }

    public function save_campaign(): void
    {
        $this->authorize();

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- in authorize() geprüft
        $result = (new Campaigns())->save($_POST, absint($_POST['id'] ?? 0));
        if (is_wp_error($result)) {
            wp_send_json_error([
                'message' => $result->get_error_message(),
                'fields'  => $result->get_error_data() ?: new \stdClass(),
            ], 422);
        }

        wp_send_json_success([
            'id'      => $result['id'],
            'url'     => App::campaigns_url($result['id']),
            'message' => match (Campaigns::status($result)) {
                'running' => __('Gespeichert. Die Aktion läuft, die Preise im Shop sind gesenkt.', 'novemberkind-produkte'),
                default   => sprintf(
                    /* translators: 1: Datum, 2: Uhrzeit */
                    __('Gespeichert. Die Aktion beginnt am %1$s um %2$s Uhr.', 'novemberkind-produkte'),
                    wp_date('d.m.Y', $result['start']),
                    wp_date('H:i', $result['start'])
                ),
            },
        ]);
    }

    public function end_campaign(): void
    {
        $this->authorize();

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- in authorize() geprüft
        $result = (new Campaigns())->end(absint($_POST['id'] ?? 0));
        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()], 422);
        }

        wp_send_json_success([
            'id'      => $result['id'],
            'url'     => App::campaigns_url($result['id']),
            'message' => __('Die Aktion ist beendet. Im Shop gelten wieder die normalen Preise.', 'novemberkind-produkte'),
        ]);
    }

    public function save(): void
    {
        $this->authorize();

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- in authorize() geprüft
        $product_id = absint($_POST['product_id'] ?? 0);
        if ($product_id && !current_user_can('edit_post', $product_id)) {
            wp_send_json_error(['message' => __('Dafür fehlen dir die Berechtigungen.', 'novemberkind-produkte')], 403);
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- in authorize() geprüft
        $type = ProductType::get(sanitize_key($_POST['type'] ?? ''));
        if (!$type) {
            wp_send_json_error(['message' => __('Unbekannte Produktart.', 'novemberkind-produkte')], 400);
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- in authorize() geprüft
        $result = (new ProductService())->save($type, $_POST, $product_id);
        if (is_wp_error($result)) {
            wp_send_json_error([
                'message' => $result->get_error_message(),
                'fields'  => $result->get_error_data() ?: new \stdClass(),
            ], 422);
        }

        wp_send_json_success([
            'id'      => $result->get_id(),
            'name'    => $result->get_name(),
            'status'  => $result->get_status(),
            'message' => self::saved_message($result),
            'viewUrl' => get_permalink($result->get_id()),
            'backups' => (new Backups())->summary($result->get_id()),
        ]);
    }

    private static function saved_message(\WC_Product $product): string
    {
        return match ($product->get_status()) {
            'publish' => __('Gespeichert. Das Produkt ist jetzt im Shop zu sehen.', 'novemberkind-produkte'),
            'future'  => sprintf(
                /* translators: 1: Datum, 2: Uhrzeit */
                __('Gespeichert. Das Produkt geht am %1$s um %2$s Uhr online.', 'novemberkind-produkte'),
                wp_date('d.m.Y', $product->get_date_created()?->getTimestamp()),
                wp_date('H:i', $product->get_date_created()?->getTimestamp())
            ),
            default   => __('Als Entwurf gespeichert.', 'novemberkind-produkte'),
        };
    }

    /**
     * Beschreibung aus der Vorlage zu den aktuellen Formularwerten, auch wenn noch nicht alles ausgefüllt ist.
     */
    public function preview(): void
    {
        $this->authorize();

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- in authorize() geprüft
        $type = ProductType::get(sanitize_key($_POST['type'] ?? ''));
        if (!$type) {
            wp_send_json_error(['message' => __('Unbekannte Produktart.', 'novemberkind-produkte')], 400);
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- in authorize() geprüft
        [$context] = $type->parse($_POST);
        wp_send_json_success(['html' => $type->description($context)]);
    }

    /**
     * Vorschlag von Claude für Titel, Beschreibung und Schlagwörter. Speichert nichts.
     */
    public function suggest(): void
    {
        $this->authorize();

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- in authorize() geprüft
        $type = ProductType::get(sanitize_key($_POST['type'] ?? ''));
        if (!$type) {
            wp_send_json_error(['message' => __('Unbekannte Produktart.', 'novemberkind-produkte')], 400);
        }

        if (function_exists('set_time_limit')) {
            set_time_limit(120);
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- in authorize() geprüft
        $result = (new Suggestions())->suggest($type, $_POST);
        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()], 502);
        }

        wp_send_json_success($result);
    }

    public function upload(): void
    {
        $this->authorize('upload_files');

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- in authorize() geprüft
        if (empty($_FILES['file'])) {
            wp_send_json_error(['message' => __('Es wurde kein Foto übertragen.', 'novemberkind-produkte')], 400);
        }

        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput, WordPress.Security.NonceVerification.Missing -- wp_handle_upload prüft die Datei, authorize() die Nonce
        $result = (new ImageProcessor())->handle_upload($_FILES['file']);
        if (is_wp_error($result)) {
            wp_send_json_error([
                'message' => sprintf(
                    /* translators: %s: technische Fehlermeldung */
                    __('Das Foto konnte nicht gespeichert werden (%s).', 'novemberkind-produkte'),
                    $result->get_error_message()
                ),
            ], 500);
        }

        wp_send_json_success([
            'id'  => $result,
            'url' => wp_get_attachment_image_url($result, 'woocommerce_thumbnail'),
        ]);
    }

    private function authorize(string ...$extra_caps): void
    {
        if (!check_ajax_referer(self::NONCE, 'nonce', false)) {
            wp_send_json_error(['message' => __('Die Sitzung ist abgelaufen. Bitte lade die Seite neu.', 'novemberkind-produkte')], 403);
        }

        foreach ([Plugin::CAPABILITY, ...$extra_caps] as $cap) {
            if (!current_user_can($cap)) {
                wp_send_json_error(['message' => __('Dafür fehlen dir die Berechtigungen.', 'novemberkind-produkte')], 403);
            }
        }
    }
}
