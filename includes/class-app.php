<?php

declare(strict_types=1);

namespace NovemberkindProdukte;

defined('ABSPATH') || exit;

/**
 * Eigenständige Seite unter /produkte-verwalten/ ohne WordPress-Rahmen. Adressen siehe CLAUDE.md.
 */
final class App
{
    public const QUERY_VAR = 'novemberkind_produkte';
    private const MANIFEST = 'manifest.webmanifest';

    /**
     * Adressen unter dem Pfad der Produktverwaltung: Muster => [Methode, zusätzlich nötiges Recht].
     * Gruppen im Muster werden der Methode übergeben.
     */
    private const ROUTES = [
        ''                      => ['overview', ''],
        'neu'                   => ['type_picker', ''],
        'neu/([a-z]+)'          => ['new_product', ''],
        '(\d+)'                 => ['edit_product', ''],
        'aktionen'              => ['campaigns', ''],
        'aktionen/(neu|\d+)'    => ['campaign_form', ''],
        'gutscheine'            => ['coupons', Coupons::CAPABILITY],
        'gutscheine/(neu|\d+)'  => ['coupon_form', Coupons::CAPABILITY],
        'newsletter'            => ['newsletters', Newsletters::CAPABILITY],
        'newsletter/abonnenten' => ['subscribers', Newsletters::CAPABILITY],
        'newsletter/(neu|\d+)'  => ['newsletter_form', Newsletters::CAPABILITY],
    ];

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
     * Meldet Adressen, Seitenaufbau und Anpassungen der Login-Seite an.
     */
    public function register(): void
    {
        add_action('init', [$this, 'add_rewrite_rules']);
        add_filter('query_vars', static fn(array $vars): array => [...$vars, self::QUERY_VAR]);
        add_action('template_redirect', [$this, 'maybe_render']);
        // Sonst hängt WordPress an manifest.webmanifest einen Schrägstrich an
        add_filter('redirect_canonical', static fn($redirect) => get_query_var(self::QUERY_VAR) !== '' ? false : $redirect);
        add_filter('login_redirect', [$this, 'login_redirect'], 10, 3);
        add_action('login_enqueue_scripts', [$this, 'login_style']);
        add_filter('login_headerurl', static fn(): string => home_url('/'));
        add_filter('login_headertext', static fn(): string => get_bloginfo('name'));
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
        self::require_capability(Plugin::CAPABILITY);
        if ($route !== null && $route['capability'] !== '') {
            self::require_capability($route['capability']);
        }

        status_header(200);
        send_frame_options_header();
        $this->render($route ? $this->{$route['method']}(...$route['args']) : self::missing(__('Diese Seite gibt es nicht.', 'novemberkind-produkte')));
        exit;
    }

    /**
     * Sucht die Route zu einer Adresse unter dem Pfad, z. B. „aktionen/12“.
     *
     * @return array{method: string, capability: string, args: string[]}|null
     */
    private static function match(string $page): ?array
    {
        foreach (self::ROUTES as $pattern => [$method, $capability]) {
            // D: $ passt nur am Ende, nicht vor einem abschließenden Zeilenumbruch
            if (preg_match('#^' . $pattern . '$#D', $page, $found)) {
                return ['method' => $method, 'capability' => $capability, 'args' => array_slice($found, 1)];
            }
        }

        return null;
    }

    private static function require_capability(string $capability): void
    {
        if (current_user_can($capability)) {
            return;
        }
        wp_die(
            esc_html(match ($capability) {
                Coupons::CAPABILITY     => __('Für Gutscheine fehlen dir die Berechtigungen.', 'novemberkind-produkte'),
                Newsletters::CAPABILITY => __('Für den Newsletter fehlen dir die Berechtigungen.', 'novemberkind-produkte'),
                default                 => __('Für die Produktverwaltung fehlen dir die Berechtigungen.', 'novemberkind-produkte'),
            }),
            esc_html__('Keine Berechtigung', 'novemberkind-produkte'),
            ['response' => 403, 'back_link' => true]
        );
    }

    /**
     * Plugin-Version plus Änderungszeit der Datei, damit Browser nach jeder Änderung die neue Fassung laden.
     */
    private static function asset_version(string $file): string
    {
        $path = dirname(PLUGIN_FILE) . '/' . $file;

        return VERSION . '.' . (is_readable($path) ? (string) filemtime($path) : '0');
    }

    /**
     * Web-App-Manifest, damit die Seite auf dem Home-Bildschirm wie eine eigene App startet.
     */
    private function manifest(): never
    {
        $icons = plugin_dir_url(PLUGIN_FILE) . 'assets/icons/';
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
     * Shop-Manager landen nach dem Login direkt in der Produktverwaltung, Administratoren im Backend.
     * Ohne Typangaben, weil auch andere Plugins diesen Filter auslösen; ein Typfehler würde die Anmeldung abbrechen.
     */
    public function login_redirect(mixed $redirect_to, mixed $requested = '', mixed $user = null): mixed
    {
        if (!is_string($requested) || !$user instanceof \WP_User || $user->has_cap('manage_options') || !$user->has_cap(Plugin::CAPABILITY)) {
            return $redirect_to;
        }
        if ($requested === '' || untrailingslashit($requested) === untrailingslashit(admin_url())) {
            return self::url();
        }

        return $redirect_to;
    }

    /**
     * Gestaltet die WordPress-Login-Seite wie die Produktverwaltung.
     */
    public function login_style(): void
    {
        wp_enqueue_style('novemberkind-produkte-login', plugin_dir_url(PLUGIN_FILE) . 'assets/css/login.css', [], self::asset_version('assets/css/login.css'));
    }

    /**
     * @param array{view: string, title: string, data: array<string, mixed>} $page
     */
    private function render(array $page): void
    {
        $this->register_assets();

        $view  = $page['view'];
        $title = $page['title'];
        extract($page['data'], EXTR_SKIP); // phpcs:ignore WordPress.PHP.DontExtract -- Variablen für das Template
        include __DIR__ . '/../templates/app.php';
    }

    /**
     * Skripte und Stile der Seite. Gemeinsame Werte und Texte stehen in `novemberkindConfig`,
     * die übrigen im Objekt des jeweiligen Skripts.
     */
    private function register_assets(): void
    {
        $base   = plugin_dir_url(PLUGIN_FILE);
        $script = static function (string $handle, string $file, array $deps = []) use ($base): void {
            wp_register_script('novemberkind-produkte-' . $handle, $base . $file, $deps, self::asset_version($file), true);
        };
        wp_register_style('novemberkind-produkte-app', $base . 'assets/css/app.css', [], self::asset_version('assets/css/app.css'));
        $script('common', 'assets/js/common.js');
        $script('images', 'assets/js/images.js');
        $script('app', 'assets/js/app.js', ['novemberkind-produkte-common', 'novemberkind-produkte-images']);
        $script('forms', 'assets/js/forms.js', ['novemberkind-produkte-common', 'novemberkind-produkte-images']);
        $script('vine', 'assets/js/vine.js');

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
        wp_localize_script('novemberkind-produkte-app', 'novemberkindProdukte', [
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
        wp_localize_script('novemberkind-produkte-forms', 'novemberkindFormulare', [
            'i18n' => [
                'testSending' => __('Wird verschickt …', 'novemberkind-produkte'),
                'linkPrompt'  => __('Adresse des Links, z. B. https://novemberkind.art/shop/', 'novemberkind-produkte'),
            ],
        ]);
    }

    /**
     * @param array<string, mixed>|null $data Variablen für das Template, null für „gibt es nicht“
     * @return array{view: string, title: string, data: array<string, mixed>}
     */
    private static function page(string $view, string $title, ?array $data, string $missing = ''): array
    {
        return $data === null ? self::missing($missing) : ['view' => $view, 'title' => $title, 'data' => $data];
    }

    /**
     * Seite mit Status 404 und einer Meldung, was fehlt.
     *
     * @return array{view: string, title: string, data: array<string, mixed>}
     */
    private static function missing(string $message): array
    {
        status_header(404);

        return [
            'view'  => 'not-found',
            'title' => __('Nicht gefunden', 'novemberkind-produkte'),
            'data'  => ['missing' => $message],
        ];
    }

    /**
     * @return array{view: string, title: string, data: array<string, mixed>}
     */
    private function overview(): array
    {
        return self::page('overview', __('Meine Produkte', 'novemberkind-produkte'), self::overview_data());
    }

    /**
     * @return array{view: string, title: string, data: array<string, mixed>}
     */
    private function type_picker(): array
    {
        return self::page('type-picker', __('Neues Produkt', 'novemberkind-produkte'), ['types' => ProductType::all()]);
    }

    /**
     * @return array{view: string, title: string, data: array<string, mixed>}
     */
    private function new_product(string $key): array
    {
        $type = ProductType::get($key);

        return self::page(
            'product-form',
            /* translators: %s: Produktart, z. B. Button */
            $type ? sprintf(__('Neu: %s', 'novemberkind-produkte'), $type->label()) : '',
            $type ? $this->form_data($type) : null,
            __('Diese Produktart gibt es nicht.', 'novemberkind-produkte')
        );
    }

    /**
     * @return array{view: string, title: string, data: array<string, mixed>}
     */
    private function edit_product(string $id): array
    {
        $product = wc_get_product((int) $id);
        $type    = $product ? ProductType::detect($product) : null;
        if ($product && !$type && current_user_can('edit_post', $product->get_id())) {
            // Produkte ohne Vorlage werden in der WooCommerce-Maske bearbeitet
            wp_safe_redirect((string) get_edit_post_link($product->get_id(), 'raw'));
            exit;
        }

        return self::page(
            'product-form',
            $product ? $product->get_name() : '',
            $product && $type ? $this->form_data($type, $product) : null,
            __('Dieses Produkt gibt es nicht mehr.', 'novemberkind-produkte')
        );
    }

    /**
     * @return array{view: string, title: string, data: array<string, mixed>}
     */
    private function campaigns(): array
    {
        return self::page('campaigns', __('Aktionen', 'novemberkind-produkte'), ['campaigns' => Campaigns::all()]);
    }

    /**
     * @return array{view: string, title: string, data: array<string, mixed>}
     */
    private function campaign_form(string $id): array
    {
        $campaign = $id === 'neu' ? null : Campaigns::get((int) $id);

        return self::page(
            'campaign-form',
            $campaign ? $campaign['name'] : __('Neue Aktion', 'novemberkind-produkte'),
            $id === 'neu' || $campaign ? $this->campaign_form_data($campaign) : null,
            __('Diese Aktion gibt es nicht mehr.', 'novemberkind-produkte')
        );
    }

    /**
     * @return array{view: string, title: string, data: array<string, mixed>}
     */
    private function coupons(): array
    {
        return self::page('coupons', __('Gutscheine', 'novemberkind-produkte'), ['coupons' => Coupons::all()]);
    }

    /**
     * @return array{view: string, title: string, data: array<string, mixed>}
     */
    private function coupon_form(string $id): array
    {
        $coupon = $id === 'neu' ? null : Coupons::get((int) $id);
        if ($coupon && !$coupon['own']) {
            // Gutscheine aus WooCommerce mit anderen Einstellungen bleiben in der WooCommerce-Maske
            wp_safe_redirect($coupon['edit_url']);
            exit;
        }

        return self::page(
            'coupon-form',
            $coupon ? $coupon['code'] : __('Neuer Gutschein', 'novemberkind-produkte'),
            $id === 'neu' || $coupon ? ['coupon' => $coupon] : null,
            __('Diesen Gutschein gibt es nicht mehr.', 'novemberkind-produkte')
        );
    }

    /**
     * @return array{view: string, title: string, data: array<string, mixed>}
     */
    private function newsletters(): array
    {
        (new Newsletters())->resume_stalled();

        return self::page('newsletters', __('Newsletter', 'novemberkind-produkte'), ['issues' => Newsletters::all(), 'counts' => Subscribers::counts()]);
    }

    /**
     * @return array{view: string, title: string, data: array<string, mixed>}
     */
    private function subscribers(): array
    {
        (new Subscribers())->cleanup();

        return self::page('subscribers', __('Abonnenten', 'novemberkind-produkte'), ['subscribers' => Subscribers::all()]);
    }

    /**
     * @return array{view: string, title: string, data: array<string, mixed>}
     */
    private function newsletter_form(string $id): array
    {
        $issue = $id === 'neu' ? null : Newsletters::get((int) $id);

        return self::page(
            'newsletter-form',
            $issue ? $issue['subject'] : __('Neuer Newsletter', 'novemberkind-produkte'),
            $id === 'neu' || $issue ? $this->newsletter_form_data($issue) : null,
            __('Diesen Newsletter gibt es nicht mehr.', 'novemberkind-produkte')
        );
    }

    /**
     * Alle Produkte, gruppiert nach ihrer genauesten Kategorie. Beiträge, Metadaten, Kategorien und Fotos
     * werden vorab gesammelt geladen statt einzeln je Produkt.
     *
     * @return array{products: \WC_Product[], groups: array<string, array{name: string, products: \WC_Product[]}>}
     */
    private static function overview_data(): array
    {
        $ids = array_map('intval', wc_get_products([
            'status'  => ProductService::LISTED_STATUSES,
            'limit'   => -1,
            'orderby' => 'modified',
            'order'   => 'DESC',
            'return'  => 'ids',
        ]));
        _prime_post_caches($ids, true, true);
        $products = array_values(array_filter(array_map('wc_get_product', $ids)));
        _prime_post_caches(array_filter(array_map(static fn(\WC_Product $product): int => (int) $product->get_image_id(), $products)), false, true);

        $groups = [];
        foreach ($products as $product) {
            $category = self::group_category($product);
            $key      = $category ? $category->slug : '';
            $groups[$key] ??= ['name' => $category ? $category->name : __('Ohne Kategorie', 'novemberkind-produkte'), 'products' => []];
            $groups[$key]['products'][] = $product;
        }
        uasort($groups, static fn(array $a, array $b): int => strcasecmp($a['name'], $b['name']));

        return ['products' => $products, 'groups' => $groups];
    }

    /**
     * Die genaueste Kategorie eines Produkts, z. B. „Buttons“ statt „Physische Produkte“.
     * Bei mehreren zählt die Kategorie der Produktart, sonst die alphabetisch erste.
     */
    private static function group_category(\WC_Product $product): ?\WP_Term
    {
        $terms   = wc_get_object_terms($product->get_id(), 'product_cat');
        $parents = wp_list_pluck($terms, 'parent');
        $leaves  = array_values(array_filter($terms, static fn(\WP_Term $term): bool => !in_array($term->term_id, $parents, true)));
        if ($leaves === []) {
            return null;
        }

        $type_category = ProductType::detect($product)?->config('category');
        foreach ($leaves as $leaf) {
            if ($type_category && $leaf->name === end($type_category)) {
                return $leaf;
            }
        }
        usort($leaves, static fn(\WP_Term $a, \WP_Term $b): int => strcasecmp($a->name, $b->name));

        return $leaves[0];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function form_data(ProductType $type, ?\WC_Product $product = null): ?array
    {
        if ($product && !current_user_can('edit_post', $product->get_id())) {
            return null;
        }

        $service = new ProductService();
        $back_images = !$type->is_variable() ? [] : ($product ? $service->variation_images_of($type, $product) : $service->variation_image_ids($type));

        $context = $product ? $type->context_from_product($product) : self::default_context($type);

        $data = [
            'type'        => $type,
            'product'     => $product,
            'context'     => $context,
            'description' => $product ? wp_kses_post($product->get_description()) : $type->description($context),
            'custom_description' => $product && $type->has_custom_description($product),
            'motif_tags'  => $product ? ProductService::motif_tags($product, $type) : [],
            'price'       => $product ? self::current_price($product) : (string) $type->config('price'),
            'stock'       => $product && $product->managing_stock() ? (string) $product->get_stock_quantity() : '',
            'price_a4'    => (string) $type->config('price_a4'),
            'stock_a4'    => '',
            'gallery_ids' => $product ? array_values(array_filter(
                array_map('intval', $product->get_gallery_image_ids()),
                static fn(int $id): bool => !$service->is_variation_image($type, $id)
            )) : [],
            'back_images' => $back_images,
            'backups'     => $product ? (new Backups())->summary($product->get_id()) : [],
        ];
        if ($product && $type->has_field('a4')) {
            $data = array_merge($data, CardSizes::values($product, (string) $type->config('price_a4')));
        }
        $data += $product ? ProductService::sale_state($product) : ['sale' => '', 'sale_a4' => '', 'sale_locked' => false];

        return $data;
    }

    /**
     * @return array<string, string>
     */
    private static function default_context(ProductType $type): array
    {
        $context = ['motif' => ''];
        foreach ($type->fields() as $field) {
            match ($field) {
                'format'         => $context['format'] = 'quer',
                'bookmark_width' => $context['width'] = '7',
                'year'           => $context['year'] = gmdate('Y'),
                'size'           => $context += ['width' => '', 'height' => ''],
                default          => $context[$field] = '',
            };
        }

        return $context;
    }

    /**
     * Daten für das Formular einer Ausgabe: Produkte aus dem Shop und die Zahl der Empfänger.
     *
     * @param array<string, mixed>|null $issue
     * @return array<string, mixed>
     */
    private function newsletter_form_data(?array $issue): array
    {
        return [
            'issue'      => $issue,
            'products'   => self::product_choices('publish'),
            'recipients' => Subscribers::counts()['confirmed'],
            'remaining'  => $issue ? Newsletters::remaining($issue['id']) : 0,
            'from'       => NewsletterMail::from_label(),
            'test_email' => Newsletters::test_email(wp_get_current_user()),
        ];
    }

    /**
     * Daten für das Formular einer Aktion: Kategorien als eingerückte Liste, Produkte nach Name.
     *
     * @param array<string, mixed>|null $campaign
     * @return array<string, mixed>
     */
    private function campaign_form_data(?array $campaign): array
    {
        $terms = get_terms(['taxonomy' => 'product_cat', 'hide_empty' => false, 'orderby' => 'name']);
        $terms = is_array($terms) ? $terms : [];
        $categories = [];
        $add = static function (int $parent, int $depth) use (&$add, &$categories, $terms): void {
            foreach ($terms as $term) {
                if ($term instanceof \WP_Term && $term->parent === $parent) {
                    $categories[] = ['id' => $term->term_id, 'parent' => $term->parent, 'name' => $term->name, 'depth' => $depth, 'count' => (int) $term->count];
                    $add($term->term_id, $depth + 1);
                }
            }
        };
        $add(0, 0);

        return [
            'campaign'   => $campaign,
            'categories' => $categories,
            'products'   => self::product_choices(ProductService::LISTED_STATUSES),
            'conflicts'  => $campaign ? Campaigns::conflicts($campaign) : ['overlaps' => [], 'reference' => []],
        ];
    }

    /**
     * Produkte zur Auswahl in Aktionen und Newsletter, nach Name sortiert.
     *
     * @param string|string[] $status
     * @return array<int, array{id: int, name: string, sku: string, own_sale: bool}>
     */
    private static function product_choices(string|array $status): array
    {
        return array_map(static fn(\WC_Product $product): array => [
            'id'       => $product->get_id(),
            'name'     => $product->get_name(),
            'sku'      => $product->get_sku(),
            'own_sale' => !$product instanceof \WC_Product_Variable && $product->is_on_sale('edit'),
        ], wc_get_products([
            'status'  => $status,
            'limit'   => -1,
            'orderby' => 'title',
            'order'   => 'ASC',
        ]));
    }

    private static function current_price(\WC_Product $product): string
    {
        $price = $product->get_regular_price('edit');
        if ($product instanceof \WC_Product_Variable) {
            $first = wc_get_product($product->get_children()[0] ?? 0);
            $price = $first ? $first->get_regular_price('edit') : '';
        }

        return (string) $price;
    }
}
