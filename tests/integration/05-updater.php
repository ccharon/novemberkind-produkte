<?php

/**
 * Integrationstests: Updates aus GitHub-Releases, ohne echte Anfrage.
 * Teil von tests/integration.php, läuft im selben Gültigkeitsbereich und nutzt dessen Hilfsfunktionen und Variablen.
 */

use NovemberkindProdukte\Campaigns;
use NovemberkindProdukte\Coupons;
use NovemberkindProdukte\ImageProcessor;
use NovemberkindProdukte\NewsletterMail;
use NovemberkindProdukte\Newsletters;
use NovemberkindProdukte\NewsletterSignup;
use NovemberkindProdukte\Originals;
use NovemberkindProdukte\ProductService;
use NovemberkindProdukte\ProductType;
use NovemberkindProdukte\ShopData;
use NovemberkindProdukte\Subscribers;

defined('ABSPATH') || exit(1);

section('Updates aus GitHub-Releases (ohne echte Anfrage)');
$github = null;
$fake_github = static function ($pre, array $args, string $url) use (&$github) {
    return str_contains($url, 'api.github.com/repos/ccharon/novemberkind-produkte') ? $github : $pre;
};
add_filter('pre_http_request', $fake_github, 10, 3);
$release_json = static fn(string $asset_url) => ['response' => ['code' => 200, 'message' => 'OK'], 'headers' => [], 'body' => wp_json_encode([
    'tag_name' => 'v9.9.9', 'html_url' => 'https://github.com/ccharon/novemberkind-produkte/releases/tag/v9.9.9',
    'body' => "Neu: Testrelease\n<script>x</script>", 'published_at' => '2026-09-24T12:00:00Z',
    'assets' => [['name' => 'novemberkind-produkte.zip', 'browser_download_url' => $asset_url]],
])];
$updater = new NovemberkindProdukte\Updater();
$plugin_file = plugin_basename(NovemberkindProdukte\PLUGIN_FILE);

delete_site_transient('novemberkind_produkte_release');
$github = $release_json('https://github.com/ccharon/novemberkind-produkte/releases/download/v9.9.9/novemberkind-produkte.zip');
$offer = $updater->check(false, ['Version' => '0.1.0', 'RequiresPHP' => '8.1'], $plugin_file);
check('neues Release wird angeboten', is_array($offer) && $offer['version'] === '9.9.9' && str_ends_with($offer['package'], '/v9.9.9/novemberkind-produkte.zip'));
check('andere Plugins bleiben unberührt', $updater->check(false, ['Version' => '1.0'], 'anderes/anderes.php') === false);

$github = ['response' => ['code' => 500, 'message' => 'Fehler'], 'headers' => [], 'body' => ''];
check('Antwort kommt aus dem Zwischenspeicher', $updater->latest_release()['version'] === '9.9.9');

delete_site_transient('novemberkind_produkte_release');
$github = $release_json('https://github.com/fremd/boeses-plugin/releases/download/v9.9.9/novemberkind-produkte.zip');
check('Paket aus fremdem Repository wird abgelehnt', $updater->latest_release() === null && str_contains(implode(' ', $updater->row_notice([], $plugin_file)), 'ohne Plugin-Paket'));

delete_site_transient('novemberkind_produkte_release');
$github = ['response' => ['code' => 404, 'message' => 'Not Found'], 'headers' => [], 'body' => '{}'];
$offline = $updater->check(false, ['Version' => '0.1.0'], $plugin_file);
check('ohne Antwort von GitHub als aktuell gemeldet, ohne Paket', is_array($offline) && $offline['version'] === '0.1.0' && $offline['package'] === '');
check('Hinweis mit Fehlergrund in der Plugin-Liste', str_contains(implode(' ', $updater->row_notice([], $plugin_file)), 'HTTP 404 Not Found'));
check('kein Hinweis bei anderen Plugins', $updater->row_notice([], 'anderes/anderes.php') === []);
delete_site_transient('update_plugins');
wp_update_plugins();
check('WordPress führt das Plugin trotzdem als aktualisierbar', isset(get_site_transient('update_plugins')->no_update[$plugin_file]));

delete_site_transient('novemberkind_produkte_release');
$github = $release_json('https://github.com/ccharon/novemberkind-produkte/releases/download/v9.9.9/novemberkind-produkte.zip');
$details = $updater->details(false, 'plugin_information', (object) ['slug' => 'novemberkind-produkte']);
check('„Details ansehen“ mit Änderungen, ohne Skript', is_object($details) && str_contains($details->sections['changelog'], 'Neu: Testrelease') && !str_contains($details->sections['changelog'], '<script>'));

delete_site_transient('update_plugins');
wp_update_plugins();
$updates = get_site_transient('update_plugins');
check('WordPress meldet das Update in der Plugin-Liste', ($updates->response[$plugin_file]->new_version ?? '') === '9.9.9');
check('nach Erfolg kein Fehlerhinweis', $updater->row_notice([], $plugin_file) === []);
remove_filter('pre_http_request', $fake_github, 10);
delete_site_transient('novemberkind_produkte_release');
delete_site_transient('update_plugins');
