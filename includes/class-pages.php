<?php

declare(strict_types=1);

namespace NovemberkindProdukte;

defined('ABSPATH') || exit;

/**
 * Daten für die Seiten der Produktverwaltung. Jede öffentliche Methode gehört zu einer Route in App::ROUTES
 * und liefert Template, Titel und Variablen, oder eine Seite mit 404, wenn es den Eintrag nicht gibt.
 *
 * @phpstan-type Page array{view: string, title: string, data: array<string, mixed>}
 */
final class Pages
{
    /**
     * @param array<string, mixed>|null $data Variablen für das Template, null für „gibt es nicht“
     * @phpstan-return Page
     * @return array<string, mixed>
     */
    private static function page(string $view, string $title, ?array $data, string $missing = ''): array
    {
        return $data === null ? self::missing($missing) : ['view' => $view, 'title' => $title, 'data' => $data];
    }

    /**
     * Seite mit Status 404 und einer Meldung, was fehlt.
     *
     * @phpstan-return Page
     * @return array<string, mixed>
     */
    public static function missing(string $message): array
    {
        status_header(404);

        return [
            'view'  => 'not-found',
            'title' => __('Nicht gefunden', 'novemberkind-produkte'),
            'data'  => ['missing' => $message],
        ];
    }

    /**
     * @phpstan-return Page
     * @return array<string, mixed>
     */
    public function overview(): array
    {
        return self::page('overview', __('Meine Produkte', 'novemberkind-produkte'), self::overview_data());
    }

    /**
     * @phpstan-return Page
     * @return array<string, mixed>
     */
    public function type_picker(): array
    {
        return self::page('type-picker', __('Neues Produkt', 'novemberkind-produkte'), ['types' => ProductType::all()]);
    }

    /**
     * @phpstan-return Page
     * @return array<string, mixed>
     */
    public function new_product(string $key): array
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
     * @phpstan-return Page
     * @return array<string, mixed>
     */
    public function edit_product(string $id): array
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
     * @phpstan-return Page
     * @return array<string, mixed>
     */
    public function campaigns(): array
    {
        return self::page('campaigns', __('Aktionen', 'novemberkind-produkte'), ['campaigns' => Campaigns::all()]);
    }

    /**
     * @phpstan-return Page
     * @return array<string, mixed>
     */
    public function campaign_form(string $id): array
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
     * @phpstan-return Page
     * @return array<string, mixed>
     */
    public function coupons(): array
    {
        return self::page('coupons', __('Gutscheine', 'novemberkind-produkte'), ['coupons' => Coupons::all()]);
    }

    /**
     * @phpstan-return Page
     * @return array<string, mixed>
     */
    public function coupon_form(string $id): array
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
     * @phpstan-return Page
     * @return array<string, mixed>
     */
    public function newsletters(): array
    {
        (new Newsletters())->resume_stalled();

        return self::page('newsletters', __('Newsletter', 'novemberkind-produkte'), ['issues' => Newsletters::all(), 'counts' => Subscribers::counts()]);
    }

    /**
     * @phpstan-return Page
     * @return array<string, mixed>
     */
    public function subscribers(): array
    {
        (new Subscribers())->cleanup();

        return self::page('subscribers', __('Abonnenten', 'novemberkind-produkte'), ['subscribers' => Subscribers::all()]);
    }

    /**
     * @phpstan-return Page
     * @return array<string, mixed>
     */
    public function newsletter_form(string $id): array
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
        $leaves = array_values(array_filter(array_map(
            static fn(int $term_id): mixed => get_term($term_id, 'product_cat'),
            ShopData::leaf_categories($product->get_id())
        ), static fn(mixed $term): bool => $term instanceof \WP_Term));
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

        $back_images = !$type->is_variable() ? [] : ($product ? $type->back_images_of($product) : $type->back_image_ids());

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
                static fn(int $id): bool => !$type->is_back_image($id)
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
