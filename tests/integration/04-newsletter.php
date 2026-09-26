<?php

/**
 * Integrationstests: Newsletter: Anmeldung, Grenzen, Versand in Päckchen, Mail und JPEG-Fassungen.
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

section('Newsletter');
$mails = [];
$catch_mail = static function ($pre, array $atts) use (&$mails) {
    $mails[] = $atts;
    return true;
};
add_filter('pre_wp_mail', $catch_mail, 10, 2);
$subscribers = new Subscribers();
$newsletters = new Newsletters();
$test_subscribers = [];
$confirm_new = static function (string $email) use ($subscribers, &$test_subscribers): array {
    $subscribers->subscribe($email, 'form');
    $subscriber = Subscribers::find($email);
    $test_subscribers[] = $subscriber['id'];
    return $subscribers->confirm($subscriber['token']);
};

if (Subscribers::counts()['confirmed'] === 0) {
    $nobody = $newsletters->save(['subject' => 'Leer', 'content' => '<p>x</p>', 'send' => 'now']);
    check('„Jetzt verschicken“ ohne bestätigte Empfänger wird abgelehnt', is_wp_error($nobody) && array_keys($nobody->get_error_data()) === ['send']);
}
check('ungültige Adresse wird abgelehnt', is_wp_error($invalid_mail = $subscribers->subscribe('keine-adresse', 'form')) && $invalid_mail->get_error_code() === 'email');
$subscribers->subscribe(' Test-Abo@Example.org ', 'checkout');
$pending = Subscribers::find('test-abo@example.org');
$test_subscribers[] = $pending['id'] ?? 0;
check('Anmeldung wartet auf Bestätigung, Adresse kleingeschrieben', $pending !== null && $pending['status'] === 'pending' && $pending['source'] === 'checkout' && $pending['email'] === 'test-abo@example.org');
check('Bestätigungsmail mit Link und Token', count($mails) === 1 && $mails[0]['to'] === 'test-abo@example.org' && str_contains($mails[0]['message'], 'nkp-newsletter=bestaetigen&#038;t=' . $pending['token']));
$subscribers->subscribe('test-abo@example.org', 'form');
check('keine zweite Mail innerhalb von 10 Minuten', count($mails) === 1);
check('falsches Token wird abgelehnt', Subscribers::by_token(str_repeat('a', 32)) === null && Subscribers::by_token('kurz') === null);
$confirmed = $subscribers->confirm($pending['token']);
check('Bestätigung über das Token', $confirmed !== null && $confirmed['status'] === 'confirmed' && $confirmed['confirmed'] > 0);
$subscribers->subscribe('test-abo@example.org', 'form');
check('bestätigte Adresse bekommt keine weitere Mail', count($mails) === 1 && Subscribers::find('test-abo@example.org')['status'] === 'confirmed');
$long_ago = time() - 30 * DAY_IN_SECONDS;
update_post_meta($pending['id'], Subscribers::META, ['status' => 'confirmed', 'created' => $long_ago, 'confirmed' => $long_ago, 'source' => 'checkout']);
$subscribers->subscribe('test-abo@example.org', 'form');
check('auch nach Wochen keine neue Bestätigungsmail für bestätigte Adressen', count($mails) === 1 && Subscribers::find('test-abo@example.org')['status'] === 'confirmed');
$subscribers->subscribe('test-alt@example.org', 'form');
$old = Subscribers::find('test-alt@example.org');
update_post_meta($old['id'], Subscribers::META, ['status' => 'pending', 'created' => time() - 8 * DAY_IN_SECONDS, 'confirmed' => 0, 'source' => 'form']);
$subscribers->cleanup();
check('unbestätigte Anmeldung verfällt nach 7 Tagen', Subscribers::get($old['id']) === null);
$subscribers->subscribe('test-frisch@example.org', 'form');
$fresh = Subscribers::find('test-frisch@example.org');
$test_subscribers[] = $fresh['id'];
$subscribers->cleanup();
check('Aufräumen lässt frische und alte bestätigte Adressen stehen', Subscribers::get($fresh['id']) !== null && Subscribers::find('test-abo@example.org') !== null);
check('Aufräumen läuft täglich über WP-Cron', wp_get_schedule(Subscribers::CLEANUP_HOOK) === 'daily');
check('CSV mit bestätigter Adresse', str_contains(Subscribers::csv(), '"test-abo@example.org";') && !str_contains(Subscribers::csv(), 'test-alt@'));
check('Adresse steht nicht im Titel des Eintrags', !str_contains(get_the_title($pending['id']), '@'));
$exporters = apply_filters('wp_privacy_personal_data_exporters', []);
$erasers = apply_filters('wp_privacy_personal_data_erasers', []);
check('beim Datenexport und Löschen von WordPress angemeldet', isset($exporters['novemberkind-produkte-newsletter'], $erasers['novemberkind-produkte-newsletter']));
$exported = call_user_func($exporters['novemberkind-produkte-newsletter']['callback'], 'Test-Abo@example.org', 1);
check('Datenexport enthält Adresse, Status und Quelle', count($exported['data']) === 1 && $exported['done'] && in_array('test-abo@example.org', array_column($exported['data'][0]['data'], 'value'), true) && in_array('Kasse', array_column($exported['data'][0]['data'], 'value'), true));
$subscribers->subscribe('test-loeschen@example.org', 'form');
$erased = call_user_func($erasers['novemberkind-produkte-newsletter']['callback'], 'test-loeschen@example.org', 1);
check('Löschanfrage entfernt die Anmeldung', $erased['items_removed'] && $erased['done'] && Subscribers::find('test-loeschen@example.org') === null);
check('Löschanfrage für unbekannte Adresse', call_user_func($erasers['novemberkind-produkte-newsletter']['callback'], 'unbekannt@example.org', 1)['items_removed'] === false);
$formula = $confirm_new('=1+1@example.org');
check('CSV entschärft Werte, die wie Formeln aussehen', str_contains(Subscribers::csv(), '"\'=1+1@example.org";'));

$from = NewsletterMail::from();
check('Absender aus den WooCommerce-Einstellungen', $from['email'] === get_option('woocommerce_email_from_address'));

$letter_product = $service->save(ProductType::get('sticker'), ['sku' => ShopData::next_sku(), 'motif' => 'Newslettertest', 'price' => '2,5', 'width' => '5', 'height' => '5', 'finish' => 'matt', 'status' => 'publish']);
$cleanup['products'][] = $letter_product->get_id();
$empty_issue = $newsletters->save(['subject' => '', 'content' => '<p> </p>']);
check('Newsletter: Betreff und Inhalt werden geprüft', is_wp_error($empty_issue) && array_keys($empty_issue->get_error_data()) === ['subject', 'content']);
$issue_data = [
    'subject'   => 'Tee & Kekse \\o/',
    'preheader' => 'Neue Sticker',
    'content'   => '<p>Hallo <strong>du</strong>, <a href="https://example.org/neu/">hier entlang</a>.</p><script>alert(1)</script>',
    'products'  => [$letter_product->get_id()],
    'send'      => 'draft',
];
$draft = $newsletters->save(wp_slash($issue_data));
$issue_ids = [$draft['id']];
check('Entwurf gespeichert, Betreff unverändert', $draft['status'] === 'draft' && $draft['subject'] === 'Tee & Kekse \\o/');
check('Inhalt ohne Skript', !str_contains($draft['content'], 'script') && str_contains($draft['content'], '<strong>du</strong>'));
$rendered = NewsletterMail::render($draft);
check('Mail mit Vorschauzeile, Produkt und Abmeldelink', str_contains($rendered['html'], 'Neue Sticker') && str_contains($rendered['html'], 'Sticker: Newslettertest') && str_contains($rendered['html'], 'nkp-newsletter=abmelden&#038;t=' . NewsletterMail::TOKEN_PLACEHOLDER));
$store_address = get_option('woocommerce_store_address');
update_option('woocommerce_store_address', 'Teststraße 12');
check('Fuß der Mail ohne Anschrift, mit Shopname', !str_contains(NewsletterMail::render($draft)['html'], 'Teststraße') && preg_match('/#d7dce2;">\s*' . preg_quote(esc_html(get_bloginfo('name')), '/') . '\s*</', $rendered['html']) === 1);
update_option('woocommerce_store_address', $store_address);
$logo_url = (string) wp_get_attachment_image_url((int) get_option('site_logo'), 'medium');
check('Mail mit dem Logo des Shops im Kopf', $logo_url !== '' && str_contains($rendered['html'], 'src="' . $logo_url . '"'));
check('Textfassung mit Link und Preis', str_contains($rendered['text'], 'hier entlang (https://example.org/neu/)') && str_contains($rendered['text'], '2,50'));

$angle = $newsletters->parse(['subject' => 'Herz <3 & mehr', 'preheader' => 'a < b', 'content' => '<p>x</p>']);
check('einzelnes < in Betreff und Vorschauzeile bleibt lesbar', !is_wp_error($angle) && $angle['subject'] === 'Herz <3 & mehr' && $angle['preheader'] === 'a < b');
check('Tags im Betreff werden entfernt', $newsletters->parse(['subject' => '<b>Fett</b>', 'content' => '<p>x</p>'])['subject'] === 'Fett');

$mails = [];
check('Testmail an eine Adresse', $newsletters->send_test(wp_slash($issue_data), 'shop@example.org') === true && count($mails) === 1 && $mails[0]['subject'] === '[Test] Tee & Kekse \\o/');

$past = $newsletters->save(wp_slash(['send' => 'scheduled', 'send_date' => wp_date('Y-m-d', time() - DAY_IN_SECONDS), 'send_time' => '10:00'] + $issue_data), $draft['id']);
check('geplanter Versand in der Vergangenheit wird abgelehnt', is_wp_error($past) && array_keys($past->get_error_data()) === ['send']);
$later = time() + DAY_IN_SECONDS;
$planned = $newsletters->save(wp_slash(['send' => 'scheduled', 'send_date' => wp_date('Y-m-d', $later), 'send_time' => wp_date('H:i', $later)] + $issue_data), $draft['id']);
check('geplant mit Aufgabe im Action Scheduler', $planned['status'] === 'scheduled' && as_next_scheduled_action(Newsletters::HOOK_START, ['id' => $draft['id']], 'novemberkind-produkte') !== false);
$newsletters->save(wp_slash($issue_data), $draft['id']);
// Geplanter Zeitpunkt verstrichen, Aufgabe fehlt, etwa weil das Plugin deaktiviert war
$newsletters->save(wp_slash(['send' => 'scheduled', 'send_date' => wp_date('Y-m-d', $later), 'send_time' => wp_date('H:i', $later)] + $issue_data), $draft['id']);
as_unschedule_all_actions(Newsletters::HOOK_START, ['id' => $draft['id']], 'novemberkind-produkte');
$overdue = get_post_meta($draft['id'], Newsletters::META, true);
update_post_meta($draft['id'], Newsletters::META, wp_slash(['scheduled' => time() - 60] + $overdue));
$newsletters->resume_stalled();
check('überfällige geplante Ausgabe wird nachgeholt', as_has_scheduled_action(Newsletters::HOOK_START, ['id' => $draft['id']], 'novemberkind-produkte'));
as_unschedule_all_actions(Newsletters::HOOK_START, ['id' => $draft['id']], 'novemberkind-produkte');
$newsletters->save(wp_slash($issue_data), $draft['id']);
check('zurück zum Entwurf ohne Aufgabe', Newsletters::get($draft['id'])['status'] === 'draft' && as_next_scheduled_action(Newsletters::HOOK_START, ['id' => $draft['id']], 'novemberkind-produkte') === false);

check('Versand bleibt unter der Grenze des Shops von 250 Mails pro Stunde', Newsletters::batch_size() * HOUR_IN_SECONDS / Newsletters::BATCH_INTERVAL <= 250);

// 30 bestätigte Empfänger für mehrere Päckchen
for ($i = 1; $i <= 30; $i++) {
    $confirm_new("test-abo-{$i}@example.org");
}
$mails = [];
$sending = $newsletters->save(wp_slash(['send' => 'now'] + $issue_data));
$issue_ids[] = $sending['id'];
$recipients = count(Subscribers::confirmed());
check('„Jetzt verschicken“ legt die Empfänger fest', $sending['status'] === 'sending' && $sending['recipients'] === $recipients && Newsletters::remaining($sending['id']) === $recipients);
check('laufender Newsletter lässt sich nicht ändern', is_wp_error($newsletters->save(wp_slash($issue_data), $sending['id'])));
// Die Aufgabe vom Start zählt nicht, geprüft wird das vom Päckchen geplante nächste
as_unschedule_all_actions(Newsletters::HOOK_BATCH, ['id' => $sending['id']], 'novemberkind-produkte');
// Die Sperre hält eine zweite Datenbankverbindung, wie ein anderer PHP-Prozess
$other_db = new wpdb(DB_USER, DB_PASSWORD, DB_NAME, DB_HOST);
$lock_name = (new ReflectionMethod(Newsletters::class, 'lock_name'))->invoke(null, $sending['id']);
$other_db->query($other_db->prepare('SELECT GET_LOCK(%s, 0)', $lock_name));
$newsletters->send_batch($sending['id']);
check('während ein Päckchen läuft, sendet kein zweites und kommt später dran', $mails === [] && Newsletters::remaining($sending['id']) === $recipients
    && as_next_scheduled_action(Newsletters::HOOK_BATCH, ['id' => $sending['id']], 'novemberkind-produkte') >= time() + Newsletters::BATCH_INTERVAL - 10);
check('gesperrte Ausgabe lässt sich nicht speichern', is_wp_error($locked_save = $newsletters->save(wp_slash($issue_data), $sending['id'])) && $locked_save->get_error_code() === 'busy');
$other_db->query($other_db->prepare('SELECT RELEASE_LOCK(%s)', $lock_name));
$other_db->close();
as_unschedule_all_actions(Newsletters::HOOK_BATCH, ['id' => $sending['id']], 'novemberkind-produkte');
$newsletters->send_batch($sending['id']);
$after_first = Newsletters::get($sending['id']);
$next_batch = as_next_scheduled_action(Newsletters::HOOK_BATCH, ['id' => $sending['id']], 'novemberkind-produkte');
check('nächstes Päckchen etwa eine Minute später geplant', is_int($next_batch) && $next_batch >= time() + Newsletters::BATCH_INTERVAL - 10);
check('erstes Päckchen mit 4 Mails, Rest geplant', count($mails) === Newsletters::batch_size() && $after_first['sent'] === Newsletters::batch_size() && $after_first['status'] === 'sending' && as_next_scheduled_action(Newsletters::HOOK_BATCH, ['id' => $sending['id']], 'novemberkind-produkte') !== false);
// Eine Testadresse aus einem späteren Päckchen meldet sich zwischendurch ab
$queued = array_values(array_intersect((array) get_post_meta($sending['id'], Newsletters::META_QUEUE, true), $test_subscribers));
$leaving = Subscribers::get((int) ($queued[0] ?? 0));
$first_mail = $mails[0];
$first_token = Subscribers::find($first_mail['to'])['token'];
check('Mail mit persönlichem Abmeldelink und Ein-Klick-Kopfzeilen', str_contains($first_mail['message'], 't=' . $first_token) && !str_contains($first_mail['message'], NewsletterMail::TOKEN_PLACEHOLDER)
    && in_array('List-Unsubscribe-Post: List-Unsubscribe=One-Click', $first_mail['headers'], true) && in_array('List-Unsubscribe: <' . NewsletterSignup::url('abmelden', $first_token) . '>', $first_mail['headers'], true));
check('Absender und Antwortadresse gesetzt', (bool) preg_grep('/^From: .*<' . preg_quote($from['email'], '/') . '>$/', $first_mail['headers']) && in_array('Reply-To: ' . $from['email'], $first_mail['headers'], true));
check('Abmelden über das Token löscht die Adresse', $leaving !== null && $subscribers->unsubscribe($leaving['token']) && Subscribers::get($leaving['id']) === null);
$batches = 1;
while ($batches < 50 && Newsletters::get($sending['id'])['status'] === 'sending') {
    $newsletters->send_batch($sending['id']);
    $batches++;
}
$sent_issue = Newsletters::get($sending['id']);
check('so viele Päckchen wie nötig', $batches === (int) ceil($recipients / Newsletters::batch_size()));
check('restliche Päckchen ohne die abgemeldete Adresse, dann verschickt', $sent_issue['status'] === 'sent' && $sent_issue['sent'] === $recipients - 1 && count($mails) === $recipients - 1 && !in_array($leaving['email'], array_column($mails, 'to'), true));
check('verschickter Newsletter bleibt unverändert', is_wp_error($newsletters->save(wp_slash($issue_data), $sending['id'])) && Newsletters::remaining($sending['id']) === 0);

$limit_keys = ['novemberkind_produkte_signup_all', 'novemberkind_produkte_signup_ip_' . substr(wp_hash('198.51.100.7'), 0, 32), 'novemberkind_produkte_signup_ip_' . substr(wp_hash('198.51.100.8'), 0, 32)];
array_map('delete_transient', $limit_keys);
$allowed = array_map(static fn(): bool => NewsletterSignup::within_limits('198.51.100.7'), range(1, NewsletterSignup::IP_LIMIT));
check('höchstens 5 Anmeldungen pro IP-Adresse und Stunde', !in_array(false, $allowed, true) && !NewsletterSignup::within_limits('198.51.100.7') && NewsletterSignup::within_limits('198.51.100.8'));
set_transient($limit_keys[0], ['start' => time(), 'count' => NewsletterSignup::HOURLY_LIMIT], 60);
check('Obergrenze für alle Anmeldungen pro Stunde', !NewsletterSignup::within_limits('198.51.100.8'));
set_transient($limit_keys[0], ['start' => time() - 3601, 'count' => NewsletterSignup::HOURLY_LIMIT], 60);
check('nach einer Stunde wieder frei', NewsletterSignup::within_limits('198.51.100.8'));
array_map('delete_transient', $limit_keys);

$photo_id = (new ImageProcessor())->import(make_png(800, 600), 'Newsletterfoto');
$cleanup['attachments'][] = $photo_id;
$photo_file = get_attached_file($photo_id);
$mail_url = ImageProcessor::mail_url($photo_id, 'full');
$mail_file = dirname($photo_file) . '/' . pathinfo($photo_file, PATHINFO_FILENAME) . '-mail.jpg';
check('WebP-Foto bekommt für Mails eine JPEG-Fassung', str_ends_with($mail_url, '-mail.jpg') && file_exists($mail_file) && wp_getimagesize($mail_file)['mime'] === 'image/jpeg');
check('JPEG-Fassung wird wiederverwendet', ImageProcessor::mail_url($photo_id, 'full') === $mail_url);
$with_photo = NewsletterMail::render(['subject' => 'Foto', 'preheader' => '', 'products' => [], 'content' => '<p>Vorher</p><p><img src="x.webp" class="wp-image-' . $photo_id . '" alt="Herbst &amp; Laub"></p><p><img src="https://fremd.example/bild.jpg" alt="fremd"></p>']);
check('Foto im Text als JPEG in voller Breite, fremde Bilder fallen weg', str_contains($with_photo['html'], 'src="' . $mail_url . '"') && str_contains($with_photo['html'], 'alt="Herbst &amp; Laub"') && !str_contains($with_photo['html'], 'fremd.example'));
$letter_product->set_image_id($photo_id);
$letter_product->save();
check('Produktfotos in der Mail als JPEG', str_contains(NewsletterMail::render($draft)['html'], '-mail.jpg'));
wp_delete_attachment($photo_id, true);
check('beim Löschen des Fotos verschwindet die JPEG-Fassung', !file_exists($mail_file));

check('zu lange Adresse wird abgelehnt', is_wp_error($subscribers->subscribe(str_repeat('a', 250) . '@example.org', 'form')));
$twin = $confirm_new('test-doppelt@example.org');
$twin_id = wp_insert_post(['post_type' => Subscribers::POST_TYPE, 'post_status' => 'private', 'post_title' => 'test-doppelt@example.org']);
$test_subscribers[] = $twin_id;
update_post_meta($twin_id, Subscribers::META_EMAIL, 'test-doppelt@example.org');
update_post_meta($twin_id, Subscribers::META_TOKEN, wp_generate_password(32, false));
update_post_meta($twin_id, Subscribers::META, ['status' => 'confirmed', 'created' => time(), 'confirmed' => time(), 'source' => 'form']);
$twin_issue = $newsletters->save(wp_slash(['send' => 'now'] + $issue_data));
$issue_ids[] = $twin_issue['id'];
check('doppelt angelegte Adresse bekommt den Newsletter nur einmal', $twin_issue['recipients'] === count(Subscribers::confirmed()) - 1);
check('Abmelden entfernt auch die doppelte Adresse', $subscribers->unsubscribe($twin['token']) && Subscribers::get($twin_id) === null);
$letter_product->set_post_password('geheim');
$letter_product->save();
check('passwortgeschützte Produkte erscheinen nicht in der Mail', !str_contains(NewsletterMail::render($draft)['html'], 'Sticker: Newslettertest'));

$open_link = NewsletterMail::render($newsletters->parse(['subject' => 'x', 'content' => '<p style="color:red">Text <a href="https://example.org/">offen']) + ['products' => []]);
check('offener Link im Text umschließt nicht den Abmeldelink', str_contains($open_link['text'], 'Vom Newsletter abmelden (' . NewsletterSignup::url('abmelden', NewsletterMail::TOKEN_PLACEHOLDER) . ')'));
check('Stile aus dem Text überschreiben die Gestaltung nicht', !str_contains($open_link['html'], 'color:red'));
check('Listen statt Text in Formularfeldern ergeben leeren Text', NovemberkindProdukte\Input::value(['email' => ['a@b.de']], 'email') === '');

$form = do_shortcode('[novemberkind_newsletter]');
check('Anmeldeformular per Shortcode sendet an die eigene Seite', !str_contains($form, 'admin-post.php') && str_contains($form, 'name="' . NewsletterSignup::SIGNUP_FIELD . '"') && str_contains($form, 'name="nkp_website"') && str_contains($form, 'type="email"'));
ob_start();
(new NewsletterSignup())->checkout_checkbox();
$checkbox = (string) ob_get_clean();
check('Haken an der klassischen Kasse, nicht vorausgewählt', str_contains($checkbox, 'name="nkp_newsletter"') && !str_contains($checkbox, 'checked'));
$order = wc_create_order();
$order->set_billing_email('test-kasse@example.org');
$order->save();
(new NewsletterSignup())->classic_checkout_processed($order->get_id(), [], $order);
check('Kasse ohne Haken meldet niemanden an', Subscribers::find('test-kasse@example.org') === null);
$_POST['nkp_newsletter'] = '1';
(new NewsletterSignup())->classic_checkout_processed($order->get_id(), [], $order);
unset($_POST['nkp_newsletter']);
$from_checkout = Subscribers::find('test-kasse@example.org');
$test_subscribers[] = $from_checkout['id'] ?? 0;
check('Haken an der Kasse startet die Anmeldung', $from_checkout !== null && $from_checkout['status'] === 'pending' && $from_checkout['source'] === 'checkout');
$subscribers->remove($from_checkout['id']);
$_POST['nkp_newsletter'] = '1';
(new NewsletterSignup())->classic_checkout_processed((string) $order->get_id(), null);
(new NewsletterSignup())->classic_checkout_processed();
(new NewsletterSignup())->block_checkout_processed('keine Bestellung');
unset($_POST['nkp_newsletter']);
$other_args = Subscribers::find('test-kasse@example.org');
$test_subscribers[] = $other_args['id'] ?? 0;
check('Kasse übersteht fremde Argumente und findet die Bestellung über die ID', $other_args !== null && $other_args['source'] === 'checkout');
$failing_mail = static function (): never {
    throw new RuntimeException('Mailserver weg');
};
add_filter('pre_wp_mail', $failing_mail, 1);
// Die erwartete Meldung in eine eigene Datei, damit debug.log sauber bleibt
$test_log = wp_tempnam('nkp-log');
$previous_log = ini_set('error_log', $test_log);
$_POST['nkp_newsletter'] = '1';
$subscribers->remove($other_args['id']);
try {
    (new NewsletterSignup())->classic_checkout_processed($order->get_id(), [], $order);
    $checkout_survived = true;
} catch (Throwable $e) {
    $checkout_survived = false;
}
unset($_POST['nkp_newsletter']);
remove_filter('pre_wp_mail', $failing_mail, 1);
ini_set('error_log', (string) $previous_log);
$logged = (string) file_get_contents($test_log);
unlink($test_log);
$test_subscribers[] = Subscribers::find('test-kasse@example.org')['id'] ?? 0;
check('Fehler bei der Anmeldung hält die Bestellung nicht auf und steht im Log', $checkout_survived && str_contains($logged, 'Mailserver weg'));
$order->delete(true);

// CSV-Export für ein Konto ohne manage_woocommerce; liefert der Export trotzdem aus, endet das Skript mit exit
add_role('nkp_test_produkte', 'Nur Produkte', ['read' => true, 'edit_products' => true]);
$product_only = wp_insert_user(['user_login' => 'nkp-test-produkte', 'user_pass' => wp_generate_password(), 'role' => 'nkp_test_produkte']);
wp_set_current_user($product_only);
$_REQUEST['_wpnonce'] = wp_create_nonce(Subscribers::CSV_ACTION);
$csv_guard = true;
register_shutdown_function(static function () use (&$csv_guard): void {
    if ($csv_guard) {
        ob_end_clean();
        echo "  ✗ CSV-Export ohne Newsletter-Rechte ausgeliefert\n";
        exit(1);
    }
});
$die_handler = static fn() => static function (): void {
    throw new RuntimeException('wp_die');
};
add_filter('wp_die_handler', $die_handler);
ob_start();
try {
    (new Subscribers())->download_csv();
    $csv_blocked = false;
} catch (RuntimeException $e) {
    $csv_blocked = true;
}
ob_end_clean();
$csv_guard = false;
remove_filter('wp_die_handler', $die_handler);
unset($_REQUEST['_wpnonce']);
check('CSV-Export nur mit Newsletter-Rechten', $csv_blocked);
require_once ABSPATH . 'wp-admin/includes/user.php';
wp_delete_user($product_only);
remove_role('nkp_test_produkte');
wp_set_current_user(get_user_by('login', 'shop')->ID);

define('NOVEMBERKIND_PRODUKTE_NEWSLETTER_PER_MINUTE', '500');
check('Mails pro Minute aus der wp-config.php, nach oben begrenzt', Newsletters::batch_size() === 100);

remove_filter('pre_wp_mail', $catch_mail, 10);
foreach ($issue_ids as $issue_id) {
    as_unschedule_all_actions(Newsletters::HOOK_BATCH, ['id' => $issue_id], 'novemberkind-produkte');
    as_unschedule_all_actions(Newsletters::HOOK_START, ['id' => $issue_id], 'novemberkind-produkte');
    wp_delete_post($issue_id, true);
}
foreach (array_filter($test_subscribers) as $subscriber_id) {
    $subscribers->remove($subscriber_id);
}
