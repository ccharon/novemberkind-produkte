<?php

declare(strict_types=1);

namespace NovemberkindProdukte;

defined('ABSPATH') || exit;

/**
 * AJAX-Aktionen der Produktverwaltung. Jede Aktion prüft Nonce und Rechte.
 *
 * phpcs:disable WordPress.Security.NonceVerification.Missing -- in run() über authorize() geprüft
 */
final class Ajax
{
    public const NONCE = 'novemberkind_produkte';
    // Puffer für langsame Antworten der API; manche Hoster brechen PHP sonst schon nach 30 Sekunden ab
    private const SUGGESTION_TIME_LIMIT = 120;

    /**
     * AJAX-Aktionen mit den Rechten, die sie zusätzlich zu Plugin::CAPABILITY verlangen.
     *
     * @return array<string, string[]>
     */
    private static function actions(): array
    {
        return [
            'save'              => [],
            'upload'            => ['upload_files'],
            'preview'           => [],
            'suggest'           => [],
            'save_campaign'     => [],
            'end_campaign'      => [],
            'save_coupon'       => [Coupons::CAPABILITY],
            'toggle_coupon'     => [Coupons::CAPABILITY],
            'save_newsletter'   => [Newsletters::CAPABILITY],
            'test_newsletter'   => [Newsletters::CAPABILITY],
            'remove_subscriber' => [Newsletters::CAPABILITY],
        ];
    }

    /**
     * Meldet alle AJAX-Aktionen an; alle verlangen eine Anmeldung.
     */
    public function register(): void
    {
        foreach (self::actions() as $action => $caps) {
            add_action('wp_ajax_novemberkind_produkte_' . $action, fn() => $this->run($action, $caps));
        }
    }

    /**
     * Prüft Nonce und Rechte und führt die Aktion aus. Eine Ausnahme, etwa von WooCommerce beim Speichern,
     * landet im Log; der Browser bekommt eine lesbare Meldung statt einer abgebrochenen Antwort.
     *
     * @param string[] $caps
     */
    private function run(string $action, array $caps): void
    {
        $this->authorize($caps);
        try {
            $this->{$action}();
        } catch (\Throwable $error) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Details für den Betrieb, die Nutzerin sieht eine allgemeine Meldung
            error_log(sprintf('novemberkind-produkte: AJAX %s: %s in %s:%d', $action, str_replace(["\r", "\n"], ' ', $error->getMessage()), $error->getFile(), $error->getLine()));
            wp_send_json_error(['message' => __('Das hat nicht geklappt. Bitte lade die Seite neu und versuche es noch einmal.', 'novemberkind-produkte')], 500);
        }
    }

    /**
     * @param string[] $caps
     */
    private function authorize(array $caps): void
    {
        if (!check_ajax_referer(self::NONCE, 'nonce', false)) {
            wp_send_json_error(['message' => __('Die Sitzung ist abgelaufen. Bitte lade die Seite neu.', 'novemberkind-produkte')], 403);
        }

        foreach ([Plugin::CAPABILITY, ...$caps] as $cap) {
            if (!current_user_can($cap)) {
                self::deny();
            }
        }
    }

    /**
     * Antwortet mit der Meldung eines Fehlers und, falls vorhanden, den Meldungen je Feld.
     */
    private static function fail(\WP_Error $error, int $status = 422): never
    {
        $fields = $error->get_error_data();
        wp_send_json_error([
            'message' => $error->get_error_message(),
            'fields'  => is_array($fields) && $fields !== [] ? $fields : new \stdClass(),
        ], $status);
    }

    /**
     * Antwort für Formulare, nach denen die Liste des Bereichs mit einer Meldung erscheint.
     * Ein Fehler geht mit seinen Meldungen je Feld zurück an das Formular.
     *
     * @param array<string, mixed>|\WP_Error          $result
     * @param callable(array<string, mixed>): string $message
     */
    private static function back_to_list(array|\WP_Error $result, string $url, callable $message): never
    {
        if (is_wp_error($result)) {
            self::fail($result);
        }
        wp_send_json_success(['id' => $result['id'] ?? 0, 'url' => $url, 'message' => $message($result)]);
    }

    private static function deny(): never
    {
        wp_send_json_error(['message' => __('Dafür fehlen dir die Berechtigungen.', 'novemberkind-produkte')], 403);
    }

    /**
     * Meldung mit Datum und Uhrzeit, z. B. „… am 01.10.2026 um 18:00 Uhr …“.
     *
     * @param string $message Text mit %1$s für das Datum und %2$s für die Uhrzeit
     */
    private static function at(string $message, int $timestamp): string
    {
        return sprintf($message, wp_date('d.m.Y', $timestamp), wp_date('H:i', $timestamp));
    }

    /**
     * Produktart aus dem Formular; eine unbekannte beendet die Anfrage.
     */
    private static function requested_type(): ProductType
    {
        $type = ProductType::get(sanitize_key(Input::value($_POST, 'type')));
        if (!$type) {
            wp_send_json_error(['message' => __('Unbekannte Produktart.', 'novemberkind-produkte')], 400);
        }

        return $type;
    }

    /**
     * Speichert das Produktformular und antwortet mit dem neuen Stand für die Seite.
     */
    private function save(): void
    {
        $product_id = Input::id($_POST, 'product_id');
        if ($product_id && !current_user_can('edit_post', $product_id)) {
            self::deny();
        }
        $type = self::requested_type();
        // Derselbe Wert, den ProductService speichert, damit die Prüfung nicht an einer anderen Lesart vorbeigeht
        if (Input::choice($_POST, 'status', ProductService::STATUSES, 'draft') !== 'draft' && !current_user_can('publish_products')) {
            wp_send_json_error(['message' => __('Du darfst Produkte nur als Entwurf speichern.', 'novemberkind-produkte')], 403);
        }

        $result = (new ProductService())->save($type, $_POST, $product_id);
        if (is_wp_error($result)) {
            self::fail($result);
        }

        wp_send_json_success([
            'id'      => $result->get_id(),
            'name'    => $result->get_name(),
            'status'  => $result->get_status(),
            'message' => match ($result->get_status()) {
                'publish' => __('Gespeichert. Das Produkt ist jetzt im Shop zu sehen.', 'novemberkind-produkte'),
                /* translators: 1: Datum, 2: Uhrzeit */
                'future'  => self::at(__('Gespeichert. Das Produkt geht am %1$s um %2$s Uhr online.', 'novemberkind-produkte'), (int) $result->get_date_created()?->getTimestamp()),
                default   => __('Als Entwurf gespeichert.', 'novemberkind-produkte'),
            },
            'viewUrl' => get_permalink($result->get_id()),
            'backups' => (new Backups())->summary($result->get_id()),
        ]);
    }

    /**
     * Beschreibung aus der Vorlage zu den aktuellen Formularwerten, auch wenn noch nicht alles ausgefüllt ist.
     */
    private function preview(): void
    {
        $type      = self::requested_type();
        [$context] = $type->parse($_POST);
        wp_send_json_success(['html' => $type->description($context)]);
    }

    /**
     * Vorschlag von Claude für Titel, Beschreibung und Schlagwörter. Speichert nichts.
     */
    private function suggest(): void
    {
        $type = self::requested_type();
        if (function_exists('set_time_limit')) {
            set_time_limit(self::SUGGESTION_TIME_LIMIT);
        }

        $result = (new Suggestions())->suggest($type, $_POST);
        if (is_wp_error($result)) {
            self::fail($result, 502);
        }

        wp_send_json_success($result);
    }

    /**
     * Nimmt ein im Browser verkleinertes Foto an und legt es als WebP in der Mediathek ab.
     */
    private function upload(): void
    {
        if (empty($_FILES['file']) || !is_array($_FILES['file'])) {
            wp_send_json_error(['message' => __('Es wurde kein Foto übertragen.', 'novemberkind-produkte')], 400);
        }

        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- wp_handle_upload prüft die Datei
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
            'id'   => $result,
            'url'  => wp_get_attachment_image_url($result, 'woocommerce_thumbnail'),
            'full' => wp_get_attachment_image_url($result, 'full'),
        ]);
    }

    /**
     * Legt eine Rabattaktion an oder ändert sie.
     */
    private function save_campaign(): void
    {
        self::back_to_list((new Campaigns())->save($_POST, Input::id($_POST, 'id')), App::campaigns_url(), static fn(array $campaign): string => Campaigns::status($campaign) === 'running'
            ? __('Gespeichert. Die Aktion läuft, die Preise im Shop sind gesenkt.', 'novemberkind-produkte')
            /* translators: 1: Datum, 2: Uhrzeit */
            : self::at(__('Gespeichert. Die Aktion beginnt am %1$s um %2$s Uhr.', 'novemberkind-produkte'), $campaign['start']));
    }

    /**
     * Beendet eine laufende Aktion sofort oder sagt eine geplante ab.
     */
    private function end_campaign(): void
    {
        self::back_to_list((new Campaigns())->end(Input::id($_POST, 'id')), App::campaigns_url(), static fn(): string => __('Die Aktion ist beendet. Im Shop gelten wieder die normalen Preise.', 'novemberkind-produkte'));
    }

    /**
     * Legt einen Gutschein an oder ändert ihn.
     */
    private function save_coupon(): void
    {
        self::back_to_list((new Coupons())->save($_POST, Input::id($_POST, 'id')), App::coupons_url(), static fn(array $coupon): string => $coupon['active']
            /* translators: %s: Gutscheincode */
            ? sprintf(__('Gespeichert. Der Code %s ist im Shop einlösbar.', 'novemberkind-produkte'), $coupon['code'])
            : __('Gespeichert. Der Gutschein bleibt deaktiviert.', 'novemberkind-produkte'));
    }

    /**
     * Deaktiviert einen Gutschein oder aktiviert ihn wieder (`value` = on oder off).
     */
    private function toggle_coupon(): void
    {
        $active = Input::text($_POST, 'value') === 'on';
        self::back_to_list((new Coupons())->set_active(Input::id($_POST, 'id'), $active), App::coupons_url(), static fn(): string => $active
            ? __('Der Gutschein ist wieder einlösbar.', 'novemberkind-produkte')
            : __('Der Gutschein ist deaktiviert und im Shop nicht mehr einlösbar.', 'novemberkind-produkte'));
    }

    /**
     * Speichert eine Newsletter-Ausgabe, plant sie oder startet den Versand.
     */
    private function save_newsletter(): void
    {
        self::back_to_list((new Newsletters())->save($_POST, Input::id($_POST, 'id')), App::newsletter_url(), static fn(array $issue): string => match ($issue['status']) {
            'sending'   => sprintf(
                /* translators: %d: Anzahl der Empfänger */
                _n('Der Newsletter wird jetzt an %d Empfänger verschickt.', 'Der Newsletter wird jetzt an %d Empfänger verschickt.', $issue['recipients'], 'novemberkind-produkte'),
                $issue['recipients']
            ),
            /* translators: 1: Datum, 2: Uhrzeit */
            'scheduled' => self::at(__('Gespeichert. Der Newsletter geht am %1$s um %2$s Uhr raus.', 'novemberkind-produkte'), $issue['scheduled']),
            default     => __('Als Entwurf gespeichert.', 'novemberkind-produkte'),
        });
    }

    /**
     * Schickt den aktuellen Stand des Formulars an die Adresse aus dem Feld „Testmail an“ und merkt sie sich.
     */
    private function test_newsletter(): void
    {
        $user  = wp_get_current_user();
        $email = strtolower(trim(sanitize_email(wp_unslash(Input::value($_POST, 'test_email', Newsletters::test_email($user))))));
        if (!is_email($email)) {
            self::fail(Input::invalid(['test_email' => __('Bitte gib eine gültige E-Mail-Adresse ein.', 'novemberkind-produkte')]));
        }

        $result = (new Newsletters())->send_test($_POST, $email);
        if (is_wp_error($result)) {
            self::fail($result);
        }
        update_user_meta($user->ID, Newsletters::META_TEST_EMAIL, $email);

        /* translators: %s: E-Mail-Adresse */
        wp_send_json_success(['message' => sprintf(__('Die Testmail ist an %s unterwegs.', 'novemberkind-produkte'), $email)]);
    }

    /**
     * Trägt einen Abonnenten aus und löscht seine Adresse.
     */
    private function remove_subscriber(): void
    {
        if (!(new Subscribers())->remove(Input::id($_POST, 'id'))) {
            wp_send_json_error(['message' => __('Diese Adresse ist nicht mehr angemeldet.', 'novemberkind-produkte')], 422);
        }

        self::back_to_list([], App::newsletter_url('abonnenten'), static fn(): string => __('Ausgetragen. Die Adresse ist gelöscht.', 'novemberkind-produkte'));
    }
}
