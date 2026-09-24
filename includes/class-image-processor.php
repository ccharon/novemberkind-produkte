<?php

declare(strict_types=1);

namespace EasyProduct;

defined('ABSPATH') || exit;

/**
 * Wandelt hochgeladene Fotos in WebP um und legt sie in der Mediathek ab.
 * Der Browser hat sie schon verkleinert, hier wird nur einmal verlustbehaftet komprimiert.
 */
final class ImageProcessor
{
    public const MAX_WIDTH = 1024;
    public const QUALITY = 85;
    public const MAX_SOURCE_SIDE = 8000;
    public const META_UPLOAD = '_easy_product_upload';

    /**
     * Ob ein Foto über dieses Plugin hochgeladen wurde. Nur solche Fotos darf das Plugin umbenennen.
     */
    public static function is_own_upload(int $attachment_id): bool
    {
        return get_post_meta($attachment_id, self::META_UPLOAD, true) === '1';
    }

    /**
     * @param array<string, mixed> $file Eintrag aus `$_FILES`
     * @return int|\WP_Error Attachment-ID
     */
    public function handle_upload(array $file): int|\WP_Error
    {
        require_once ABSPATH . 'wp-admin/includes/file.php';

        $upload = wp_handle_upload($file, [
            'test_form' => false,
            'mimes'     => ['png' => 'image/png', 'jpg|jpeg' => 'image/jpeg'],
        ]);
        if (isset($upload['error'])) {
            return new \WP_Error('upload', (string) $upload['error']);
        }

        $title = sanitize_text_field(pathinfo((string) ($file['name'] ?? ''), PATHINFO_FILENAME));

        return $this->import($upload['file'], $title);
    }

    /**
     * Wandelt eine Bilddatei in WebP um, löscht das Original und legt das Attachment an.
     *
     * @return int|\WP_Error Attachment-ID
     */
    public function import(string $source, string $title = ''): int|\WP_Error
    {
        require_once ABSPATH . 'wp-admin/includes/image.php';

        // Sehr große Pixelmaße würden beim Umwandeln den Speicher füllen
        $size = wp_getimagesize($source);
        if (!$size || max($size[0], $size[1]) > self::MAX_SOURCE_SIDE) {
            wp_delete_file($source);
            return new \WP_Error('dimensions', __('Das Foto ist zu groß oder beschädigt.', 'easy-product'));
        }

        $webp = $this->convert($source);
        wp_delete_file($source);
        if (is_wp_error($webp)) {
            return $webp;
        }

        $attachment_id = wp_insert_attachment([
            'post_mime_type' => 'image/webp',
            'post_title'     => $title !== '' ? $title : pathinfo($webp, PATHINFO_FILENAME),
            'post_status'    => 'inherit',
        ], $webp, 0, true);
        if (is_wp_error($attachment_id)) {
            wp_delete_file($webp);
            return $attachment_id;
        }

        wp_update_attachment_metadata($attachment_id, wp_generate_attachment_metadata($attachment_id, $webp));
        update_post_meta($attachment_id, self::META_UPLOAD, '1');

        return $attachment_id;
    }

    /**
     * Benennt die Datei eines Fotos um, z. B. in „A000009-1-1024.webp“, und erzeugt die Vorschaubilder neu.
     *
     * @param string $base Dateiname ohne Breite und Endung, z. B. „A000009-1“
     */
    public function rename(int $attachment_id, string $base): bool
    {
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $file = get_attached_file($attachment_id);
        if (!$file || !file_exists($file)) {
            return false;
        }

        $meta = wp_get_attachment_metadata($attachment_id);
        $dir  = dirname($file);
        $name = wp_unique_filename($dir, sprintf('%s-%d.%s', $base, (int) ($meta['width'] ?? self::MAX_WIDTH), pathinfo($file, PATHINFO_EXTENSION)));
        $new  = $dir . '/' . $name;

        foreach ($meta['sizes'] ?? [] as $size) {
            wp_delete_file($dir . '/' . $size['file']);
        }
        if (!rename($file, $new)) { // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- gleiche Partition
            return false;
        }

        update_attached_file($attachment_id, $new);
        wp_update_attachment_metadata($attachment_id, wp_generate_attachment_metadata($attachment_id, $new));

        return true;
    }

    /**
     * @return string|\WP_Error Pfad der WebP-Datei
     */
    private function convert(string $source): string|\WP_Error
    {
        $editor = wp_get_image_editor($source);
        if (is_wp_error($editor)) {
            return $editor;
        }

        // Falls der Browser nicht verkleinern konnte
        if ($editor->get_size()['width'] > self::MAX_WIDTH) {
            $editor->resize(self::MAX_WIDTH, null);
        }
        $editor->set_quality(self::QUALITY);

        $dir    = dirname($source);
        $name   = pathinfo($source, PATHINFO_FILENAME);
        $target = $dir . '/' . wp_unique_filename($dir, $name . '.webp');

        $saved = $editor->save($target, 'image/webp');
        if (is_wp_error($saved)) {
            return $saved;
        }

        return $saved['path'];
    }
}
