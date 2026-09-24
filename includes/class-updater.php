<?php

declare(strict_types=1);

namespace NovemberkindProdukte;

defined('ABSPATH') || exit;

/**
 * Updates aus den GitHub-Releases über den WordPress-Hook für Plugins mit „Update URI“.
 */
final class Updater
{
    public const REPOSITORY = 'ccharon/novemberkind-produkte';
    public const SLUG = 'novemberkind-produkte';
    public const ASSET = 'novemberkind-produkte.zip';
    private const CACHE = 'novemberkind_produkte_release';

    public function register(): void
    {
        add_filter('update_plugins_github.com', [$this, 'check'], 10, 3);
        add_filter('plugins_api', [$this, 'details'], 10, 3);
        add_action('upgrader_process_complete', static fn() => delete_site_transient(self::CACHE));
    }

    /**
     * @param array<string, mixed>|false $update
     * @param array<string, string>      $plugin_data
     * @return array<string, mixed>|false
     */
    public function check(array|false $update, array $plugin_data, string $plugin_file): array|false
    {
        if ($plugin_file !== plugin_basename(PLUGIN_FILE)) {
            return $update;
        }

        $release = $this->latest_release();
        if ($release === null) {
            return $update;
        }

        return [
            'id'           => 'github.com/' . self::REPOSITORY,
            'slug'         => self::SLUG,
            'version'      => $release['version'],
            'url'          => $release['url'],
            'package'      => $release['package'],
            'requires_php' => $plugin_data['RequiresPHP'] ?? '',
        ];
    }

    /**
     * Inhalt für „Details ansehen“, sonst fragt WordPress bei wordpress.org nach und findet nichts.
     */
    public function details(mixed $result, string $action, object $args): mixed
    {
        if ($action !== 'plugin_information' || ($args->slug ?? '') !== self::SLUG) {
            return $result;
        }

        $release = $this->latest_release();
        if ($release === null) {
            return $result;
        }

        require_once ABSPATH . 'wp-admin/includes/plugin.php';
        $plugin = get_plugin_data(PLUGIN_FILE, false, false);

        return (object) [
            'name'          => $plugin['Name'],
            'slug'          => self::SLUG,
            'version'       => $release['version'],
            'author'        => $plugin['Author'],
            'homepage'      => 'https://github.com/' . self::REPOSITORY,
            'requires'      => $plugin['RequiresWP'],
            'requires_php'  => $plugin['RequiresPHP'],
            'download_link' => $release['package'],
            'last_updated'  => $release['published'],
            'sections'      => [
                'changelog' => $release['notes'] !== '' ? wpautop(esc_html($release['notes'])) : esc_html__('Keine Angaben.', 'novemberkind-produkte'),
            ],
        ];
    }

    /**
     * Neuestes Release, 12 Stunden zwischengespeichert. „Erneut prüfen“ in WordPress fragt sofort nach.
     *
     * @return array{version: string, url: string, package: string, notes: string, published: string}|null
     */
    public function latest_release(): ?array
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nur Auslöser für eine frische Abfrage
        $force  = is_admin() && !empty($_GET['force-check']);
        $cached = $force ? false : get_site_transient(self::CACHE);
        if (is_array($cached)) {
            return $cached['release'];
        }

        $release = $this->fetch_release();
        // Fehlschläge nur kurz merken, damit ein Ausfall von GitHub nicht stundenlang nachwirkt
        set_site_transient(self::CACHE, ['release' => $release], $release === null ? HOUR_IN_SECONDS : 12 * HOUR_IN_SECONDS);

        return $release;
    }

    /**
     * @return array{version: string, url: string, package: string, notes: string, published: string}|null
     */
    private function fetch_release(): ?array
    {
        $response = wp_remote_get('https://api.github.com/repos/' . self::REPOSITORY . '/releases/latest', [
            'timeout' => 10,
            'headers' => ['Accept' => 'application/vnd.github+json', 'User-Agent' => 'WordPress/' . self::SLUG],
        ]);
        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
            return null;
        }

        $data    = json_decode(wp_remote_retrieve_body($response), true);
        $version = is_array($data) ? ltrim((string) ($data['tag_name'] ?? ''), 'v') : '';
        if (!preg_match('/^\d+\.\d+\.\d+$/', $version)) {
            return null;
        }

        // Nur das eigene Paket aus diesem Repository annehmen
        $expected = 'https://github.com/' . self::REPOSITORY . '/releases/download/';
        foreach ((array) ($data['assets'] ?? []) as $asset) {
            $url = (string) ($asset['browser_download_url'] ?? '');
            if (($asset['name'] ?? '') === self::ASSET && str_starts_with($url, $expected)) {
                return [
                    'version'   => $version,
                    'url'       => (string) ($data['html_url'] ?? 'https://github.com/' . self::REPOSITORY),
                    'package'   => $url,
                    'notes'     => (string) ($data['body'] ?? ''),
                    'published' => (string) ($data['published_at'] ?? ''),
                ];
            }
        }

        return null;
    }
}
