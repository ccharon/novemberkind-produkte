<?php

declare(strict_types=1);

namespace NovemberkindProdukte;

defined('ABSPATH') || exit;

/**
 * Neuigkeiten auf der Startseite: WordPress-Beiträge der Kategorie „Neuigkeiten“, die das Blog-Modul von Divi zeigt.
 * Andere Beiträge bleiben unberührt.
 *
 * @phpstan-type Entry array{id: int, title: string, blocks: string, image_id: int, status: string, date: int, modified: int, url: string}
 */
final class News
{
    public const CATEGORY = 'Neuigkeiten';
    public const CAPABILITY = 'edit_posts';
    public const STATUSES = ['draft', 'future', 'publish'];
    public const TITLE_MAX_LENGTH = 200;
    private const LISTED_STATUSES = ['publish', 'future', 'draft', 'pending', 'private'];

    /**
     * ID der Kategorie, mit `$create` legt sie fehlende an. 0, wenn es sie nicht gibt.
     */
    public static function category_id(bool $create = false): int
    {
        $term = get_term_by('name', self::CATEGORY, 'category');
        if ($term instanceof \WP_Term) {
            return $term->term_id;
        }
        if (!$create) {
            return 0;
        }
        $created = wp_insert_term(self::CATEGORY, 'category');

        return is_wp_error($created) ? 0 : (int) $created['term_id'];
    }

    /**
     * Alle Neuigkeiten außer im Papierkorb, neueste zuerst.
     *
     * @return array<int, array<string, mixed>>
     * @phpstan-return array<int, Entry>
     */
    public static function all(): array
    {
        $category = self::category_id();
        if ($category === 0) {
            return [];
        }
        $posts = get_posts([
            'post_type'   => 'post',
            'post_status' => self::LISTED_STATUSES,
            'category'    => $category,
            'numberposts' => -1,
            'orderby'     => 'date',
            'order'       => 'DESC',
        ]);

        return array_map([self::class, 'from_post'], array_filter($posts, static fn(mixed $post): bool => $post instanceof \WP_Post));
    }

    /**
     * Eine Neuigkeit oder null, wenn es den Beitrag nicht gibt oder er nicht in der Kategorie steht.
     *
     * @return array<string, mixed>|null
     * @phpstan-return Entry|null
     */
    public static function get(int $id): ?array
    {
        $post     = $id > 0 ? get_post($id) : null;
        $category = self::category_id();
        if (!$post instanceof \WP_Post || $post->post_type !== 'post' || !in_array($post->post_status, self::LISTED_STATUSES, true)) {
            return null;
        }

        return $category !== 0 && has_category($category, $post) ? self::from_post($post) : null;
    }

    /**
     * Status als Plakette: Online, Geplant oder Entwurf.
     *
     * @return array{badge: string, label: string}
     */
    public static function badge(string $status): array
    {
        return match ($status) {
            'publish' => ['badge' => 'online', 'label' => __('Online', 'novemberkind-produkte')],
            'future'  => ['badge' => 'future', 'label' => __('Geplant', 'novemberkind-produkte')],
            default   => ['badge' => 'draft', 'label' => __('Entwurf', 'novemberkind-produkte')],
        };
    }

    /**
     * Prüft die Eingaben aus dem Formular.
     *
     * @param array<string, mixed> $data
     * @return array{title: string, blocks: string, image_id: int, status: string, publish_at: ?int}|\WP_Error
     */
    public function parse(array $data, ?\WP_Post $existing = null): array|\WP_Error
    {
        $errors = [];
        $title  = Input::text($data, 'title');
        if ($title === '') {
            $errors['title'] = __('Bitte gib einen Titel ein.', 'novemberkind-produkte');
        } elseif (mb_strlen($title) > self::TITLE_MAX_LENGTH) {
            /* translators: %d: größte Anzahl Zeichen */
            $errors['title'] = sprintf(__('Der Titel darf höchstens %d Zeichen lang sein.', 'novemberkind-produkte'), self::TITLE_MAX_LENGTH);
        }

        // Browser senden Zeilenumbrüche in Formularen als CRLF
        $blocks = str_replace("\r\n", "\n", wp_unslash(Input::value($data, 'blocks')));
        if (strlen($blocks) > NewsletterHtml::MAX_BYTES) {
            $errors['content'] = __('Der Text ist zu lang. Bitte kürze ihn oder teile ihn auf zwei Neuigkeiten auf.', 'novemberkind-produkte');
            $blocks = '';
        }
        $blocks = NewsletterHtml::source($blocks);
        if (!isset($errors['content']) && trim(wp_strip_all_tags($blocks)) === '' && !str_contains($blocks, '<img')) {
            $errors['content'] = __('Bitte schreib einen Text oder füge ein Foto ein.', 'novemberkind-produkte');
        }

        $image_id = Input::id($data, 'image_id');
        $image_id = self::usable_image($image_id, $existing) ? $image_id : 0;

        $status     = Input::choice($data, 'status', self::STATUSES, 'draft');
        $publish_at = null;
        if ($status === 'future') {
            $publish_at = Input::datetime($data, 'publish');
            if ($publish_at === null) {
                $errors['publish'] = __('Bitte wähle das Datum für die Veröffentlichung.', 'novemberkind-produkte');
            } elseif ($publish_at <= time()) {
                $errors['publish'] = __('Der Zeitpunkt liegt in der Vergangenheit. Wähle einen späteren oder stelle die Neuigkeit direkt online.', 'novemberkind-produkte');
            }
        }

        if ($errors !== []) {
            return Input::invalid($errors);
        }

        return ['title' => $title, 'blocks' => $blocks, 'image_id' => $image_id, 'status' => $status, 'publish_at' => $publish_at];
    }

    /**
     * Legt eine Neuigkeit an oder ändert sie. Neue Beiträge kommen in die Kategorie, die übrigen Kategorien bleiben.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>|\WP_Error
     * @phpstan-return Entry|\WP_Error
     */
    public function save(array $data, int $id = 0): array|\WP_Error
    {
        $existing = $id > 0 ? get_post($id) : null;
        if ($id > 0 && self::get($id) === null) {
            return new \WP_Error('not_found', __('Diese Neuigkeit gibt es nicht mehr.', 'novemberkind-produkte'));
        }
        $values = $this->parse($data, $existing instanceof \WP_Post ? $existing : null);
        if (is_wp_error($values)) {
            return $values;
        }

        $post = [
            'post_type'    => 'post',
            'post_title'   => $values['title'],
            'post_content' => $values['blocks'],
            'post_status'  => $values['status'],
        ];
        if ($existing instanceof \WP_Post) {
            $post['ID'] = $existing->ID;
        } else {
            $category = self::category_id(true);
            if ($category === 0) {
                return new \WP_Error('category', __('Die Kategorie „Neuigkeiten“ konnte nicht angelegt werden.', 'novemberkind-produkte'));
            }
            $post['post_category'] = [$category];
        }
        $date = $values['publish_at'];
        if ($date === null && $existing instanceof \WP_Post && (int) get_post_timestamp($existing) > time()) {
            // Ein Datum in der Zukunft würde WordPress beim Veröffentlichen erneut planen, deshalb zurück auf jetzt
            $date = time();
        }
        if ($date !== null) {
            $post += ['post_date' => Time::format('Y-m-d H:i:s', $date), 'post_date_gmt' => gmdate('Y-m-d H:i:s', $date), 'edit_date' => true];
        }

        // wp_insert_post erwartet Werte mit Slashes wie aus einem Formular
        $post_id = wp_insert_post(wp_slash($post), true);
        if (is_wp_error($post_id)) {
            return $post_id;
        }

        if ($values['image_id'] !== 0) {
            set_post_thumbnail($post_id, $values['image_id']);
            if (ImageProcessor::is_own_upload($values['image_id']) && get_post_meta($values['image_id'], '_wp_attachment_image_alt', true) === '') {
                update_post_meta($values['image_id'], '_wp_attachment_image_alt', $values['title']);
            }
        } else {
            delete_post_thumbnail($post_id);
        }

        return self::get($post_id) ?? new \WP_Error('save', __('Die Neuigkeit konnte nicht gespeichert werden.', 'novemberkind-produkte'));
    }

    /**
     * Legt eine Neuigkeit in den Papierkorb, aus dem sie sich in WordPress wiederherstellen lässt.
     */
    public function delete(int $id): bool|\WP_Error
    {
        if (self::get($id) === null) {
            return new \WP_Error('not_found', __('Diese Neuigkeit gibt es nicht mehr.', 'novemberkind-produkte'));
        }

        return wp_trash_post($id) ? true : new \WP_Error('delete', __('Die Neuigkeit konnte nicht gelöscht werden.', 'novemberkind-produkte'));
    }

    /**
     * Beitragsbild nur aus eigenen Uploads oder das, das der Beitrag schon hat, damit Logo und andere Medien unberührt bleiben.
     */
    private static function usable_image(int $id, ?\WP_Post $post): bool
    {
        if (!$id || !wp_attachment_is_image($id) || !current_user_can('edit_post', $id)) {
            return false;
        }

        return ImageProcessor::is_own_upload($id) || ($post && (int) get_post_thumbnail_id($post) === $id);
    }

    /**
     * @phpstan-return Entry
     * @return array<string, mixed>
     */
    private static function from_post(\WP_Post $post): array
    {
        return [
            'id'       => $post->ID,
            // WordPress speichert den Titel mit Entitäten wie &amp;, das Formular zeigt ihn als Text
            'title'    => wp_specialchars_decode($post->post_title, ENT_QUOTES),
            'blocks'   => $post->post_content,
            'image_id' => (int) get_post_thumbnail_id($post),
            'status'   => $post->post_status,
            'date'     => (int) get_post_timestamp($post),
            'modified' => (int) get_post_timestamp($post, 'modified'),
            'url'      => (string) get_permalink($post),
        ];
    }
}
