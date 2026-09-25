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

    public static function path(): string
    {
        return trim((string) apply_filters('novemberkind_produkte_path', 'produkte-verwalten'), '/');
    }

    public static function url(): string
    {
        return home_url('/' . self::path() . '/');
    }

    public static function edit_url(int $product_id): string
    {
        return self::url() . $product_id . '/';
    }

    public static function new_url(string $type = ''): string
    {
        return self::url() . 'neu/' . ($type !== '' ? $type . '/' : '');
    }

    public static function campaigns_url(int|string $campaign = ''): string
    {
        return self::url() . 'aktionen/' . ($campaign !== '' ? $campaign . '/' : '');
    }

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

    public function add_rewrite_rules(): void
    {
        $path = preg_quote(self::path(), '#');
        add_rewrite_rule("^{$path}/?$", 'index.php?' . self::QUERY_VAR . '=overview', 'top');
        add_rewrite_rule("^{$path}/(neu|\\d+)/?$", 'index.php?' . self::QUERY_VAR . '=$matches[1]', 'top');
        add_rewrite_rule("^{$path}/neu/([a-z]+)/?$", 'index.php?' . self::QUERY_VAR . '=neu-$matches[1]', 'top');
        add_rewrite_rule("^{$path}/manifest\\.webmanifest$", 'index.php?' . self::QUERY_VAR . '=manifest', 'top');
        add_rewrite_rule("^{$path}/aktionen/?$", 'index.php?' . self::QUERY_VAR . '=aktionen', 'top');
        add_rewrite_rule("^{$path}/aktionen/(neu|\\d+)/?$", 'index.php?' . self::QUERY_VAR . '=aktion-$matches[1]', 'top');

        // Regeln neu schreiben, sobald sich Pfad oder Regeln ändern
        $signature = 'v4|' . self::path();
        if (get_option('novemberkind_produkte_rewrite') !== $signature) {
            flush_rewrite_rules(false);
            update_option('novemberkind_produkte_rewrite', $signature);
        }
    }

    public function maybe_render(): void
    {
        $route = (string) get_query_var(self::QUERY_VAR);
        if ($route === '') {
            return;
        }

        // Safari lädt das Manifest ohne Anmeldung; es enthält nur Name, Farben und Icons
        if ($route === 'manifest') {
            $this->manifest();
        }

        nocache_headers();

        if (!is_user_logged_in()) {
            wp_safe_redirect(wp_login_url(self::url_for_route($route)));
            exit;
        }
        if (!current_user_can(Plugin::CAPABILITY)) {
            wp_die(
                esc_html__('Für die Produktverwaltung fehlen dir die Berechtigungen.', 'novemberkind-produkte'),
                esc_html__('Keine Berechtigung', 'novemberkind-produkte'),
                ['response' => 403, 'back_link' => true]
            );
        }

        status_header(200);
        send_frame_options_header();
        $this->render($route);
        exit;
    }

    /**
     * Plugin-Version plus Änderungszeit der Datei, damit Browser nach jeder Änderung die neue Fassung laden.
     */
    private static function asset_version(string $file): string
    {
        $path = dirname(PLUGIN_FILE) . '/' . $file;

        return VERSION . '.' . (is_readable($path) ? (string) filemtime($path) : '0');
    }

    public static function manifest_url(): string
    {
        return self::url() . 'manifest.webmanifest';
    }

    /**
     * Web-App-Manifest, damit die Seite auf dem Home-Bildschirm wie eine eigene App startet.
     */
    private function manifest(): void
    {
        $icons = plugin_dir_url(PLUGIN_FILE) . 'assets/icons/';
        $home  = (string) wp_parse_url(home_url('/'), PHP_URL_PATH);

        status_header(200);
        header('Content-Type: application/manifest+json; charset=utf-8');
        header('Cache-Control: public, max-age=86400');
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
     */
    public function login_redirect(string $redirect_to, string $requested, \WP_User|\WP_Error $user): string
    {
        if (!$user instanceof \WP_User || $user->has_cap('manage_options') || !$user->has_cap(Plugin::CAPABILITY)) {
            return $redirect_to;
        }
        if ($requested === '' || untrailingslashit($requested) === untrailingslashit(admin_url())) {
            return self::url();
        }

        return $redirect_to;
    }

    public function login_style(): void
    {
        wp_enqueue_style('novemberkind-produkte-login', plugin_dir_url(PLUGIN_FILE) . 'assets/css/login.css', [], self::asset_version('assets/css/login.css'));
    }

    private static function url_for_route(string $route): string
    {
        return match (true) {
            $route === 'overview'           => self::url(),
            $route === 'aktionen'           => self::campaigns_url(),
            $route === 'aktion-neu'         => self::campaigns_url('neu'),
            str_starts_with($route, 'aktion-') => self::campaigns_url((int) substr($route, 7)),
            $route === 'neu'                => self::new_url(),
            str_starts_with($route, 'neu-') => self::new_url(substr($route, 4)),
            default                         => self::edit_url((int) $route),
        };
    }

    private function render(string $route): void
    {
        $base = plugin_dir_url(PLUGIN_FILE);
        wp_register_style('novemberkind-produkte-app', $base . 'assets/css/app.css', [], self::asset_version('assets/css/app.css'));
        wp_register_script('novemberkind-produkte-app', $base . 'assets/js/app.js', [], self::asset_version('assets/js/app.js'), true);
        wp_register_script('novemberkind-produkte-vine', $base . 'assets/js/vine.js', [], self::asset_version('assets/js/vine.js'), true);
        wp_register_script('novemberkind-produkte-campaigns', $base . 'assets/js/campaigns.js', [], self::asset_version('assets/js/campaigns.js'), true);
        wp_localize_script('novemberkind-produkte-app', 'novemberkindProdukte', [
            'ajaxUrl'        => admin_url('admin-ajax.php'),
            'nonce'          => wp_create_nonce(Ajax::NONCE),
            'appUrl'         => self::url(),
            'maxUploadBytes' => wp_max_upload_size(),
            'maxWidth'       => ImageProcessor::MAX_WIDTH,
            'suggestions'    => Suggestions::is_available(),
            'i18n'           => [
                'saving'         => __('Wird gespeichert …', 'novemberkind-produkte'),
                'save'           => __('Speichern', 'novemberkind-produkte'),
                'waitForUpload'  => __('Einen Moment noch, die Fotos werden gerade hochgeladen.', 'novemberkind-produkte'),
                'unreadable'     => __('Dieses Foto kann der Browser nicht öffnen. Bitte als JPEG oder PNG versuchen.', 'novemberkind-produkte'),
                'networkError'   => __('Keine Verbindung zum Shop. Bitte prüfe die Internetverbindung und versuche es noch einmal.', 'novemberkind-produkte'),
                'loggedOut'      => __('Du bist inzwischen abgemeldet. Bitte lade die Seite neu und melde dich wieder an.', 'novemberkind-produkte'),
                'unsaved'        => __('Es gibt ungespeicherte Änderungen.', 'novemberkind-produkte'),
                'suggesting'     => __('Claude denkt nach …', 'novemberkind-produkte'),
                'suggest'        => __('Vorschlag holen', 'novemberkind-produkte'),
                'modeNew'        => __('Die Beschreibung war noch unvollständig. Claude hat sie nach der Vorlage neu geschrieben.', 'novemberkind-produkte'),
                'modeImproved'   => __('Claude hat deine Beschreibung behutsam überarbeitet.', 'novemberkind-produkte'),
                'view'           => __('Ansehen', 'novemberkind-produkte'),
                'download'       => __('Herunterladen', 'novemberkind-produkte'),
                'resetText'      => __('Deine Änderungen an der Beschreibung gehen dabei verloren. Trotzdem neu erstellen?', 'novemberkind-produkte'),
            ],
        ]);

        wp_localize_script('novemberkind-produkte-campaigns', 'novemberkindAktionen', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce(Ajax::NONCE),
            'i18n'    => [
                'saving'       => __('Wird gespeichert …', 'novemberkind-produkte'),
                'save'         => __('Speichern', 'novemberkind-produkte'),
                'networkError' => __('Keine Verbindung zum Shop. Bitte prüfe die Internetverbindung und versuche es noch einmal.', 'novemberkind-produkte'),
                'loggedOut'    => __('Du bist inzwischen abgemeldet. Bitte lade die Seite neu und melde dich wieder an.', 'novemberkind-produkte'),
                'unsaved'      => __('Es gibt ungespeicherte Änderungen.', 'novemberkind-produkte'),
                'confirmEnd'   => __('Die Aktion endet sofort, die Preise im Shop sind dann wieder normal. Beenden?', 'novemberkind-produkte'),
            ],
        ]);

        $title = __('Meine Produkte', 'novemberkind-produkte');
        $view  = 'product-form';
        if ($route === 'aktionen') {
            $view  = 'campaigns';
            $title = __('Aktionen', 'novemberkind-produkte');
            $data  = ['campaigns' => Campaigns::all()];
        } elseif (str_starts_with($route, 'aktion-')) {
            $view     = 'campaign-form';
            $campaign = $route === 'aktion-neu' ? null : Campaigns::get((int) substr($route, 7));
            $data     = $route === 'aktion-neu' || $campaign ? $this->campaign_form_data($campaign) : null;
            $title    = $campaign ? $campaign['name'] : __('Neue Aktion', 'novemberkind-produkte');
        } elseif ($route === 'overview') {
            $view = 'overview';
            $data = $this->overview_data();
        } elseif ($route === 'neu') {
            $view  = 'type-picker';
            $title = __('Neues Produkt', 'novemberkind-produkte');
            $data  = ['types' => ProductType::all()];
        } elseif (str_starts_with($route, 'neu-')) {
            $type = ProductType::get(substr($route, 4));
            $data = $type ? $this->form_data($type) : null;
            /* translators: %s: Produktart, z. B. Button */
            $title = $type ? sprintf(__('Neu: %s', 'novemberkind-produkte'), $type->label()) : $title;
        } else {
            $product = wc_get_product((int) $route);
            $type    = $product ? ProductType::detect($product) : null;
            if ($product && !$type && current_user_can('edit_post', $product->get_id())) {
                // Produkte ohne Vorlage werden in der WooCommerce-Maske bearbeitet
                wp_safe_redirect((string) get_edit_post_link($product->get_id(), 'raw'));
                exit;
            }
            $data  = $product && $type ? $this->form_data($type, $product) : null;
            $title = $product ? $product->get_name() : $title;
        }

        if ($data === null) {
            status_header(404);
            $data = ['missing' => $view === 'campaign-form' ? __('Diese Aktion gibt es nicht mehr.', 'novemberkind-produkte') : __('Dieses Produkt gibt es nicht mehr.', 'novemberkind-produkte')];
            $view = 'not-found';
        }

        extract($data, EXTR_SKIP); // phpcs:ignore WordPress.PHP.DontExtract -- Variablen für das Template
        include __DIR__ . '/../templates/app.php';
    }

    /**
     * @return array{products: \WC_Product[]}
     */
    private function overview_data(): array
    {
        $products = wc_get_products([
            'status'  => ['publish', 'future', 'draft', 'pending', 'private'],
            'limit'   => -1,
            'orderby' => 'modified',
            'order'   => 'DESC',
        ]);

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
                    $categories[] = ['id' => $term->term_id, 'name' => $term->name, 'depth' => $depth, 'count' => (int) $term->count];
                    $add($term->term_id, $depth + 1);
                }
            }
        };
        $add(0, 0);

        $products = array_map(static fn(\WC_Product $product): array => [
            'id'      => $product->get_id(),
            'name'    => $product->get_name(),
            'sku'     => $product->get_sku(),
            'own_sale' => !$product instanceof \WC_Product_Variable && $product->is_on_sale('edit'),
        ], wc_get_products([
            'status'  => ['publish', 'future', 'draft', 'pending', 'private'],
            'limit'   => -1,
            'orderby' => 'title',
            'order'   => 'ASC',
        ]));

        return [
            'campaign'   => $campaign,
            'categories' => $categories,
            'products'   => $products,
            'conflicts'  => $campaign ? Campaigns::conflicts($campaign) : ['overlaps' => [], 'reference' => []],
        ];
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
