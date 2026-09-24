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

    public function register(): void
    {
        add_action('init', [$this, 'add_rewrite_rules']);
        add_filter('query_vars', static fn(array $vars): array => [...$vars, self::QUERY_VAR]);
        add_action('template_redirect', [$this, 'maybe_render']);
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

        // Regeln neu schreiben, sobald sich Pfad oder Regeln ändern
        $signature = 'v2|' . self::path();
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
        wp_enqueue_style('novemberkind-produkte-login', plugin_dir_url(PLUGIN_FILE) . 'assets/css/login.css', [], VERSION);
    }

    private static function url_for_route(string $route): string
    {
        return match (true) {
            $route === 'overview'           => self::url(),
            $route === 'neu'                => self::new_url(),
            str_starts_with($route, 'neu-') => self::new_url(substr($route, 4)),
            default                         => self::edit_url((int) $route),
        };
    }

    private function render(string $route): void
    {
        $base = plugin_dir_url(PLUGIN_FILE);
        wp_register_style('novemberkind-produkte-app', $base . 'assets/css/app.css', [], VERSION);
        wp_register_script('novemberkind-produkte-app', $base . 'assets/js/app.js', [], VERSION, true);
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
                'resetText'      => __('Deine Änderungen an der Beschreibung gehen dabei verloren. Trotzdem neu erstellen?', 'novemberkind-produkte'),
            ],
        ]);

        $title = __('Meine Produkte', 'novemberkind-produkte');
        $view  = 'product-form';
        if ($route === 'overview') {
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
            $view = 'not-found';
            $data = [];
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
            'status'  => ['publish', 'draft', 'pending', 'private'],
            'limit'   => -1,
            'orderby' => 'date',
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

        return [
            'type'        => $type,
            'product'     => $product,
            'context'     => $context,
            'description' => $product ? wp_kses_post($product->get_description()) : $type->description($context),
            'custom_description' => $product && $type->has_custom_description($product),
            'motif_tags'  => $product ? ProductService::motif_tags($product, $type) : [],
            'price'       => $product ? self::current_price($product) : (string) $type->config('price'),
            'gallery_ids' => $product ? array_values(array_filter(
                array_map('intval', $product->get_gallery_image_ids()),
                static fn(int $id): bool => !$service->is_variation_image($type, $id)
            )) : [],
            'back_images' => $back_images,
        ];
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
