<?php

declare(strict_types=1);

namespace NovemberkindProdukte;

defined('ABSPATH') || exit;

/**
 * Legt Produkte nach der Vorlage ihrer Produktart an und ändert sie. Regeln siehe CLAUDE.md.
 */
final class ProductService
{
    private const STATUSES = ['draft', 'publish', 'future'];

    // Artikelnummern wie A000123: Muster mit der Zahl als Gruppe und Format für die nächste freie Nummer
    public const SKU_PATTERN = '/^A(\d{6})$/';
    public const SKU_FORMAT = 'A%06d';
    // Preise mit Cent, Gewicht auf Gramm genau
    private const PRICE_DECIMALS = 2;
    private const WEIGHT_DECIMALS = 3;

    // Seitenlayout des Themes Divi wie bei allen bestehenden Produkten: ohne Seitenleiste
    private const DIVI_LAYOUT = '_et_pb_page_layout';
    private const DIVI_DEFAULTS = [
        '_et_pb_page_layout'  => 'et_no_sidebar',
        '_et_pb_side_nav'     => 'off',
        '_et_pb_post_hide_nav' => 'default',
    ];

    /**
     * @param array<string, mixed> $data Rohdaten aus dem Formular
     * @return \WC_Product|\WP_Error Produkt oder Fehler mit Meldungen je Feld in `get_error_data()`
     */
    public function save(ProductType $type, array $data, int $product_id = 0): \WC_Product|\WP_Error
    {
        $is_new = $product_id === 0;
        [$context, $errors] = $type->parse($data);
        // Karten mit A4 sind Variantenprodukte mit den Größen A6 und A4
        $with_a4 = $type->has_field('a4') && ($context['a4'] ?? '') === '1';

        if ($is_new) {
            $product = $type->is_variable() || $with_a4 ? new \WC_Product_Variable() : new \WC_Product_Simple();
        } else {
            $product = wc_get_product($product_id);
            if (!$product instanceof \WC_Product) {
                return new \WP_Error('not_found', __('Dieses Produkt gibt es nicht mehr.', 'novemberkind-produkte'));
            }
            if (ProductType::detect($product)?->key() !== $type->key()) {
                return new \WP_Error('wrong_type', __('Dieses Produkt passt nicht zur gewählten Produktart.', 'novemberkind-produkte'));
            }
        }

        $sku = strtoupper(trim(sanitize_text_field((string) ($data['sku'] ?? ''))));
        if (!preg_match(self::SKU_PATTERN, $sku)) {
            $errors['sku'] = __('Bitte gib die Artikelnummer im Format A000123 ein.', 'novemberkind-produkte');
        } else {
            $owner = wc_get_product_id_by_sku($sku);
            if ($owner && $owner !== $product->get_id()) {
                /* translators: 1: Artikelnummer, 2: Produktname */
                $errors['sku'] = sprintf(__('Die Artikelnummer %1$s gehört schon zu „%2$s“.', 'novemberkind-produkte'), $sku, get_the_title($owner));
            }
        }

        $price = self::parse_price((string) ($data['price'] ?? ''));
        if ($price === null) {
            $errors['price'] = __('Bitte gib einen Preis ein, z. B. 24,90.', 'novemberkind-produkte');
        }

        $stock = null;
        if (!$type->is_unique()) {
            $stock_raw = trim((string) ($data['stock'] ?? ''));
            if ($stock_raw !== '' && !ctype_digit($stock_raw)) {
                $errors['stock'] = __('Der Lagerbestand muss eine ganze Zahl ab 0 sein.', 'novemberkind-produkte');
            }
            $stock = $stock_raw === '' ? null : (int) $stock_raw;
        }

        $status = (string) ($data['status'] ?? 'draft');
        if (!in_array($status, self::STATUSES, true)) {
            $status = 'draft';
        }
        $publish_at = null;
        if ($status === 'future') {
            $publish_at = self::parse_local_datetime((string) ($data['publish_date'] ?? ''), (string) ($data['publish_time'] ?? ''));
            if ($publish_at === null) {
                $errors['publish_at'] = __('Bitte wähle das Datum für die Veröffentlichung.', 'novemberkind-produkte');
            } elseif ($publish_at <= time()) {
                $errors['publish_at'] = __('Der Zeitpunkt liegt in der Vergangenheit. Wähle einen späteren oder stelle das Produkt direkt online.', 'novemberkind-produkte');
            }
        }

        $price_a4 = null;
        $stock_a4 = null;
        if ($with_a4) {
            $price_a4 = self::parse_price((string) ($data['price_a4'] ?? ''));
            if ($price_a4 === null) {
                $errors['price_a4'] = __('Bitte gib einen Preis für A4 ein, z. B. 5,00.', 'novemberkind-produkte');
            }
            $stock_a4_raw = trim((string) ($data['stock_a4'] ?? ''));
            if ($stock_a4_raw !== '' && !ctype_digit($stock_a4_raw)) {
                $errors['stock_a4'] = __('Der Lagerbestand muss eine ganze Zahl ab 0 sein.', 'novemberkind-produkte');
            }
            $stock_a4 = $stock_a4_raw === '' ? null : (int) $stock_a4_raw;
        }

        // null heißt: Feld nicht gesendet, der bisherige Angebotspreis bleibt (z. B. mit Zeitraum aus WooCommerce)
        $sale    = self::parse_sale($data, 'sale', $price, $errors);
        $sale_a4 = $with_a4 ? self::parse_sale($data, 'sale_a4', $price_a4, $errors) : null;

        if ($errors !== []) {
            return new \WP_Error('invalid', __('Bitte prüfe die markierten Felder.', 'novemberkind-produkte'), $errors);
        }

        // Stand vor der Änderung sichern; ohne Sicherung wird nichts geändert
        if (!$is_new) {
            $backup = (new Backups())->create($product);
            if (is_wp_error($backup)) {
                return $backup;
            }
            // Einfache Karte bekommt A4: WooCommerce wandelt den Typ beim Speichern um, Fotos und Artikelnummer bleiben
            if ($with_a4 && !$product instanceof \WC_Product_Variable) {
                $product = new \WC_Product_Variable($product->get_id());
            }
        }
        $sized = $type->has_field('a4') && $product instanceof \WC_Product_Variable;

        $image_id    = self::usable_image(absint($data['image_id'] ?? 0), $is_new ? null : $product) ? absint($data['image_id']) : 0;
        $gallery_ids = array_values(array_filter(
            array_map('absint', (array) ($data['gallery_ids'] ?? [])),
            static fn(int $id): bool => self::usable_image($id, $is_new ? null : $product)
        ));
        $own_gallery = array_values(array_filter(
            array_diff(array_unique($gallery_ids), [$image_id]),
            fn(int $id): bool => !$this->is_variation_image($type, $id)
        ));
        $gallery_ids = $own_gallery;
        if ($type->is_variable()) {
            $gallery_ids = [...$own_gallery, ...($is_new ? $this->variation_image_ids($type) : $this->variation_images_of($type, $product))];
        }

        $context_changed = $is_new || $context != $type->context_from_product($product);

        $product->set_name($type->product_name($context['motif']));
        $product->set_short_description($type->short_description($context['motif']));

        $description = wp_kses_post(wp_unslash((string) ($data['description'] ?? '')));
        $custom      = ($data['description_custom'] ?? '') === '1' && trim(wp_strip_all_tags($description)) !== '';
        $product->set_description($custom ? $description : $type->description($context));
        $custom ? $product->update_meta_data(ProductType::META_CUSTOM_DESCRIPTION, 'yes') : $product->delete_meta_data(ProductType::META_CUSTOM_DESCRIPTION);

        if ($context_changed) {
            $dimensions = $type->dimensions($context);
            $product->set_length(ProductType::to_shop_unit($dimensions['length'] ?? ''));
            $product->set_width(ProductType::to_shop_unit($dimensions['width'] ?? ''));
            $product->set_height(ProductType::to_shop_unit($dimensions['height'] ?? ''));
        }

        // Kategorien aus anderen Zusammenhängen bleiben beim Ändern erhalten
        $product->set_category_ids(array_values(array_unique([
            ...($is_new ? [] : $product->get_category_ids()),
            ...ShopData::category_ids($type->config('category')),
        ])));
        $product->set_tag_ids($this->tag_ids([...$type->tags($context), ...self::parse_tags((string) ($data['tags'] ?? ''))]));
        $product->set_image_id($image_id ?: '');
        $product->set_gallery_image_ids($gallery_ids);
        $product->set_status($status);
        if ($publish_at !== null) {
            // WordPress veröffentlicht zum Beitragsdatum, WooCommerce führt es als Erstelldatum
            $product->set_date_created($publish_at);
        } elseif (!$is_new && $product->get_date_created('edit')?->getTimestamp() > time()) {
            // Ein Datum in der Zukunft würde WordPress beim Veröffentlichen erneut planen, deshalb zurück auf jetzt
            $product->set_date_created(time());
        }
        foreach (self::DIVI_DEFAULTS as $key => $value) {
            // Das Layout immer setzen, die übrigen Felder nur ergänzen
            if ($key === self::DIVI_LAYOUT || $product->get_meta($key) === '') {
                $product->update_meta_data($key, $value);
            }
        }
        $product->update_meta_data(ProductType::META_TYPE, $type->key());
        $product->update_meta_data(ProductType::META_CONTEXT, $context);

        $sku_changed = $product->get_sku('edit') !== $sku;
        $product->set_sku($sku);

        if ($is_new) {
            $product->set_shipping_class_id(ShopData::shipping_class_id($type->config('shipping_class')));
            $weight = (string) $type->config('weight');
            $product->set_weight($weight === '' ? '' : wc_format_decimal(wc_get_weight((float) $weight, (string) get_option('woocommerce_weight_unit'), 'kg'), self::WEIGHT_DECIMALS, true));
            $product->set_reviews_allowed(true);
            $product->set_backorders('no');
            if ($type->is_unique()) {
                $product->set_manage_stock(true);
                $product->set_stock_quantity(1);
                $product->set_sold_individually(true);
            }
        }

        if (!$type->is_unique() && !$sized) {
            $product->set_manage_stock($stock !== null);
            $product->set_stock_quantity($stock);
            if ($stock === null) {
                $product->set_stock_status('instock');
            }
        }

        if (!$product instanceof \WC_Product_Variable) {
            $product->set_regular_price($price);
            if ($sale !== null) {
                $product->set_sale_price($sale);
            }
        }

        $product->save();
        $this->name_images($product, [$image_id, ...$own_gallery]);
        $this->save_germanized($product, $type, $context['motif'], $is_new);

        if ($type->is_variable()) {
            $is_new ? $this->create_variations($product, $type, $price, $sale) : $this->update_variations($product, $price, $sale, $sku_changed);
            \WC_Product_Variable::sync($product->get_id());
        }
        if ($sized && $product instanceof \WC_Product_Variable) {
            (new CardSizes())->apply($product, $context['format'] ?? 'quer', $with_a4, [
                'price'    => (string) $price,
                'stock'    => $stock,
                'price_a4' => $price_a4,
                'stock_a4' => $stock_a4,
                'sale'     => $sale,
                'sale_a4'  => $sale_a4,
            ], $sku_changed);
        }

        return wc_get_product($product->get_id());
    }

    /**
     * Zeitpunkt aus einem Datums- und einem Uhrzeitfeld in der Zeitzone des Shops. Fehlt die Uhrzeit, gilt `$default_time`.
     */
    public static function parse_local_datetime(string $date, string $time, string $default_time = '00:00'): ?int
    {
        $value = trim($date) . 'T' . (trim($time) !== '' ? trim($time) : $default_time);
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $value, wp_timezone());

        // Der Vergleich verwirft Werte, die PHP stillschweigend umrechnet, z. B. den 31.02.
        return $parsed !== false && $parsed->format('Y-m-d\TH:i') === $value ? $parsed->getTimestamp() : null;
    }

    /**
     * Angebotspreis aus dem Formular: '' für keinen, null wenn das Feld fehlt (gesperrt), sonst der Preis.
     *
     * @param array<string, mixed>  $data
     * @param array<string, string> $errors
     */
    private static function parse_sale(array $data, string $field, ?string $regular, array &$errors): ?string
    {
        if (!array_key_exists($field, $data)) {
            return null;
        }
        $raw = trim((string) $data[$field]);
        if ($raw === '') {
            return '';
        }
        $sale = self::parse_price($raw);
        if ($sale === null || (float) $sale <= 0) {
            $errors[$field] = __('Bitte gib den Angebotspreis wie einen Preis ein, z. B. 1,99, oder lass das Feld leer.', 'novemberkind-produkte');
        } elseif ($regular !== null && (float) $sale >= (float) $regular) {
            $errors[$field] = __('Der Angebotspreis muss niedriger sein als der normale Preis.', 'novemberkind-produkte');
        }

        return $sale;
    }

    /**
     * Angebotspreise für das Formular. Gesperrt, wenn ein Angebot einen Zeitraum hat oder je Variante verschieden ist;
     * das bleibt in der WooCommerce-Maske, damit das Formular nichts davon überschreibt.
     *
     * @return array{sale: string, sale_a4: string, sale_locked: bool}
     */
    public static function sale_state(\WC_Product $product): array
    {
        $items = $product instanceof \WC_Product_Variable
            ? array_filter(array_map('wc_get_product', $product->get_children()))
            : [$product];
        $a4    = CardSizes::has_sizes($product) ? CardSizes::variation($product, CardSizes::A4) : null;
        $main  = array_filter($items, static fn(\WC_Product $item): bool => $a4 === null || $item->get_id() !== $a4->get_id());

        $dated  = array_filter($items, static fn(\WC_Product $item): bool => $item->get_date_on_sale_from('edit') !== null || $item->get_date_on_sale_to('edit') !== null);
        $prices = array_unique(array_map(static fn(\WC_Product $item): string => (string) $item->get_sale_price('edit'), $main));

        return [
            'sale'        => count($prices) === 1 ? (string) reset($prices) : '',
            'sale_a4'     => $a4 ? (string) $a4->get_sale_price('edit') : '',
            'sale_locked' => $dated !== [] || count($prices) > 1,
        ];
    }

    /**
     * Wandelt einen Preis wie „24,90“, „1.234,50“ oder „24.90“ in „24.90“ um.
     */
    public static function parse_price(string $input): ?string
    {
        $value = preg_replace('/[\s€]/u', '', $input) ?? '';
        if (str_contains($value, ',')) {
            $value = str_replace(['.', ','], ['', '.'], $value);
        }
        if (!preg_match('/^\d+(\.\d{1,2})?$/', $value)) {
            return null;
        }

        return number_format((float) $value, self::PRICE_DECIMALS, '.', '');
    }

    /**
     * „Otter, Tier ,otter“ → ['Otter', 'Tier']
     *
     * @return string[]
     */
    public static function parse_tags(string $input): array
    {
        $tags = [];
        foreach (explode(',', wp_unslash($input)) as $tag) {
            $tag = trim(sanitize_text_field($tag));
            if ($tag !== '' && !isset($tags[mb_strtolower($tag)])) {
                $tags[mb_strtolower($tag)] = $tag;
            }
        }

        return array_values($tags);
    }

    /**
     * Schlagwörter eines Produkts ohne die, die aus der Vorlage kommen.
     *
     * @return string[]
     */
    public static function motif_tags(\WC_Product $product, ProductType $type): array
    {
        $fixed = array_map('mb_strtolower', $type->tags($type->context_from_product($product)));

        return array_values(array_filter(
            wp_list_pluck(wc_get_object_terms($product->get_id(), 'product_tag'), 'name'),
            static fn(string $tag): bool => !in_array(mb_strtolower($tag), $fixed, true)
        ));
    }

    /**
     * @param string[] $names
     * @return int[]
     */
    private function tag_ids(array $names): array
    {
        $ids = [];
        foreach ($names as $name) {
            $term = get_term_by('name', $name, 'product_tag');
            if (!$term instanceof \WP_Term) {
                $created = wp_insert_term($name, 'product_tag');
                $term    = is_wp_error($created) ? null : get_term($created['term_id'], 'product_tag');
            }
            if ($term instanceof \WP_Term) {
                $ids[] = $term->term_id;
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * Benennt neue Fotos nach der Artikelnummer: „A000009-1024.webp“, bei mehreren „A000009-1-1024.webp“ usw.
     *
     * @param int[] $image_ids eigene Fotos des Produkts in Anzeigereihenfolge
     */
    private function name_images(\WC_Product $product, array $image_ids): void
    {
        $sku = $product->get_sku();
        $ids = array_values(array_unique(array_filter($image_ids)));
        if ($sku === '' || $ids === []) {
            return;
        }

        $highest = 0;
        $new     = [];
        foreach ($ids as $id) {
            $name = wp_basename((string) get_attached_file($id));
            if (!str_starts_with($name, $sku . '-')) {
                // Nur eigene Uploads umbenennen, damit Links auf andere Medien gültig bleiben
                if (ImageProcessor::is_own_upload($id)) {
                    $new[] = $id;
                }
            } else {
                $highest = max($highest, preg_match('/^' . preg_quote($sku, '/') . '-(\d{1,2})-/', $name, $match) ? (int) $match[1] : 1);
            }
        }

        $processor = new ImageProcessor();
        foreach ($new as $id) {
            $base = count($ids) === 1 ? $sku : $sku . '-' . ++$highest;
            $processor->rename($id, $base);
            wp_update_post([
                'ID'          => $id,
                'post_parent' => $product->get_id(),
                'post_title'  => pathinfo((string) get_attached_file($id), PATHINFO_FILENAME),
            ]);
            if (get_post_meta($id, '_wp_attachment_image_alt', true) === '') {
                update_post_meta($id, '_wp_attachment_image_alt', $product->get_name());
            }
        }
    }

    private function save_germanized(\WC_Product $product, ProductType $type, string $motif, bool $is_new): void
    {
        if (!function_exists('wc_gzd_get_product')) {
            return;
        }

        $gzd = wc_gzd_get_product($product);
        $gzd->set_mini_desc($type->cart_description($motif));
        if ($is_new) {
            $slug = ShopData::delivery_time_slug($type->config('delivery_time'));
            if ($slug !== '') {
                $gzd->set_default_delivery_time_slug($slug);
            }
        }
        // Germanized speichert das Produkt nur mit, wenn sich die Lieferzeit ändert
        $gzd->save();
        $product->save();
    }

    private function create_variations(\WC_Product $product, ProductType $type, string $price, ?string $sale): void
    {
        $config    = $type->config('variations');
        $attribute = new \WC_Product_Attribute();
        $attribute->set_name($config['attribute']);
        $attribute->set_options(array_column($config['options'], 'name'));
        $attribute->set_visible(true);
        $attribute->set_variation(true);

        $key = sanitize_title($config['attribute']);
        $product->set_attributes([$attribute]);
        $product->set_default_attributes([$key => $config['default']]);
        $product->save();

        foreach ($config['options'] as $position => $option) {
            $variation = new \WC_Product_Variation();
            $variation->set_parent_id($product->get_id());
            $variation->set_attributes([$key => $option['name']]);
            $variation->set_regular_price($price);
            $variation->set_sale_price((string) $sale);
            $variation->set_sku($product->get_sku() . '-' . ($position + 1));
            $variation->set_menu_order($position + 1);
            $variation->set_tax_class('parent');
            $variation->save();

            if (!empty($option['safety_instructions']) && function_exists('wc_gzd_get_product')) {
                wc_gzd_get_product($variation)->set_safety_instructions($option['safety_instructions']);
                $variation->save();
            }
        }
    }

    /**
     * Preis und Angebotspreis für alle Varianten; bei neuer Artikelnummer auch deren Nummern (A000123-1, -2, …).
     */
    private function update_variations(\WC_Product $product, string $price, ?string $sale, bool $sku_changed): void
    {
        foreach ($product->get_children() as $position => $child_id) {
            $variation = wc_get_product($child_id);
            if (!$variation) {
                continue;
            }
            $variation->set_regular_price($price);
            if ($sale !== null) {
                $variation->set_sale_price($sale);
            }
            if ($sku_changed) {
                $variation->set_sku($product->get_sku() . '-' . ($position + 1));
            }
            $variation->save();
        }
    }

    /**
     * Ob ein Foto eines der Variantenfotos ist (z. B. „Saugnapf-gross.webp“). Erkannt am Dateinamen,
     * auch mit der Endung „-1“, die WordPress bei doppelt hochgeladenen Dateien anhängt.
     */
    public function is_variation_image(ProductType $type, int $attachment_id): bool
    {
        $name = pathinfo((string) get_attached_file($attachment_id), PATHINFO_FILENAME);
        foreach ($type->config('variations')['options'] ?? [] as $option) {
            if (!empty($option['image']) && preg_match('/^' . preg_quote($option['image'], '/') . '(-\d+)?$/', $name)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Variantenfotos eines bestehenden Produkts in seiner Reihenfolge. Hat es keine, die aus der Mediathek.
     *
     * @return int[]
     */
    public function variation_images_of(ProductType $type, \WC_Product $product): array
    {
        $existing = array_values(array_filter(
            array_map('intval', $product->get_gallery_image_ids()),
            fn(int $id): bool => $this->is_variation_image($type, $id)
        ));

        return $existing !== [] ? $existing : $this->variation_image_ids($type);
    }

    /**
     * Fotos der Varianten (z. B. Rückseiten), die bei jedem Produkt dieser Art in der Galerie stehen.
     *
     * @return int[]
     */
    public function variation_image_ids(ProductType $type): array
    {
        $ids = array_map(
            static fn(array $option): int => ShopData::attachment_id($option['image'] ?? ''),
            $type->config('variations')['options'] ?? []
        );

        return array_values(array_filter($ids));
    }

    /**
     * Fotos, die ein Produkt verwenden darf: über das Plugin hochgeladen oder schon am Produkt.
     * Andere Medien wie Logo oder Blogbilder bleiben so unberührt.
     */
    public static function usable_image(int $id, ?\WC_Product $product): bool
    {
        if (!$id || !wp_attachment_is_image($id) || !current_user_can('edit_post', $id)) {
            return false;
        }

        return ImageProcessor::is_own_upload($id)
            || ($product && in_array($id, [(int) $product->get_image_id(), ...array_map('intval', $product->get_gallery_image_ids())], true));
    }
}
