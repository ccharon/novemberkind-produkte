#!/usr/bin/env bash
# HTTP-Tests der AJAX-Aktionen: als Shop-Manager anmelden, speichern, hochladen, Rechte prüfen.
set -uo pipefail

BASE=${BASE:-http://localhost:8888}
TMP=$(mktemp -d)
trap 'rm -rf "$TMP"' EXIT
JAR=$TMP/cookies
failures=0

check() {
  if [ "$2" = "$3" ]; then echo "  ✓ $1"; else echo "  ✗ $1 (erwartet: $3, bekommen: $2)"; failures=$((failures + 1)); fi
}

ajax() {
  curl -s -b "$JAR" -o "$TMP/body" -w '%{http_code}' "$BASE/wp-admin/admin-ajax.php" "$@"
}

json() {
  python3 -c "import json,sys; d=json.load(open('$TMP/body')); print($1)" 2>/dev/null
}

echo
echo "HTTP"
APP=$BASE/produkte-verwalten

location() {
  curl -s -o /dev/null -w '%{redirect_url}' "$@"
}

manifest=$(curl -s -w '\n%{http_code} %{content_type}' "$APP/manifest.webmanifest")
check 'Manifest ohne Anmeldung abrufbar' "$(tail -1 <<<"$manifest")" '200 application/manifest+json; charset=utf-8'
check 'Manifest startet in der Produktverwaltung, ohne Browserleisten' "$(head -1 <<<"$manifest" | python3 -c "import json,sys; d=json.load(sys.stdin); print(d['start_url'], d['display'], d['short_name'], len(d['icons']))")" '/produkte-verwalten/ standalone Produkte 3'
for size in 180 192 512; do
  check "App-Icon ${size} px erreichbar" "$(curl -s -o /dev/null -w '%{http_code} %{content_type}' "$BASE/wp-content/plugins/novemberkind-produkte/assets/icons/app-icon-$size.png")" '200 image/png'
done
check 'ohne Login zur Anmeldung' "$(location "$APP/neu/" | grep -c '/wp-login.php?redirect_to=.*produkte-verwalten%2Fneu')" 1

curl -s -c "$JAR" -b "$JAR" -o /dev/null "$BASE/wp-login.php"
check 'Shop-Manager landet nach Login in der Produktverwaltung' \
  "$(location -c "$JAR" -b "$JAR" -d 'log=shop&pwd=password&testcookie=1' "$BASE/wp-login.php")" "$APP/"

check 'Auswahl der Produktart zeigt 5 Arten' "$(curl -s -b "$JAR" "$APP/neu/" | grep -c 'class="nkp-type"')" 5
page=$(curl -s -b "$JAR" "$APP/neu/button/")
nonce=$(grep -oP 'var novemberkindProdukte = .*?"nonce":"\K[a-f0-9]+' <<<"$page")
check 'Button-Formular lädt mit Nonce' "$([ -n "$nonce" ] && echo ja)" ja
check 'Schutz gegen Einbetten' "$(curl -s -b "$JAR" -D - -o /dev/null "$APP/neu/button/" | grep -ci '^x-frame-options: sameorigin')" 1
check 'Seite verweist auf Manifest und Apple-Icon' "$(grep -cE 'rel="manifest"|rel="apple-touch-icon"|apple-mobile-web-app-capable' <<<"$page")" 3
check 'ohne WordPress-Oberfläche' "$(grep -c 'id="wpadminbar"' <<<"$page")" 0
check 'unbekannte Produktart liefert 404' "$(curl -s -b "$JAR" -o /dev/null -w '%{http_code}' "$APP/neu/tasse/")" 404
overview=$(curl -s -b "$JAR" "$APP/")
check 'Übersicht nach Kategorien' "$(grep -oP 'class="nkp-group__title">\K[^<]+' <<<"$overview" | paste -sd,)" 'Buttons,Karten,magnetische Lesezeichen,Originalzeichnungen,Sticker,Tassen'

card_id=$(bin/wp post list --post_type=product --title='Karte: Rubie Weltentdeckerin' --format=ids)
check 'Karte bearbeiten lädt mit Format' "$(curl -s -b "$JAR" "$APP/$card_id/" | grep -cE "name=\"format\" value=\"quer\" +checked")" 1
mug_id=$(bin/wp post list --post_type=product --title='Tasse: Inga und Lisa' --format=ids)
check 'Produkt ohne Vorlage öffnet WooCommerce' "$(location -b "$JAR" "$APP/$mug_id/" | grep -c "post.php?post=$mug_id&action=edit")" 1
check 'unbekanntes Produkt liefert 404' "$(curl -s -b "$JAR" -o /dev/null -w '%{http_code}' "$APP/999999/")" 404
check 'Menüeintrag im Backend leitet weiter' "$(location -b "$JAR" "$BASE/wp-admin/admin.php?page=novemberkind-produkte")" "$APP/"

ADMIN_JAR=$TMP/admin-cookies
curl -s -c "$ADMIN_JAR" -b "$ADMIN_JAR" -o /dev/null "$BASE/wp-login.php"
check 'Administrator landet nach Login im Backend' \
  "$(location -c "$ADMIN_JAR" -b "$ADMIN_JAR" -d 'log=admin&pwd=password&testcookie=1' "$BASE/wp-login.php")" "$BASE/wp-admin/"

bin/wp user get kunde >/dev/null 2>&1 || bin/wp user create kunde kunde@example.org --role=customer --user_pass=password >/dev/null
CUSTOMER_JAR=$TMP/customer-cookies
curl -s -c "$CUSTOMER_JAR" -b "$CUSTOMER_JAR" -o /dev/null "$BASE/wp-login.php"
curl -s -c "$CUSTOMER_JAR" -b "$CUSTOMER_JAR" -o /dev/null -d 'log=kunde&pwd=password&testcookie=1' "$BASE/wp-login.php"
check 'Kunde bekommt keinen Zugang' "$(curl -s -b "$CUSTOMER_JAR" -o /dev/null -w '%{http_code}' "$APP/")" 403
bin/wp user delete kunde --yes >/dev/null

check 'Vorschau der Beschreibung' "$(ajax -d action=novemberkind_produkte_preview -d "nonce=$nonce" -d type=sticker --data-urlencode 'motif=Eule' -d finish=glaenzend -d width=7,5 -d height=6)" 200
check 'Vorschau enthält Motiv und Maße' "$(json "'Sticker Eule' in d['data']['html'] and 'Breite: 7,5 cm' in d['data']['html']")" True
check 'Vorschlag ohne Nonce abgelehnt' "$(ajax -d action=novemberkind_produkte_suggest -d type=card -d motif=x)" 403
check 'Speichern ohne Nonce abgelehnt' "$(ajax -d action=novemberkind_produkte_save -d type=card -d motif=x -d price=1)" 403
check 'unbekannte Produktart abgelehnt' "$(ajax -d action=novemberkind_produkte_save -d "nonce=$nonce" -d type=tasse -d motif=x)" 400
check 'Pflichtfehler liefert 422' "$(ajax -d action=novemberkind_produkte_save -d "nonce=$nonce" -d type=card -d motif= -d price=)" 422
check 'Fehlermeldungen für Motiv und Format' "$(json "sorted(k for k in d['data']['fields'] if k in ('motif','format'))")" "['format', 'motif']"

python3 -c "import zlib,struct
w,h=1600,1200
raw=b''.join(b'\x00'+bytes([176,85,58])*w for _ in range(h))
c=lambda t,d: struct.pack('>I',len(d))+t+d+struct.pack('>I',zlib.crc32(t+d))
open('$TMP/foto.png','wb').write(b'\x89PNG\r\n\x1a\n'+c(b'IHDR',struct.pack('>IIBBBBB',w,h,8,2,0,0,0))+c(b'IDAT',zlib.compress(raw))+c(b'IEND',b''))"
check 'Upload erfolgreich' "$(ajax -F action=novemberkind_produkte_upload -F "nonce=$nonce" -F "file=@$TMP/foto.png;type=image/png")" 200
image_id=$(json "d['data']['id']")
check 'Upload liefert WebP-Adresse' "$(json "d['data']['url'].endswith('.webp')")" True
check 'Upload ohne Datei abgelehnt' "$(ajax -F action=novemberkind_produkte_upload -F "nonce=$nonce")" 400

check 'Speichern erfolgreich' "$(ajax -d action=novemberkind_produkte_save -d "nonce=$nonce" -d type=card --data-urlencode 'motif=HTTP-Test' -d "sku=$(bin/wp eval 'echo NovemberkindProdukte\ShopData::next_sku();')" \
  -d format=hoch --data-urlencode 'price=2,50' -d stock=3 -d status=draft -d "image_id=$image_id")" 200
product_id=$(json "d['data']['id']")
check 'Meldung für Entwurf' "$(json "d['data']['message']")" 'Als Entwurf gespeichert.'

check 'Name nach Vorlage' "$(json "d['data']['name']")" 'Karte: HTTP-Test'
sku=$(bin/wp eval "echo wc_get_product($(json "d['data']['id']"))->get_sku();")

check 'Ändern erfolgreich' "$(ajax -d action=novemberkind_produkte_save -d "nonce=$nonce" -d type=card -d "product_id=$product_id" -d "sku=$sku" \
  --data-urlencode 'motif=HTTP-Test 2' -d format=quer -d price=3 -d status=publish -d "image_id=$image_id")" 200
check 'gleiche ID nach Ändern' "$(json "d['data']['id']")" "$product_id"
check 'Ändern mit falscher Produktart abgelehnt' "$(ajax -d action=novemberkind_produkte_save -d "nonce=$nonce" -d type=sticker -d "product_id=$product_id" \
  -d motif=x -d finish=matt -d width=1 -d height=1 -d price=1)" 422

backup_url=$(curl -s -b "$JAR" "$APP/$product_id/" | grep -oP 'href="\K[^"]*admin-post\.php\?action=novemberkind_produkte_backup[^"]*inline=0[^"]*' | head -1 | sed 's/&#038;/\&/g; s/&amp;/\&/g')
check 'Sicherung im Formular verlinkt' "$([ -n "$backup_url" ] && echo ja)" ja
check 'Sicherung herunterladen' "$(curl -s -b "$JAR" -o "$TMP/backup.json" -w '%{http_code}' "$backup_url")" 200
check 'Sicherung enthält den alten Namen' "$(python3 -c "import json; print(json.load(open('$TMP/backup.json'))['product']['name'])")" 'Karte: HTTP-Test'
check 'Sicherung ohne Nonce abgelehnt' "$(curl -s -b "$JAR" -o /dev/null -w '%{http_code}' "$(sed 's/_wpnonce=[^&]*/_wpnonce=falsch/' <<<"$backup_url")")" 403
check 'Sicherung ohne Anmeldung nicht erreichbar' "$(curl -s -o /dev/null -w '%{http_code}' "$backup_url")" 400
bin/wp post list --post_type=novemberkind_backup --post_status=private --post_parent="$product_id" --format=ids | xargs -r bin/wp post delete --force >/dev/null
for id in $product_id $image_id; do bin/wp post delete "$id" --force >/dev/null; done

shop_url=$(bin/wp eval 'echo wc_get_page_permalink("shop");')
check 'Link „Zum Shop“ zeigt auf die Shopseite von WooCommerce' "$(curl -s -b "$JAR" "$APP/" | grep -F -c "href=\"$shop_url\" target=\"_blank\"")" 1

# Rabattaktionen
check 'Aktionen: Liste lädt' "$(curl -s -b "$JAR" -o /dev/null -w '%{http_code}' "$APP/aktionen/")" 200
check 'Aktionen: Formular lädt mit Nonce' "$(curl -s -b "$JAR" "$APP/aktionen/neu/" | grep -c 'var novemberkindFormulare = .*"nonce":"[a-f0-9]')" 1
check 'Aktionen: unbekannte Aktion liefert 404' "$(curl -s -b "$JAR" -o /dev/null -w '%{http_code}' "$APP/aktionen/999999/")" 404
check 'Aktionen: ohne Nonce abgelehnt' "$(ajax -d action=novemberkind_produkte_save_campaign -d nonce=falsch -d name=x)" 403
check 'Aktionen: Pflichtfehler liefert 422' "$(ajax -d action=novemberkind_produkte_save_campaign -d "nonce=$nonce" -d name=)" 422
# Ein einfaches Produkt, das gerade nicht reduziert ist, auch nicht durch eine von Hand angelegte Aktion
plain=$(bin/wp eval 'foreach (wc_get_products(["limit" => -1, "status" => "publish", "type" => "simple"]) as $p) { if (!$p->is_on_sale()) { echo $p->get_name(), "\t", get_permalink($p->get_id()); break; } }')
plain_name=${plain%%$'\t'*}
plain_url=${plain#*$'\t'}
# Ob die Karte eines Produkts in der Übersicht einen durchgestrichenen Preis zeigt
card_on_sale() {
  curl -s -b "$JAR" "$APP/" | awk -v name="data-name=\"$1\"" 'index($0, name) { found = 1 } found && /nkp-card__price/ { print (index($0, "<del") ? "ja" : "nein"); exit }'
}
today=$(bin/wp eval "echo wp_date('Y-m-d');")
tomorrow=$(bin/wp eval "echo wp_date('Y-m-d', time() + DAY_IN_SECONDS);")
check 'Aktionen: Speichern erfolgreich' "$(ajax -d action=novemberkind_produkte_save_campaign -d "nonce=$nonce" -d name=HTTP-Aktion -d percent=10 -d "start_date=$today" -d start_time=00:00 -d "end_date=$tomorrow" -d scope=all)" 200
campaign_id=$(grep -oP '"id":\K\d+' "$TMP/body")
check 'Aktionen: Übersicht zeigt den Aktionspreis durchgestrichen' "$(card_on_sale "$plain_name")" ja
check 'Aktionen: Produktseite im Shop zeigt den Aktionspreis' "$(curl -s "$plain_url" | grep -c '<del' | awk '$1 > 0 { print "ja" }')" ja
check 'Aktionen: Beenden erfolgreich' "$(ajax -d action=novemberkind_produkte_end_campaign -d "nonce=$nonce" -d "id=$campaign_id")" 200
check 'Aktionen: nach dem Ende wieder Normalpreis in der Übersicht' "$(card_on_sale "$plain_name")" nein
check 'Aktionen: beendete Aktion lässt sich nicht ändern' "$(ajax -d action=novemberkind_produkte_save_campaign -d "nonce=$nonce" -d "id=$campaign_id" -d name=x -d percent=5 -d "start_date=$today" -d start_time=00:00 -d "end_date=$tomorrow" -d scope=all)" 422
[ -n "$campaign_id" ] && bin/wp post delete "$campaign_id" --force >/dev/null

# Gutscheine
check 'Gutscheine: Liste lädt' "$(curl -s -b "$JAR" -o /dev/null -w '%{http_code}' "$APP/gutscheine/")" 200
check 'Gutscheine: Formular lädt' "$(curl -s -b "$JAR" -o /dev/null -w '%{http_code}' "$APP/gutscheine/neu/")" 200
check 'Gutscheine: ohne Nonce abgelehnt' "$(ajax -d action=novemberkind_produkte_save_coupon -d nonce=falsch -d code=X)" 403
check 'Gutscheine: Pflichtfehler liefert 422' "$(ajax -d action=novemberkind_produkte_save_coupon -d "nonce=$nonce" -d code=)" 422
check 'Gutscheine: Speichern erfolgreich' "$(ajax -d action=novemberkind_produkte_save_coupon -d "nonce=$nonce" -d code=HTTP-TEST -d kind=percent -d percent=10)" 200
coupon_id=$(grep -oP '"id":\K\d+' "$TMP/body")
check 'Gutscheine: Deaktivieren erfolgreich' "$(ajax -d action=novemberkind_produkte_toggle_coupon -d "nonce=$nonce" -d "id=$coupon_id" -d value=off)" 200
check 'Gutscheine: Status Entwurf' "$(bin/wp post get "$coupon_id" --field=post_status)" draft
[ -n "$coupon_id" ] && bin/wp post delete "$coupon_id" --force >/dev/null

echo
if [ "$failures" -eq 0 ]; then echo 'Alle HTTP-Tests bestanden.'; else echo "$failures HTTP-Test(s) fehlgeschlagen."; fi
exit $((failures > 0))
