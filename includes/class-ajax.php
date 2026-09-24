<?php

declare(strict_types=1);

namespace EasyProduct;

defined('ABSPATH') || exit;

/**
 * AJAX-Aktionen für das Produktformular. Jede Aktion prüft Nonce und Rechte.
 */
final class Ajax
{
    public const NONCE = 'easy_product';

    public function register(): void
    {
        add_action('wp_ajax_easy_product_save', [$this, 'save']);
        add_action('wp_ajax_easy_product_upload', [$this, 'upload']);
        add_action('wp_ajax_easy_product_preview', [$this, 'preview']);
        add_action('wp_ajax_easy_product_suggest', [$this, 'suggest']);
    }

    public function save(): void
    {
        $this->authorize();

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- in authorize() geprüft
        $product_id = absint($_POST['product_id'] ?? 0);
        if ($product_id && !current_user_can('edit_post', $product_id)) {
            wp_send_json_error(['message' => __('Dafür fehlen dir die Berechtigungen.', 'easy-product')], 403);
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- in authorize() geprüft
        $type = ProductType::get(sanitize_key($_POST['type'] ?? ''));
        if (!$type) {
            wp_send_json_error(['message' => __('Unbekannte Produktart.', 'easy-product')], 400);
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
            'message' => $result->get_status() === 'publish'
                ? __('Gespeichert. Das Produkt ist jetzt im Shop zu sehen.', 'easy-product')
                : __('Als Entwurf gespeichert.', 'easy-product'),
            'viewUrl' => get_permalink($result->get_id()),
        ]);
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
            wp_send_json_error(['message' => __('Unbekannte Produktart.', 'easy-product')], 400);
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
            wp_send_json_error(['message' => __('Unbekannte Produktart.', 'easy-product')], 400);
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
            wp_send_json_error(['message' => __('Es wurde kein Foto übertragen.', 'easy-product')], 400);
        }

        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput, WordPress.Security.NonceVerification.Missing -- wp_handle_upload prüft die Datei, authorize() die Nonce
        $result = (new ImageProcessor())->handle_upload($_FILES['file']);
        if (is_wp_error($result)) {
            wp_send_json_error([
                'message' => sprintf(
                    /* translators: %s: technische Fehlermeldung */
                    __('Das Foto konnte nicht gespeichert werden (%s).', 'easy-product'),
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
            wp_send_json_error(['message' => __('Die Sitzung ist abgelaufen. Bitte lade die Seite neu.', 'easy-product')], 403);
        }

        foreach ([Plugin::CAPABILITY, ...$extra_caps] as $cap) {
            if (!current_user_can($cap)) {
                wp_send_json_error(['message' => __('Dafür fehlen dir die Berechtigungen.', 'easy-product')], 403);
            }
        }
    }
}
