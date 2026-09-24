<?php

declare(strict_types=1);

namespace NovemberkindProdukte;

defined('ABSPATH') || exit;

/**
 * Eine Produktart mit ihrer Vorlage aus includes/product-types.php.
 * Der „Kontext“ sind die Eingaben, aus denen Name und Beschreibung entstehen.
 */
final class ProductType
{
    public const META_TYPE = '_novemberkind_produkte_type';
    public const META_CONTEXT = '_novemberkind_produkte_context';
    public const META_CUSTOM_DESCRIPTION = '_novemberkind_produkte_custom_description';

    public const TECHNIQUES = ['Aquarell', 'Tusche', 'Bleistift', 'Buntstift', 'Mischtechnik'];

    /** @var array<string, self>|null */
    private static ?array $types = null;

    /**
     * @param array<string, mixed> $config
     */
    private function __construct(private string $key, private array $config)
    {
    }

    /**
     * @return array<string, self>
     */
    public static function all(): array
    {
        if (self::$types === null) {
            $config = (array) apply_filters('novemberkind_produkte_types', require __DIR__ . '/product-types.php');
            self::$types = [];
            foreach ($config as $key => $type) {
                self::$types[$key] = new self($key, $type);
            }
        }

        return self::$types;
    }

    public static function get(string $key): ?self
    {
        return self::all()[$key] ?? null;
    }

    /**
     * Erkennt die Produktart über die gespeicherte Art, bei älteren Produkten über Kategorie,
     * Produkttyp und Namensmuster. Das Namensmuster hält Sets wie „Sticker Set: …“ fern.
     */
    public static function detect(\WC_Product $product): ?self
    {
        $stored = self::get((string) $product->get_meta(self::META_TYPE));
        if ($stored) {
            return $stored;
        }

        $category_names = wp_list_pluck(wc_get_object_terms($product->get_id(), 'product_cat'), 'name');
        foreach (self::all() as $type) {
            $category = $type->config['category'];
            $prefix   = explode('%s', $type->config['name'], 2)[0];
            if (
                $product->get_type() === $type->config['product_type']
                && in_array(end($category), $category_names, true)
                && ($prefix === '' || str_starts_with($product->get_name(), $prefix))
            ) {
                return $type;
            }
        }

        return null;
    }

    public function key(): string
    {
        return $this->key;
    }

    public function label(): string
    {
        return $this->config['label'];
    }

    public function hint(): string
    {
        return $this->config['hint'];
    }

    public function is_variable(): bool
    {
        return $this->config['product_type'] === 'variable';
    }

    public function is_unique(): bool
    {
        return !empty($this->config['unique']);
    }

    /**
     * @return string[]
     */
    public function fields(): array
    {
        return $this->config['fields'];
    }

    public function has_field(string $field): bool
    {
        return in_array($field, $this->config['fields'], true);
    }

    /**
     * @return mixed
     */
    public function config(string $key)
    {
        return $this->config[$key] ?? null;
    }

    public function product_name(string $motif): string
    {
        return sprintf($this->config['name'], $motif);
    }

    public function short_description(string $motif): string
    {
        return sprintf($this->config['short_description'], $motif);
    }

    public function cart_description(string $motif): string
    {
        return '<p>' . esc_html(sprintf($this->config['cart_description'], $motif)) . '</p>';
    }

    public function motif_from_name(string $name): string
    {
        [$prefix, $suffix] = explode('%s', $this->config['name'], 2) + ['', ''];
        if ($prefix !== '' && str_starts_with($name, $prefix)) {
            $name = substr($name, strlen($prefix));
        }
        if ($suffix !== '' && str_ends_with($name, $suffix)) {
            $name = substr($name, 0, -strlen($suffix));
        }

        return trim($name);
    }

    /**
     * Prüft die Formulareingaben.
     *
     * @param array<string, mixed> $input
     * @return array{0: array<string, string>, 1: array<string, string>} Kontext und Fehler je Feld
     */
    public function parse(array $input): array
    {
        $value   = static fn(string $key): string => trim(sanitize_text_field(wp_unslash((string) ($input[$key] ?? ''))));
        $context = ['motif' => $value('motif')];
        $errors  = [];

        if ($context['motif'] === '') {
            $errors['motif'] = $this->is_unique()
                ? __('Bitte gib dem Bild einen Titel.', 'novemberkind-produkte')
                : __('Bitte gib den Namen des Motivs ein.', 'novemberkind-produkte');
        }

        foreach ($this->fields() as $field) {
            switch ($field) {
                case 'format':
                    $context['format'] = $value('format');
                    if (!in_array($context['format'], ['quer', 'hoch'], true)) {
                        $errors['format'] = __('Bitte wähle Hoch- oder Querformat.', 'novemberkind-produkte');
                    }
                    break;

                case 'finish':
                    $context['finish'] = $value('finish');
                    if (!in_array($context['finish'], ['matt', 'glaenzend'], true)) {
                        $errors['finish'] = __('Bitte wähle matt oder glänzend.', 'novemberkind-produkte');
                    }
                    break;

                case 'size':
                    foreach (['width' => __('Breite', 'novemberkind-produkte'), 'height' => __('Höhe', 'novemberkind-produkte')] as $key => $label) {
                        $number = self::parse_number($value($key));
                        if ($number === null) {
                            /* translators: %s: Breite oder Höhe */
                            $errors[$key] = sprintf(__('Bitte gib die %s in cm ein, z. B. 7,5.', 'novemberkind-produkte'), $label);
                        }
                        $context[$key] = (string) $number;
                    }
                    break;

                case 'bookmark_width':
                    $context['width'] = $value('width');
                    if (!in_array($context['width'], ['5', '7'], true)) {
                        $errors['width'] = __('Bitte wähle die Breite.', 'novemberkind-produkte');
                    }
                    break;

                case 'technique':
                    $context['technique'] = $value('technique');
                    if (!in_array($context['technique'], self::TECHNIQUES, true)) {
                        $errors['technique'] = __('Bitte wähle die Technik.', 'novemberkind-produkte');
                    }
                    break;

                case 'year':
                    $context['year'] = $value('year');
                    if (!preg_match('/^(19|20)\d\d$/', $context['year']) || (int) $context['year'] > (int) gmdate('Y')) {
                        $errors['year'] = __('Bitte gib das Jahr vierstellig ein, z. B. 2026.', 'novemberkind-produkte');
                    }
                    break;

                case 'text':
                    $context['text'] = trim(sanitize_textarea_field(wp_unslash((string) ($input['text'] ?? ''))));
                    if ($context['text'] === '') {
                        $errors['text'] = __('Bitte beschreibe das Bild mit ein paar Sätzen.', 'novemberkind-produkte');
                    }
                    break;
            }
        }

        return [$context, $errors];
    }

    /**
     * Liest den Kontext eines bestehenden Produkts zurück, damit das Formular ihn anzeigen kann.
     *
     * @return array<string, string>
     */
    public function context_from_product(\WC_Product $product): array
    {
        $stored = $product->get_meta(self::META_CONTEXT);
        if (is_array($stored) && isset($stored['motif'])) {
            return array_map('strval', $stored);
        }

        $context = ['motif' => $this->motif_from_name($product->get_name())];
        $tags    = array_map('mb_strtolower', wp_list_pluck(wc_get_object_terms($product->get_id(), 'product_tag'), 'name'));

        foreach ($this->fields() as $field) {
            switch ($field) {
                case 'format':
                    $context['format'] = (float) $product->get_height() > (float) $product->get_width() ? 'hoch' : 'quer';
                    break;
                case 'finish':
                    $context['finish'] = in_array('glänzend', $tags, true) ? 'glaenzend' : (in_array('matt', $tags, true) ? 'matt' : '');
                    break;
                case 'size':
                    $context['width']  = self::from_shop_unit($product->get_width());
                    $context['height'] = self::from_shop_unit($product->get_height());
                    break;
                case 'bookmark_width':
                    $context['width'] = self::from_shop_unit($product->get_width());
                    break;
                default:
                    $context[$field] = '';
            }
        }

        return $context;
    }

    /**
     * @param array<string, string> $context
     */
    public function description(array $context): string
    {
        $dimensions = $this->dimensions($context);
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- lokale Datei des Plugins
        $footer     = (string) file_get_contents(__DIR__ . '/descriptions/_footer.html');
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- lokale Datei des Plugins
        $template   = (string) file_get_contents(__DIR__ . "/descriptions/{$this->key}.html");
        $finish     = $context['finish'] ?? '';

        $replacements = [
            '{nachhaltigkeit}'   => $this->config['plastic_free_note']
                ? ' Nachhaltigkeit liegt mir sehr am Herzen, deshalb werden alle Produkte bei mir so gut es geht plastikfrei versendet.'
                : '',
            '{motiv}'            => esc_html($context['motif']),
            '{breite}'           => self::format_number($dimensions['width'] ?? ''),
            '{hoehe}'            => self::format_number($dimensions['height'] ?? ''),
            '{oberflaeche_satz}' => $finish === 'glaenzend' ? 'Glänzender' : 'Matter',
            '{material}'         => $finish === 'glaenzend' ? 'Vinyl irisierend (glänzend), wasserabweisend' : 'Vinyl, weiß, matt, wasserabweisend',
            '{technik}'          => esc_html($context['technique'] ?? ''),
            '{jahr}'             => esc_html($context['year'] ?? ''),
            '{beschreibung}'     => wpautop(esc_html($context['text'] ?? '')),
        ];

        return trim(strtr(strtr($template, ['{footer}' => $footer]), $replacements));
    }

    /**
     * Ob die Beschreibung von Hand angepasst wurde: im Formular gespeichert oder, bei älteren
     * Produkten, abweichend vom Text, den die Vorlage heute erzeugen würde.
     */
    public function has_custom_description(\WC_Product $product): bool
    {
        if ($product->get_meta(self::META_CUSTOM_DESCRIPTION) === 'yes') {
            return true;
        }
        $normalize = static fn(string $html): string => (string) preg_replace('/\s+/', ' ', trim($html));

        return $normalize($product->get_description()) !== $normalize($this->description($this->context_from_product($product)));
    }

    /**
     * @param array<string, string> $context
     * @return array{length?: string, width?: string, height?: string}
     */
    public function dimensions(array $context): array
    {
        $dimensions = $this->config['dimensions'];

        if (isset($context['format'])) {
            [$dimensions['width'], $dimensions['height']] = $context['format'] === 'hoch' ? ['10.5', '15'] : ['15', '10.5'];
        }
        if (isset($context['width']) && $context['width'] !== '') {
            $dimensions['width'] = $context['width'];
        }
        if (isset($context['height']) && $context['height'] !== '') {
            $dimensions['height'] = $context['height'];
        }

        return $dimensions;
    }

    /**
     * Feste Schlagwörter der Produktart plus die aus Oberfläche oder Technik.
     *
     * @param array<string, string> $context
     * @return string[]
     */
    public function tags(array $context): array
    {
        $tags = $this->config['tags'];
        if (!empty($context['finish'])) {
            $tags[] = $context['finish'] === 'glaenzend' ? 'glänzend' : 'matt';
        }
        if (!empty($context['technique'])) {
            $tags[] = $context['technique'];
        }

        return $tags;
    }

    /**
     * „7,5“ oder „7.5“ → 7.5
     */
    public static function parse_number(string $input): ?float
    {
        $value = str_replace(',', '.', trim($input));
        if (!preg_match('/^\d+(\.\d+)?$/', $value) || (float) $value <= 0) {
            return null;
        }

        return (float) $value;
    }

    /**
     * Maß in cm → Wert in der Maßeinheit des Shops (WooCommerce-Einstellung).
     */
    public static function to_shop_unit(string $cm): string
    {
        return $cm === '' ? '' : wc_format_decimal(wc_get_dimension((float) $cm, (string) get_option('woocommerce_dimension_unit'), 'cm'), 4, true);
    }

    /**
     * Maß in der Maßeinheit des Shops → Wert in cm.
     */
    public static function from_shop_unit(string $value): string
    {
        return $value === '' ? '' : wc_format_decimal(wc_get_dimension((float) $value, 'cm'), 2, true);
    }

    public static function format_number(string $value): string
    {
        return $value === '' ? '' : str_replace('.', ',', (string) (float) $value);
    }
}
