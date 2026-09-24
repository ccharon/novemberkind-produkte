<?php

declare(strict_types=1);

namespace NovemberkindProdukte;

use Anthropic\Client;
use Anthropic\Messages\Base64ImageSource;
use Anthropic\Messages\ImageBlockParam;
use Anthropic\Messages\TextBlockParam;
use Anthropic\RequestOptions;
use Http\Discovery\ClassDiscovery;
use Nyholm\Psr7\Factory\Psr17Factory;

defined('ABSPATH') || exit;

/**
 * Vorschläge für Titel, Beschreibung und Schlagwörter über die Claude API.
 */
final class Suggestions
{
    public const DEFAULT_MODEL = 'claude-sonnet-5';

    public const SCHEMA = [
        'type'                 => 'object',
        'properties'           => [
            'mode'        => ['type' => 'string', 'enum' => ['neu', 'verbessert']],
            'title'       => ['type' => 'string'],
            'description' => ['type' => 'string'],
            'tags'        => ['type' => 'array', 'items' => ['type' => 'string']],
        ],
        'required'             => ['mode', 'title', 'description', 'tags'],
        'additionalProperties' => false,
    ];

    /**
     * Schlüssel aus der Konstante NOVEMBERKIND_PRODUKTE_ANTHROPIC_KEY (wp-config.php) oder der gleichnamigen Umgebungsvariable.
     */
    public static function api_key(): string
    {
        $key = defined('NOVEMBERKIND_PRODUKTE_ANTHROPIC_KEY') ? (string) constant('NOVEMBERKIND_PRODUKTE_ANTHROPIC_KEY') : '';

        return $key !== '' ? $key : (string) getenv('NOVEMBERKIND_PRODUKTE_ANTHROPIC_KEY');
    }

    public static function model(): string
    {
        return defined('NOVEMBERKIND_PRODUKTE_ANTHROPIC_MODEL') ? (string) constant('NOVEMBERKIND_PRODUKTE_ANTHROPIC_MODEL') : self::DEFAULT_MODEL;
    }

    public static function is_available(): bool
    {
        return self::api_key() !== '' && class_exists(Client::class);
    }

    /**
     * @param array<string, mixed> $input Formularwerte
     * @return array{mode: string, title: string, description: string, tags: string[]}|\WP_Error
     */
    public function suggest(ProductType $type, array $input): array|\WP_Error
    {
        [$context] = $type->parse($input);
        $current   = wp_kses_post(wp_unslash((string) ($input['description'] ?? '')));
        $tags      = ProductService::parse_tags((string) ($input['tags'] ?? ''));
        $image_id  = absint($input['image_id'] ?? 0);
        $product   = wc_get_product(absint($input['product_id'] ?? 0)) ?: null;
        if (!ProductService::usable_image($image_id, $product)) {
            $image_id = 0;
        }

        $message = $this->user_message($type, $context, $current, $tags, $image_id);

        // Tests und andere Plugins können hier eine Antwort liefern, ohne die API aufzurufen
        $raw = apply_filters('novemberkind_produkte_pre_suggestion', null, $message, $type);
        if ($raw === null) {
            $raw = $this->call_api($message);
            if (is_wp_error($raw)) {
                return $raw;
            }
        }

        return $this->clean(is_array($raw) ? $raw : [], $type, $context);
    }

    /**
     * Anfrage als Text und optional Foto. Die Angaben stehen in Abschnitten, damit Claude sie klar trennt.
     *
     * @param array<string, string> $context
     * @param string[]              $tags
     * @return array{text: string, image: string|null, mime: string}
     */
    public function user_message(ProductType $type, array $context, string $current, array $tags, int $image_id): array
    {
        $details = [];
        foreach (['format' => 'Format', 'finish' => 'Oberfläche', 'technique' => 'Technik', 'year' => 'Entstanden'] as $key => $label) {
            if (!empty($context[$key])) {
                $details[] = "{$label}: " . ($key === 'finish' ? ($context[$key] === 'glaenzend' ? 'glänzend' : 'matt') : $context[$key]);
            }
        }
        $dimensions = $type->dimensions($context);
        if (!empty($dimensions['width']) && !empty($dimensions['height'])) {
            $details[] = sprintf('Maße: %s × %s cm', ProductType::format_number($dimensions['width']), ProductType::format_number($dimensions['height']));
        }

        $sections = [
            'produktart'          => $type->label() . ' (' . $type->hint() . ')',
            'titel'               => $context['motif'] !== '' ? $context['motif'] : '(noch keiner, bitte aus dem Foto ableiten)',
            'angaben'             => $details !== [] ? implode("\n", $details) : '(keine)',
            'eigener_text'        => $context['text'] ?? '',
            'feste_schlagwoerter' => implode(', ', $type->tags($context)) ?: '(keine)',
            'bisherige_schlagwoerter' => $tags !== [] ? implode(', ', $tags) : '(keine)',
            'bisherige_beschreibung'  => trim(wp_strip_all_tags($current)) !== '' ? $current : '(leer)',
            'vorlage'             => $type->description($context),
        ];

        $text = '';
        foreach ($sections as $name => $value) {
            if ($value !== '') {
                $text .= "<{$name}>\n{$value}\n</{$name}>\n\n";
            }
        }
        $text .= 'Erstelle Titel, Beschreibung und Schlagwörter nach den Regeln.';

        $file  = $image_id && wp_attachment_is_image($image_id) ? get_attached_file($image_id) : false;
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Foto aus der Mediathek
        $image = $file && is_readable($file) && filesize($file) < 5 * MB_IN_BYTES ? (string) file_get_contents($file) : null;

        return ['text' => $text, 'image' => $image, 'mime' => $image !== null ? (string) get_post_mime_type($image_id) : ''];
    }

    public function system_prompt(): string
    {
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- lokale Datei des Plugins
        return strtr((string) file_get_contents(__DIR__ . '/prompts/suggestion.md'), ['{shop}' => get_bloginfo('name')]);
    }

    /**
     * @param array{text: string, image: string|null, mime: string} $message
     * @return array<string, mixed>|\WP_Error
     */
    private function call_api(array $message): array|\WP_Error
    {
        if (!self::is_available()) {
            return new \WP_Error('unavailable', __('Für Vorschläge fehlt der API-Schlüssel.', 'novemberkind-produkte'));
        }

        static $registered = false;
        if (!$registered) {
            ClassDiscovery::prependStrategy(WpHttpDiscoveryStrategy::class);
            $registered = true;
        }

        $content = [];
        if ($message['image'] !== null && in_array($message['mime'], ['image/webp', 'image/jpeg', 'image/png', 'image/gif'], true)) {
            // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- die API erwartet das Foto base64-kodiert
            $content[] = ImageBlockParam::with(source: Base64ImageSource::with(data: base64_encode($message['image']), mediaType: $message['mime']));
        }
        $content[] = TextBlockParam::with(text: $message['text']);

        $factory = new Psr17Factory();
        try {
            $client = new Client(
                apiKey: self::api_key(),
                requestOptions: RequestOptions::with(
                    timeout: WpHttpClient::TIMEOUT,
                    maxRetries: 1,
                    transporter: new WpHttpClient(),
                    uriFactory: $factory,
                    streamFactory: $factory,
                    requestFactory: $factory,
                ),
            );
            $response = $client->messages->create(
                model: self::model(),
                maxTokens: 16000,
                system: $this->system_prompt(),
                messages: [['role' => 'user', 'content' => $content]],
                outputConfig: [
                    'effort' => 'medium',
                    'format' => ['type' => 'json_schema', 'schema' => self::SCHEMA],
                ],
            );
        } catch (\Throwable $error) {
            // Details nur ins Log, die Nutzerin bekommt eine verständliche Meldung
            error_log('novemberkind-produkte: Claude API: ' . $error->getMessage()); // phpcs:ignore WordPress.PHP.DevelopmentFunctions
            return new \WP_Error('api', __('Claude ist gerade nicht erreichbar. Bitte versuch es gleich noch einmal.', 'novemberkind-produkte'));
        }

        if ($response->stopReason === 'refusal') {
            return new \WP_Error('refusal', __('Claude hat für dieses Produkt keinen Vorschlag erstellt.', 'novemberkind-produkte'));
        }
        if ($response->stopReason === 'max_tokens') {
            return new \WP_Error('max_tokens', __('Der Vorschlag wurde zu lang und ist unvollständig. Bitte versuch es noch einmal.', 'novemberkind-produkte'));
        }

        foreach ($response->content as $block) {
            if ($block->type === 'text') {
                $data = json_decode($block->text, true);
                if (is_array($data)) {
                    return $data;
                }
            }
        }

        return new \WP_Error('format', __('Die Antwort von Claude war unvollständig. Bitte versuch es noch einmal.', 'novemberkind-produkte'));
    }

    /**
     * Prüft und bereinigt die Antwort, bevor sie ins Formular kommt.
     *
     * @param array<string, mixed>  $data
     * @param array<string, string> $context
     * @return array{mode: string, title: string, description: string, tags: string[]}|\WP_Error
     */
    private function clean(array $data, ProductType $type, array $context): array|\WP_Error
    {
        $title = $type->motif_from_name(sanitize_text_field((string) ($data['title'] ?? '')));
        $html  = wp_kses_post((string) ($data['description'] ?? ''));
        if ($title === '' || trim(wp_strip_all_tags($html)) === '') {
            return new \WP_Error('format', __('Die Antwort von Claude war unvollständig. Bitte versuch es noch einmal.', 'novemberkind-produkte'));
        }

        $fixed = array_map('mb_strtolower', $type->tags($context));
        $tags  = array_values(array_filter(
            ProductService::parse_tags(mb_strtolower(implode(',', array_map('strval', (array) ($data['tags'] ?? []))))),
            static fn(string $tag): bool => !in_array(mb_strtolower($tag), $fixed, true)
        ));

        return [
            'mode'        => ($data['mode'] ?? '') === 'neu' ? 'neu' : 'verbessert',
            'title'       => $title,
            'description' => $html,
            'tags'        => $tags,
        ];
    }
}
