<?php

declare(strict_types=1);

namespace NovemberkindProdukte;

defined('ABSPATH') || exit;

/**
 * Eigenständige Seite unter /produkte-verwalten/ ohne WordPress-Rahmen: Adressen, Rechte und Seitenaufbau.
 * Die Daten der einzelnen Seiten liefert Pages. Adressen siehe CLAUDE.md.
 */
final class App
{
    public const QUERY_VAR = 'novemberkind_produkte';
    private const MANIFEST = 'manifest.webmanifest';

    /**
     * Adressen unter dem Pfad der Produktverwaltung: Muster => [Methode in Pages, Bereich, Skript der Seite].
     * Gruppen im Muster werden der Methode übergeben, das nötige Recht kommt vom Bereich.
     */
    private const ROUTES = [
        ''                      => ['overview', 'products', 'overview'],
        'neu'                   => ['type_picker', 'products', ''],
        'neu/([a-z]+)'          => ['new_product', 'products', 'product-form'],
        '(\d+)'                 => ['edit_product', 'products', 'product-form'],
        'aktionen'              => ['campaigns', 'campaigns', 'forms'],
        'aktionen/(neu|\d+)'    => ['campaign_form', 'campaigns', 'forms'],
        'gutscheine'            => ['coupons', 'coupons', 'forms'],
        'gutscheine/(neu|\d+)'  => ['coupon_form', 'coupons', 'forms'],
        'newsletter'            => ['newsletters', 'newsletter', 'forms'],
        'newsletter/abonnenten' => ['subscribers', 'newsletter', 'forms'],
        'newsletter/(neu|\d+)'  => ['newsletter_form', 'newsletter', 'newsletter-form'],
    ];

    /**
     * Bereiche in der Kopfzeile mit Adresse, Bezeichnung und dem Recht, das sie zusätzlich verlangen.
     *
     * @return array<string, array{url: string, label: string, capability: string}>
     */
    public static function sections(): array
    {
        return [
            'products'   => ['url' => self::url(), 'label' => __('Produkte', 'novemberkind-produkte'), 'capability' => ''],
            'campaigns'  => ['url' => self::campaigns_url(), 'label' => __('Aktionen', 'novemberkind-produkte'), 'capability' => ''],
            'coupons'    => ['url' => self::coupons_url(), 'label' => __('Gutscheine', 'novemberkind-produkte'), 'capability' => Coupons::CAPABILITY],
            'newsletter' => ['url' => self::newsletter_url(), 'label' => __('Newsletter', 'novemberkind-produkte'), 'capability' => Newsletters::CAPABILITY],
        ];
    }

    /**
     * Pfad der Produktverwaltung ohne Schrägstriche, änderbar über den Filter `novemberkind_produkte_path`.
     */
    public static function path(): string
    {
        return trim((string) apply_filters('novemberkind_produkte_path', 'produkte-verwalten'), '/');
    }

    /**
     * Adresse der Produktübersicht oder, mit `$page` wie „aktionen/neu“, einer Unterseite.
     */
    public static function url(string $page = ''): string
    {
        return home_url('/' . self::path() . '/' . ($page !== '' ? trim($page, '/') . '/' : ''));
    }

    /**
     * Adresse des Formulars für ein bestehendes Produkt.
     */
    public static function edit_url(int $product_id): string
    {
        return self::url((string) $product_id);
    }

    /**
     * Adresse der Auswahl der Produktart oder, mit Produktart, des leeren Formulars.
     */
    public static function new_url(string $type = ''): string
    {
        return self::url('neu/' . $type);
    }

    /**
     * Adresse der Aktionsliste oder, mit ID oder „neu“, eines Aktionsformulars.
     */
    public static function campaigns_url(int|string $campaign = ''): string
    {
        return self::url('aktionen/' . $campaign);
    }

    /**
     * Adresse der Gutscheinliste oder, mit ID oder „neu“, eines Gutscheinformulars.
     */
    public static function coupons_url(int|string $coupon = ''): string
    {
        return self::url('gutscheine/' . $coupon);
    }

    /**
     * Adresse der Newsletter-Liste oder, mit ID, „neu“ oder „abonnenten“, einer Unterseite.
     */
    public static function newsletter_url(int|string $page = ''): string
    {
        return self::url('newsletter/' . $page);
    }

    /**
     * Adresse des Web-App-Manifests für den Home-Bildschirm.
     */
    public static function manifest_url(): string
    {
        return home_url('/' . self::path() . '/' . self::MANIFEST);
    }

    /**
     * Meldet Adressen und Seitenaufbau an.
     */
    public function register(): void
    {
        add_action('init', [$this, 'add_rewrite_rules']);
        add_filter('query_vars', static fn(array $vars): array => [...$vars, self::QUERY_VAR]);
        add_action('template_redirect', [$this, 'maybe_render']);
        // Sonst hängt WordPress an manifest.webmanifest einen Schrägstrich an
        add_filter('redirect_canonical', static fn($redirect) => get_query_var(self::QUERY_VAR) !== '' ? false : $redirect);
    }

    /**
     * Eine Regel für alles unter dem Pfad, die Seite wählt maybe_render() über ROUTES.
     * Ändert sich die Regel, etwa über den Filter für den Pfad, wird sie einmal neu geschrieben.
     */
    public function add_rewrite_rules(): void
    {
        $rule  = '^' . preg_quote(self::path(), '#') . '(?:/(.*?))?/?$';
        $query = 'index.php?' . self::QUERY_VAR . '=/$matches[1]';
        add_rewrite_rule($rule, $query, 'top');

        $signature = md5($rule . $query);
        if (get_option('novemberkind_produkte_rewrite') !== $signature) {
            flush_rewrite_rules(false);
            update_option('novemberkind_produkte_rewrite', $signature);
        }
    }

    /**
     * Zeigt die eigene Seite an, wenn die Adresse dazu gehört, und prüft vorher Anmeldung und Rechte.
     */
    public function maybe_render(): void
    {
        $query = get_query_var(self::QUERY_VAR);
        if ($query === '') {
            return;
        }
        // Listen wie ?novemberkind_produkte[]=x gelten als unbekannte Seite
        $query = is_string($query) ? $query : '/-';
        $page = trim($query, '/');

        // Safari lädt das Manifest ohne Anmeldung; es enthält nur Name, Farben und Icons
        if ($page === self::MANIFEST) {
            $this->manifest();
        }

        nocache_headers();
        $route = self::match($page);

        if (!is_user_logged_in()) {
            wp_safe_redirect(wp_login_url($route !== null ? self::url($page) : self::url()));
            exit;
        }
        Plugin::require_capability(Plugin::CAPABILITY);
        $section = $route['section'] ?? 'products';
        $capability = self::sections()[$section]['capability'];
        if ($capability !== '') {
            Plugin::require_capability($capability);
        }

        status_header(200);
        send_frame_options_header();
        $page = $route ? (new Pages())->{$route['method']}(...$route['args']) : Pages::missing(__('Diese Seite gibt es nicht.', 'novemberkind-produkte'));
        $this->render($page + ['section' => $section, 'script' => $page['view'] === 'not-found' ? '' : (string) ($route['script'] ?? '')]);
        exit;
    }

    /**
     * Sucht die Route zu einer Adresse unter dem Pfad, z. B. „aktionen/12“.
     *
     * @return array{method: string, section: string, script: string, args: string[]}|null
     */
    private static function match(string $page): ?array
    {
        foreach (self::ROUTES as $pattern => [$method, $section, $script]) {
            // D: $ passt nur am Ende, nicht vor einem abschließenden Zeilenumbruch
            if (preg_match('#^' . $pattern . '$#D', $page, $found)) {
                return ['method' => $method, 'section' => $section, 'script' => $script, 'args' => array_slice($found, 1)];
            }
        }

        return null;
    }

    /**
     * Web-App-Manifest, damit die Seite auf dem Home-Bildschirm wie eine eigene App startet.
     */
    private function manifest(): never
    {
        $icons = Plugin::asset_url('assets/icons/');
        $home  = (string) wp_parse_url(home_url('/'), PHP_URL_PATH);

        status_header(200);
        header('Content-Type: application/manifest+json; charset=utf-8');
        header('Cache-Control: public, max-age=' . DAY_IN_SECONDS);
        echo wp_json_encode([
            'name'             => __('Novemberkind Produkte', 'novemberkind-produkte'),
            'short_name'       => __('Produkte', 'novemberkind-produkte'),
            'lang'             => 'de',
            'start_url'        => (string) wp_parse_url(self::url(), PHP_URL_PATH),
            // Ganze Website als Bereich, damit die Anmeldung unter wp-login.php in der App bleibt
            'scope'            => $home !== '' ? $home : '/',
            'display'          => 'standalone',
            'background_color' => '#f6f1ea',
            'theme_color'      => '#f6f1ea',
            'icons'            => [
                ['src' => $icons . 'app-icon-192.png', 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any'],
                ['src' => $icons . 'app-icon-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any'],
                ['src' => $icons . 'app-icon-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'],
            ],
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }

    /**
     * @param array{view: string, title: string, data: array<string, mixed>, section: string, script: string} $page
     */
    private function render(array $page): void
    {
        $this->register_assets();

        $view    = $page['view'];
        $title   = $page['title'];
        $section = $page['section'];
        $scripts = [...($page['script'] !== '' ? ['novemberkind-produkte-' . $page['script']] : []), 'novemberkind-produkte-vine'];
        extract($page['data'], EXTR_SKIP); // phpcs:ignore WordPress.PHP.DontExtract -- Variablen für das Template
        include __DIR__ . '/../templates/app.php';
    }

    /**
     * Skripte und Stile der Seite. Gemeinsame Werte und Texte stehen in `novemberkindConfig`,
     * die übrigen im Objekt des jeweiligen Skripts.
     */
    private function register_assets(): void
    {
        $script = static function (string $handle, string $file, array $deps = []): void {
            wp_register_script('novemberkind-produkte-' . $handle, Plugin::asset_url($file), $deps, Plugin::asset_version($file), true);
        };
        Plugin::register_app_style();
        $script('images', 'assets/js/images.js');
        $script('common', 'assets/js/common.js', ['novemberkind-produkte-images']);
        $script('overview', 'assets/js/overview.js', ['novemberkind-produkte-common']);
        $script('product-form', 'assets/js/product-form.js', ['novemberkind-produkte-common']);
        $script('forms', 'assets/js/forms.js', ['novemberkind-produkte-common']);
        $script('newsletter-form', 'assets/js/newsletter-form.js', ['novemberkind-produkte-forms']);
        $script('vine', 'assets/js/vine.js', ['novemberkind-produkte-common']);

        wp_localize_script('novemberkind-produkte-common', 'novemberkindConfig', [
            'ajaxUrl'        => admin_url('admin-ajax.php'),
            'nonce'          => wp_create_nonce(Ajax::NONCE),
            'appUrl'         => self::url(),
            'maxUploadBytes' => wp_max_upload_size(),
            'maxWidth'       => ImageProcessor::MAX_WIDTH,
            'i18n'           => [
                'saving'        => __('Wird gespeichert …', 'novemberkind-produkte'),
                'save'          => __('Speichern', 'novemberkind-produkte'),
                'waitForUpload' => __('Einen Moment noch, die Fotos werden gerade hochgeladen.', 'novemberkind-produkte'),
                'unreadable'    => __('Dieses Foto kann der Browser nicht öffnen. Bitte als JPEG oder PNG versuchen.', 'novemberkind-produkte'),
                'networkError'  => __('Keine Verbindung zum Shop. Bitte prüfe die Internetverbindung und versuche es noch einmal.', 'novemberkind-produkte'),
                'loggedOut'     => __('Du bist inzwischen abgemeldet. Bitte lade die Seite neu und melde dich wieder an.', 'novemberkind-produkte'),
                'unsaved'       => __('Es gibt ungespeicherte Änderungen.', 'novemberkind-produkte'),
            ],
        ]);
        wp_localize_script('novemberkind-produkte-product-form', 'novemberkindProdukte', [
            'i18n' => [
                'suggesting'   => __('Claude denkt nach …', 'novemberkind-produkte'),
                'suggest'      => __('Vorschlag holen', 'novemberkind-produkte'),
                'modeNew'      => __('Die Beschreibung war noch unvollständig. Claude hat sie nach der Vorlage neu geschrieben.', 'novemberkind-produkte'),
                'modeImproved' => __('Claude hat deine Beschreibung behutsam überarbeitet.', 'novemberkind-produkte'),
                'view'         => __('Ansehen', 'novemberkind-produkte'),
                'download'     => __('Herunterladen', 'novemberkind-produkte'),
                'resetText'    => __('Deine Änderungen an der Beschreibung gehen dabei verloren. Trotzdem neu erstellen?', 'novemberkind-produkte'),
            ],
        ]);
        wp_localize_script('novemberkind-produkte-newsletter-form', 'novemberkindNewsletter', [
            'i18n' => [
                'testSending' => __('Wird verschickt …', 'novemberkind-produkte'),
                'linkPrompt'  => __('Adresse des Links, z. B. https://novemberkind.art/shop/', 'novemberkind-produkte'),
            ],
        ]);
    }
}
