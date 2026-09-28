<?php

/**
 * Integrationstests: Hooks mit fremden Argumenten, Datenbank-Stand, Eingaben und Pflichtfelder.
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

section('Hooks mit fremden Argumenten');
$campaigns_hooks = new NovemberkindProdukte\Campaigns();
check('Preisfilter ohne Produkt gibt den Preis unverändert zurück', $campaigns_hooks->filter_price('4.50', null) === '4.50' && $campaigns_hooks->filter_price(null) === null);
check('Preisanzeige mit fremden Werten bleibt unverändert', $campaigns_hooks->range_price_html(null, null) === null && $campaigns_hooks->range_price_html('<span>1 €</span>', 'kein Produkt') === '<span>1 €</span>');
check('Verfügbarkeitstext mit fremden Werten bleibt unverändert', (new NovemberkindProdukte\Originals())->availability_text(null, null) === null && (new NovemberkindProdukte\Originals())->availability_text('Vorrätig', 42) === 'Vorrätig');
check('Versandfilter mit fremden Werten bleibt unverändert', (new NovemberkindProdukte\Coupons())->free_shipping_rates(null) === null);
check('Sichtbarkeit verkaufter Unikate mit fremden Werten bricht nicht ab', (static function (): bool {
    (new NovemberkindProdukte\Originals())->update_visibility(null, null, 'kein Produkt');
    (new NovemberkindProdukte\Originals())->update_visibility('abc');
    return true;
})());
check('Login-Weiterleitung mit fremden Werten bleibt unverändert', (new NovemberkindProdukte\Login())->login_redirect('/ziel/', ['liste'], get_user_by('login', 'shop')) === '/ziel/' && (new NovemberkindProdukte\Login())->login_redirect('/ziel/') === '/ziel/');

section('Datenbank-Stand');
update_option('novemberkind_produkte_webp_supported', 'yes');
update_option('novemberkind_produkte_db_version', 0);
NovemberkindProdukte\Plugin::upgrade();
check('Upgrade entfernt die alte WebP-Option und merkt sich den Stand', get_option('novemberkind_produkte_webp_supported') === false && (int) get_option('novemberkind_produkte_db_version') === 1);

section('Eingaben einlesen');
foreach (['24,90' => '24.90', '24.90' => '24.90', '1.234,50' => '1234.50', '5' => '5.00', ' 9,5 € ' => '9.50'] as $in => $out) {
    check("Preis „{$in}“ → {$out}", NovemberkindProdukte\Input::price($in) === $out);
}
foreach (['', 'abc', '-3', '1,234'] as $in) {
    check("Preis „{$in}“ wird abgelehnt", NovemberkindProdukte\Input::price($in) === null);
}
check('Maß „7,5“ → 7.5', NovemberkindProdukte\Input::number('7,5') === 7.5);
check('Maß „0“ wird abgelehnt', NovemberkindProdukte\Input::number('0') === null);
check('Maß 10.5 wird als „10,5“ angezeigt', ProductType::format_number('10.5') === '10,5');
check('Schlagwörter ohne Dubletten', NovemberkindProdukte\Input::tags('Otter, tier ,otter,, ') === ['Otter', 'tier']);
$input = NovemberkindProdukte\Input::class;
check('IDs ohne Dubletten, 0 und Listen in Listen', $input::ids(['ids' => ['3', '0', 'x', '3', ['7'], '12']], 'ids') === [3, 12]);
check('einzelne ID als Liste', $input::ids(['ids' => '5'], 'ids') === [5]);
check('Auswahl außerhalb der erlaubten Werte ergibt den Standard', $input::choice(['s' => 'hack'], 's', ['a', 'b'], 'a') === 'a' && $input::choice(['s' => 'b'], 's', ['a', 'b'], 'a') === 'b');
check('Prozent von 1 bis zur Grenze', $input::percent(['p' => '90'], 'p', 90) === 90 && $input::percent(['p' => '91'], 'p', 90) === null && $input::percent(['p' => '0'], 'p', 90) === null && $input::percent(['p' => '5.5'], 'p', 90) === null);
check('Zeitpunkt aus Datum und Uhrzeit in der Zeitzone des Shops', $input::datetime(['start_date' => '2030-10-01', 'start_time' => '18:00'], 'start') === (new DateTimeImmutable('2030-10-01 18:00', \NovemberkindProdukte\Time::zone()))->getTimestamp());
check('Zeitpunkt ohne Uhrzeit nimmt die Vorgabe', $input::datetime(['end_date' => '2030-10-01'], 'end', '23:59') === (new DateTimeImmutable('2030-10-01 23:59', \NovemberkindProdukte\Time::zone()))->getTimestamp());
check('31. Februar wird abgelehnt', $input::datetime(['d_date' => '2030-02-31'], 'd') === null);

section('Zeitzone');
$time = \NovemberkindProdukte\Time::class;
$utc = static fn(string $value): int => (new DateTimeImmutable($value, new DateTimeZone('UTC')))->getTimestamp();
$old_timezone = [get_option('timezone_string'), get_option('gmt_offset')];
update_option('timezone_string', '');
update_option('gmt_offset', '0');
check('Zeitzone kommt aus WordPress', $time::parse('2026-09-28', '18:00') === $utc('2026-09-28 18:00'));
update_option('timezone_string', 'Europe/Berlin');
check('Sommerzeit: 18:00 in Berlin ist 16:00 UTC', $time::parse('2026-09-28', '18:00') === $utc('2026-09-28 16:00'));
check('Winterzeit: 18:00 in Berlin ist 17:00 UTC', $time::parse('2026-11-02', '18:00') === $utc('2026-11-02 17:00'));
check('Anzeige in Berlin', $time::format('d.m.Y H:i', $utc('2026-12-31 23:30')) === '01.01.2027 00:30');
check('Umstellung auf Winterzeit: der Tag hat 25 Stunden', $time::day_start('2026-10-26') - $time::day_start('2026-10-25') === 25 * HOUR_IN_SECONDS);
check('Umstellung auf Sommerzeit: der Tag hat 23 Stunden', $time::day_start('2027-03-28', 1) - $time::day_start('2027-03-28') === 23 * HOUR_IN_SECONDS);
check('Uhrzeit in der Lücke der Sommerzeit rückt vor', $time::parse('2027-03-28', '02:30') === $utc('2027-03-28 01:30'));
check('doppelte Uhrzeit bei Winterzeit gilt noch als Sommerzeit', $time::parse('2026-10-25', '02:30') === $utc('2026-10-25 00:30'));
check('ungültige Uhrzeit wird abgelehnt', $time::parse('2026-10-01', '24:00') === null && $time::parse('2026-10-01', '9:00') === null);
check('Datum ohne führende Null wird abgelehnt', $time::parse('2026-1-01') === null);
update_option('timezone_string', $old_timezone[0]);
update_option('gmt_offset', $old_timezone[1]);
check('Text ohne Slashes und Tags', $input::text(['t' => ' Otter \\"Olli\\" <b>x</b> '], 't') === 'Otter "Olli" x');
check('Motiv aus „Button: Sophie“', ProductType::get('button')->motif_from_name('Button: Sophie') === 'Sophie');
check('Motiv beim Lesezeichen ist der Name', ProductType::get('bookmark')->motif_from_name('Kaffee to go') === 'Kaffee to go');

section('Pflichtfelder');
$errors = static fn($result): array => is_wp_error($result) ? array_keys((array) $result->get_error_data()) : [];
check('Button ohne Motiv und Preis', $errors($service->save(ProductType::get('button'), ['price' => ''])) === ['motif', 'sku', 'price']);
check('Karte ohne Format', in_array('format', $errors($service->save(ProductType::get('card'), ['motif' => 'x', 'price' => '1'])), true));
check('Sticker ohne Oberfläche und Maße', array_values(array_intersect(['finish', 'width', 'height'], $errors($service->save(ProductType::get('sticker'), ['motif' => 'x', 'price' => '1'])))) === ['finish', 'width', 'height']);
check('Original ohne Technik, Jahr, Text und Preis', array_values(array_intersect(['technique', 'year', 'text', 'price'], $errors($service->save(ProductType::get('original'), ['motif' => 'x'])))) === ['technique', 'year', 'text', 'price']);
check('Artikelnummer im falschen Format', in_array('sku', $errors($service->save(ProductType::get('card'), ['sku' => 'B12'])), true));
check('Artikelnummer klein geschrieben wird akzeptiert', !in_array('sku', $errors($service->save(ProductType::get('card'), ['sku' => 'a999998'])), true));
check('Jahr in der Zukunft wird abgelehnt', in_array('year', $errors($service->save(ProductType::get('original'), ['year' => (string) ((int) \NovemberkindProdukte\Time::format('Y') + 1)])), true));
