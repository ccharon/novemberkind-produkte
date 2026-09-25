<?php

declare(strict_types=1);

namespace NovemberkindProdukte;

defined('ABSPATH') || exit;

/**
 * Rabattaktionen: senken Preise während ihrer Laufzeit nur beim Auslesen, die Produkte bleiben unverändert.
 *
 * @phpstan-type Campaign array{id: int, name: string, percent: int, start: int, end: int, scope: string, categories: int[], products: int[]}
 */
final class Campaigns
{
    public const POST_TYPE = 'novemberkind_aktion';
    public const META = '_novemberkind_produkte_campaign';
    public const MAX_PERCENT = 90;
    // Preisangabenverordnung: Vergleichspreis ist der niedrigste Preis der letzten 30 Tage
    public const REFERENCE_DAYS = 30;
    public const SCOPES = ['all', 'categories', 'products'];

    /** @var array<int, array<string, mixed>>|null alle Aktionen dieser Anfrage */
    private static ?array $cache = null;

    /** @var array<int, int> Rabatt in Prozent je Produkt-ID in dieser Anfrage */
    private static array $discounts = [];

    /** @var array<int, int[]> Kategorien samt übergeordneten je Produkt-ID */
    private static array $categories = [];

    public function register(): void
    {
        add_action('init', [$this, 'register_post_type']);
        foreach (['woocommerce_product_get_price', 'woocommerce_product_get_sale_price', 'woocommerce_product_variation_get_price', 'woocommerce_product_variation_get_sale_price'] as $hook) {
            add_filter($hook, [$this, 'filter_price'], 20, 2);
        }
        // Preisspannen variabler Produkte berechnet WooCommerce aus den Rohwerten der Varianten
        add_filter('woocommerce_variation_prices_price', [$this, 'filter_price'], 20, 2);
        add_filter('woocommerce_variation_prices_sale_price', [$this, 'filter_price'], 20, 2);
        add_filter('woocommerce_get_variation_prices_hash', [$this, 'prices_hash']);
        add_action('save_post_' . self::POST_TYPE, [self::class, 'flush']);
    }

    public function register_post_type(): void
    {
        register_post_type(self::POST_TYPE, [
            'label'               => __('Rabattaktionen', 'novemberkind-produkte'),
            'public'              => false,
            'publicly_queryable'  => false,
            'exclude_from_search' => true,
            'show_ui'             => false,
            'show_in_rest'        => false,
            'show_in_nav_menus'   => false,
            'rewrite'             => false,
            'query_var'           => false,
            'can_export'          => false,
            'supports'            => ['title'],
            'capability_type'     => 'product',
            'map_meta_cap'        => true,
        ]);
    }

    /**
     * Verwirft die Zwischenspeicher dieser Anfrage nach einer Änderung.
     */
    public static function flush(): void
    {
        self::$cache      = null;
        self::$discounts  = [];
        self::$categories = [];
    }

    /**
     * @return array<int, array<string, mixed>> alle Aktionen, zuletzt gestartete zuerst
     * @phpstan-return array<int, Campaign>
     */
    public static function all(): array
    {
        if (self::$cache === null) {
            self::$cache = [];
            $posts = get_posts([
                'post_type'      => self::POST_TYPE,
                'post_status'    => 'private',
                'posts_per_page' => -1,
                'no_found_rows'  => true,
            ]);
            foreach ($posts as $post) {
                $campaign = self::from_post($post);
                if ($campaign !== null) {
                    self::$cache[$campaign['id']] = $campaign;
                }
            }
            uasort(self::$cache, static fn(array $a, array $b): int => $b['start'] <=> $a['start'] ?: $b['id'] <=> $a['id']);
            // Erlaubt Tests, nur ihre eigenen Aktionen zu berücksichtigen
            self::$cache = (array) apply_filters('novemberkind_produkte_campaigns', self::$cache);
        }

        return self::$cache;
    }

    /**
     * @phpstan-return Campaign|null
     * @return array<string, mixed>|null
     */
    public static function get(int $id): ?array
    {
        return self::all()[$id] ?? null;
    }

    /**
     * @phpstan-param Campaign $campaign
     * @param array<string, mixed> $campaign
     * @return string planned, running oder ended
     */
    public static function status(array $campaign, ?int $now = null): string
    {
        $now ??= time();

        return match (true) {
            $campaign['end'] <= $now || $campaign['end'] <= $campaign['start'] => 'ended',
            $campaign['start'] > $now                                          => 'planned',
            default                                                            => 'running',
        };
    }

    /**
     * @return array<int, array<string, mixed>>
     * @phpstan-return array<int, Campaign>
     */
    public static function running(): array
    {
        return array_filter(self::all(), static fn(array $campaign): bool => self::status($campaign) === 'running');
    }

    /**
     * Legt eine Aktion an oder ändert sie. Beendete Aktionen bleiben unverändert als Nachweis für die 30-Tage-Regel.
     *
     * @param array<string, mixed> $data Rohdaten aus dem Formular
     * @return array<string, mixed>|\WP_Error
     * @phpstan-return Campaign|\WP_Error
     */
    public function save(array $data, int $id = 0): array|\WP_Error
    {
        $existing = $id ? self::get($id) : null;
        if ($id && $existing === null) {
            return new \WP_Error('not_found', __('Diese Aktion gibt es nicht mehr.', 'novemberkind-produkte'));
        }
        if ($existing !== null && self::status($existing) === 'ended') {
            return new \WP_Error('ended', __('Beendete Aktionen lassen sich nicht mehr ändern. Lege bei Bedarf eine neue an.', 'novemberkind-produkte'));
        }

        $errors = [];
        $name   = trim(sanitize_text_field(wp_unslash((string) ($data['name'] ?? ''))));
        if ($name === '') {
            $errors['name'] = __('Bitte gib der Aktion einen Namen, z. B. Herbstaktion.', 'novemberkind-produkte');
        }

        $percent_raw = trim((string) ($data['percent'] ?? ''));
        $percent     = ctype_digit($percent_raw) ? (int) $percent_raw : 0;
        if ($percent < 1 || $percent > self::MAX_PERCENT) {
            /* translators: %d: höchster erlaubter Rabatt */
            $errors['percent'] = sprintf(__('Bitte gib einen Rabatt zwischen 1 und %d Prozent ein.', 'novemberkind-produkte'), self::MAX_PERCENT);
        }

        $start = ProductService::parse_local_datetime((string) ($data['start'] ?? ''));
        $end   = ProductService::parse_local_datetime((string) ($data['end'] ?? ''));
        if ($start === null) {
            $errors['start'] = __('Bitte wähle, wann die Aktion beginnt.', 'novemberkind-produkte');
        }
        if ($end === null) {
            $errors['end'] = __('Bitte wähle, wann die Aktion endet.', 'novemberkind-produkte');
        } elseif ($start !== null && $end <= $start) {
            $errors['end'] = __('Das Ende muss nach dem Beginn liegen.', 'novemberkind-produkte');
        } elseif ($end <= time()) {
            $errors['end'] = __('Das Ende liegt in der Vergangenheit.', 'novemberkind-produkte');
        }

        $scope = (string) ($data['scope'] ?? '');
        if (!in_array($scope, self::SCOPES, true)) {
            $scope = 'all';
        }
        $categories = [];
        $products   = [];
        if ($scope === 'categories') {
            $categories = array_values(array_filter(
                array_unique(array_map('absint', (array) ($data['categories'] ?? []))),
                static fn(int $term_id): bool => term_exists($term_id, 'product_cat') !== null
            ));
            if ($categories === []) {
                $errors['categories'] = __('Bitte wähle mindestens eine Kategorie.', 'novemberkind-produkte');
            }
        } elseif ($scope === 'products') {
            $products = array_values(array_filter(
                array_unique(array_map('absint', (array) ($data['products'] ?? []))),
                static fn(int $product_id): bool => get_post_type($product_id) === 'product'
            ));
            if ($products === []) {
                $errors['products'] = __('Bitte wähle mindestens ein Produkt.', 'novemberkind-produkte');
            }
        }

        if ($errors !== []) {
            return new \WP_Error('invalid', __('Bitte prüfe die markierten Felder.', 'novemberkind-produkte'), $errors);
        }

        $post_id = wp_insert_post([
            'ID'          => $id,
            'post_type'   => self::POST_TYPE,
            'post_status' => 'private',
            'post_title'  => $name,
        ], true);
        if (is_wp_error($post_id)) {
            return new \WP_Error('save', __('Die Aktion konnte nicht gespeichert werden.', 'novemberkind-produkte'));
        }

        return $this->store($post_id, [
            'percent'    => $percent,
            'start'      => (int) $start,
            'end'        => (int) $end,
            'scope'      => $scope,
            'categories' => $categories,
            'products'   => $products,
        ]);
    }

    /**
     * Beendet eine laufende Aktion sofort. Eine geplante startet gar nicht erst.
     *
     * @return array<string, mixed>|\WP_Error
     * @phpstan-return Campaign|\WP_Error
     */
    public function end(int $id): array|\WP_Error
    {
        $campaign = self::get($id);
        if ($campaign === null) {
            return new \WP_Error('not_found', __('Diese Aktion gibt es nicht mehr.', 'novemberkind-produkte'));
        }
        if (self::status($campaign) === 'ended') {
            return $campaign;
        }

        $now = time();
        $campaign['start'] = min($campaign['start'], $now);
        $campaign['end']   = $now;

        return $this->store($id, $campaign);
    }

    /**
     * Alle Produkte (Hauptprodukte), für die eine Aktion gilt.
     *
     * @phpstan-param Campaign $campaign
     * @param array<string, mixed> $campaign
     * @return int[]
     */
    public static function product_ids(array $campaign): array
    {
        $args = ['limit' => -1, 'return' => 'ids', 'status' => ['publish', 'future', 'draft', 'pending', 'private']];

        return match ($campaign['scope']) {
            'products'   => $campaign['products'],
            'categories' => array_map('intval', wc_get_products($args + ['category' => self::category_slugs($campaign['categories'])])),
            default      => array_map('intval', wc_get_products($args)),
        };
    }

    /**
     * Kurzbeschreibung des Umfangs für die Liste, z. B. „Kategorien: Sticker, Karten“.
     *
     * @phpstan-param Campaign $campaign
     * @param array<string, mixed> $campaign
     */
    public static function scope_label(array $campaign): string
    {
        if ($campaign['scope'] === 'products') {
            $count = count($campaign['products']);
            /* translators: %d: Anzahl der Produkte */
            return sprintf(_n('%d Produkt', '%d Produkte', $count, 'novemberkind-produkte'), $count);
        }
        if ($campaign['scope'] === 'categories') {
            $names = array_filter(array_map(static function (int $term_id): string {
                $term = get_term($term_id, 'product_cat');
                return $term instanceof \WP_Term ? $term->name : '';
            }, $campaign['categories']));
            /* translators: %s: Namen der Kategorien */
            return sprintf(__('Kategorien: %s', 'novemberkind-produkte'), implode(', ', $names));
        }

        return __('Ganzer Shop', 'novemberkind-produkte');
    }

    /**
     * Hinweise zu anderen Aktionen mit denselben Produkten: gleichzeitig laufend oder kurz vorher beendet.
     *
     * @phpstan-param Campaign $campaign
     * @param array<string, mixed> $campaign
     * @return array{overlaps: string[], reference: string[]}
     */
    public static function conflicts(array $campaign): array
    {
        $result = ['overlaps' => [], 'reference' => []];
        if ($campaign['end'] <= $campaign['start']) {
            return $result;
        }

        $mine = null;
        foreach (self::all() as $other) {
            if ($other['id'] === $campaign['id'] || $other['end'] <= $other['start']) {
                continue;
            }
            $overlaps  = $other['start'] < $campaign['end'] && $other['end'] > $campaign['start'];
            $reference = !$overlaps && $other['end'] <= $campaign['start'] && $other['end'] > $campaign['start'] - self::REFERENCE_DAYS * DAY_IN_SECONDS;
            if (!$overlaps && !$reference) {
                continue;
            }
            $mine ??= self::product_ids($campaign);
            if (array_intersect($mine, self::product_ids($other)) === []) {
                continue;
            }
            $result[$overlaps ? 'overlaps' : 'reference'][] = $other['name'];
        }

        return $result;
    }

    /**
     * Rabatt in Prozent, der gerade für ein Produkt oder eine Variante gilt. Der höchste von mehreren gewinnt.
     */
    public function discount_for(\WC_Product $product): int
    {
        $id = $product->get_id();
        if (isset(self::$discounts[$id])) {
            return self::$discounts[$id];
        }

        $percent = 0;
        // Produkte mit eigenem Angebotspreis sind ausgenommen; variable Hauptprodukte prüft WooCommerce über ihre Varianten
        if ($product instanceof \WC_Product_Variable || !$product->is_on_sale('edit')) {
            $parent_id = $product->get_parent_id() ?: $id;
            foreach (self::running() as $campaign) {
                if ($campaign['percent'] > $percent && $this->covers($campaign, $parent_id)) {
                    $percent = $campaign['percent'];
                }
            }
        }

        return self::$discounts[$id] = $percent;
    }

    /**
     * Senkt Preis und Angebotspreis beim Auslesen. Grundlage ist immer der normale Preis.
     */
    public function filter_price(mixed $price, \WC_Product $product): mixed
    {
        $regular = (string) $product->get_regular_price('edit');
        if ($regular === '' || (float) $regular <= 0) {
            return $price;
        }
        $percent = $this->discount_for($product);
        if ($percent === 0) {
            return $price;
        }

        return wc_format_decimal(round((float) $regular * (100 - $percent) / 100, wc_get_price_decimals()), wc_get_price_decimals());
    }

    /**
     * Damit WooCommerce zwischengespeicherte Preisspannen bei Start, Ende oder Änderung einer Aktion neu berechnet.
     *
     * @param array<int|string, mixed> $hash
     * @return array<int|string, mixed>
     */
    public function prices_hash(array $hash): array
    {
        $hash['novemberkind_campaigns'] = array_map(
            static fn(array $campaign): string => implode(':', [$campaign['id'], $campaign['percent'], $campaign['start'], $campaign['end'], md5((string) wp_json_encode([$campaign['scope'], $campaign['categories'], $campaign['products']]))]),
            array_values(self::running())
        );

        return $hash;
    }

    /**
     * @phpstan-param Campaign $campaign
     * @param array<string, mixed> $campaign
     */
    private function covers(array $campaign, int $product_id): bool
    {
        return match ($campaign['scope']) {
            'products'   => in_array($product_id, $campaign['products'], true),
            'categories' => array_intersect($campaign['categories'], $this->category_ids($product_id)) !== [],
            default      => true,
        };
    }

    /**
     * @return int[] Kategorien eines Produkts mit allen übergeordneten, damit eine Aktion auf „Physische Produkte“ auch Sticker erfasst
     */
    private function category_ids(int $product_id): array
    {
        if (!isset(self::$categories[$product_id])) {
            $ids = [];
            foreach (wc_get_product_term_ids($product_id, 'product_cat') as $term_id) {
                $ids = [...$ids, $term_id, ...array_map('intval', get_ancestors($term_id, 'product_cat', 'taxonomy'))];
            }
            self::$categories[$product_id] = array_values(array_unique($ids));
        }

        return self::$categories[$product_id];
    }

    /**
     * @param int[] $term_ids
     * @return string[]
     */
    private static function category_slugs(array $term_ids): array
    {
        $slugs = [];
        foreach ($term_ids as $term_id) {
            $term = get_term($term_id, 'product_cat');
            if ($term instanceof \WP_Term) {
                $slugs[] = $term->slug;
            }
        }

        return $slugs === [] ? ['-'] : $slugs;
    }

    /**
     * @param array<string, mixed> $values
     * @return array<string, mixed>|\WP_Error
     * @phpstan-return Campaign|\WP_Error
     */
    private function store(int $post_id, array $values): array|\WP_Error
    {
        $meta = [
            'percent'    => (int) $values['percent'],
            'start'      => (int) $values['start'],
            'end'        => (int) $values['end'],
            'scope'      => (string) $values['scope'],
            'categories' => array_map('intval', (array) $values['categories']),
            'products'   => array_map('intval', (array) $values['products']),
        ];
        update_post_meta($post_id, self::META, $meta);
        self::flush();

        return self::get($post_id) ?? new \WP_Error('save', __('Die Aktion konnte nicht gespeichert werden.', 'novemberkind-produkte'));
    }

    /**
     * @return array<string, mixed>|null
     * @phpstan-return Campaign|null
     */
    private static function from_post(\WP_Post $post): ?array
    {
        $meta = get_post_meta($post->ID, self::META, true);
        if (!is_array($meta)) {
            return null;
        }

        return [
            'id'         => $post->ID,
            'name'       => $post->post_title,
            'percent'    => (int) ($meta['percent'] ?? 0),
            'start'      => (int) ($meta['start'] ?? 0),
            'end'        => (int) ($meta['end'] ?? 0),
            'scope'      => in_array($meta['scope'] ?? '', self::SCOPES, true) ? (string) $meta['scope'] : 'all',
            'categories' => array_map('intval', (array) ($meta['categories'] ?? [])),
            'products'   => array_map('intval', (array) ($meta['products'] ?? [])),
        ];
    }
}
