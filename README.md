# einmalpost

Überträgt ein Geheimnis genau einmal und vergisst es danach.

Der Absender tippt einen Text ein und bekommt einen Link. Wer diesen Link öffnet und den
Anzeigen-Knopf drückt, sieht den Inhalt ein einziges Mal. Im selben Moment löscht der Server
ihn.

**Der Server sieht zu keinem Zeitpunkt den Klartext oder den Schlüssel.** Verschlüsselt und
entschlüsselt wird ausschließlich im Browser. Der Schlüssel steht nur im Fragment der Adresse
— hinter dem `#` — und wird von Browsern grundsätzlich nicht an Server übertragen.

Der Prüfstein für jede Entscheidung in diesem Projekt:

> Wenn jemand die Datenbank und den ganzen Server erbeutet, darf er nichts Lesbares finden.

---

## Inhalt

- [Wie es funktioniert](#wie-es-funktioniert)
- [Was das Projekt nicht verspricht](#was-das-projekt-nicht-verspricht)
- [Aufbau](#aufbau)
- [Selbst betreiben](#selbst-betreiben)
- [Prüfen](#prüfen)
- [Betrieb](#betrieb)
- [Gestaltung](#gestaltung)
- [Sprachen](#sprachen)
- [Mitwirken](#mitwirken)
- [Lizenz](#lizenz)

---

## Wie es funktioniert

### Beim Erstellen, im Browser

1. Der Browser erzeugt einen zufälligen Schlüssel mit 256 Bit
   (`crypto.getRandomValues`).
2. Text oder Datei werden verpackt: `typ(1) ‖ namenslaenge(2) ‖ name ‖ laenge(4) ‖ inhalt`,
   dann mit Nullbytes bis zum nächsten Vielfachen von 256 Byte aufgefüllt. **Ohne das verrät
   die gespeicherte Länge, ob dort ein Kennwort oder ein Zertifikat liegt.**
3. Verschlüsselt wird mit **AES-256-GCM** über `crypto.subtle`. GCM erkennt nachträgliche
   Veränderungen: Ein einziges gekipptes Bit lässt die Entschlüsselung fehlschlagen, statt
   Unsinn zu liefern.
4. Gesendet wird `payload = version(1) ‖ [salz(16)] ‖ iv(12) ‖ ciphertext ‖ tag(16)`. Der
   Schlüssel wird an den Link gehängt, hinter das `#`.

**Anhänge** gehen denselben Weg wie Texte, bis 16 MB. Der Server sieht weder Inhalt noch
Dateinamen.

**Passphrase** (freiwillig): Ist eine gesetzt, wird der tatsächliche Schlüssel aus beidem
abgeleitet — `zufall(32) XOR PBKDF2-SHA256(passphrase, salz, 600.000 Runden)`. Im Link steht
weiterhin nur der Zufallsanteil, gekennzeichnet durch `p.` im Fragment. Wer den Link abfängt,
hat ohne die Passphrase nichts. Gefragt wird **vor** dem Abruf: Danach wäre der Inhalt bei
einem Tippfehler verbraucht.

**QR-Code**: im Browser erzeugt, ohne fremde Bibliothek (`public/assets/qr.js`, Byte-Modus,
Fehlerkorrektur M). Ein Dienst, der ihn erzeugte, bekäme den Link samt Schlüssel zu sehen.

### Auf dem Server

Gespeichert werden genau drei Angaben: eine zufällige Kennung (`random_bytes(16)`), der
Schlüsseltext und ein Ablaufzeitpunkt. **Kein Erstellungszeitpunkt, keine IP-Adresse, kein
Browserkennzeichen, kein Aufrufzähler.**

```sql
CREATE TABLE secrets (
  id          BINARY(16) NOT NULL PRIMARY KEY,
  payload     LONGBLOB   NOT NULL,
  expires_at  DATETIME   NOT NULL,
  KEY idx_expires (expires_at),
  CONSTRAINT payload_hoechstens_16m CHECK (LENGTH(payload) <= 16500000)
) ENGINE=InnoDB;
```

Die Grenze bleibt bewusst unter MariaDBs `max_allowed_packet` von 16 MiB — ein größerer
payload ließe sich gar nicht erst schreiben.

### Beim Abrufen

Gelesen und gelöscht wird in **einer** Anweisung:

```sql
DELETE FROM secrets
 WHERE id = ? AND expires_at > UTC_TIMESTAMP()
RETURNING payload;
```

Ein Lesen mit anschließendem Löschen hätte dazwischen ein Fenster von Millisekunden, in dem
zwei gleichzeitige Anfragen beide den Inhalt bekämen. Das ist kein theoretischer Fall:
Mail-Gateways prüfen Links regelmäßig mehrfach und parallel.

### Warum ein Knopf zwischen Link und Inhalt steht

`GET /s/{id}` liefert ein **statisches** Gerüst und fragt die Datenbank überhaupt nicht ab.
Erst ein `POST` nach dem Knopfdruck verbraucht das Geheimnis.

Der Grund: Slack, Teams und Microsoft Safe Links rufen Links automatisch ab, um eine Vorschau
zu erzeugen. Würde der Inhalt schon beim Öffnen ausgeliefert, wäre er verbrannt, bevor der
Empfänger ihn sieht. Diese Bots führen kein JavaScript aus und senden kein POST.

### Ein unvollständiger Link verbraucht nichts

Chat- und Mailprogramme kürzen lange Adressen beim Anzeigen. Die Kennung im Pfad ist dann oft
noch vollständig, der Schlüssel dahinter nicht. Deshalb prüft die Seite den Schlüssel **vor**
dem Abruf — genau 43 Zeichen base64url. Passt das nicht, wird `/api/reveal` gar nicht erst
aufgerufen, und das Geheimnis bleibt unversehrt.

---

## Was das Projekt nicht verspricht

Diese Liste gehört ausdrücklich dazu.

**Die Verschlüsselung läuft im Browser, aber das JavaScript dafür kommt vom Server.** Ein
manipulierter Server könnte Code ausliefern, der den Schlüssel zusätzlich mitschickt, und
niemand würde es beim Benutzen bemerken. Das gilt für **jeden** Dienst dieser Bauart. Wer
etwas anderes behauptet, hat entweder nicht nachgedacht oder rechnet damit, dass Sie es nicht
tun.

Dagegen hilft: den Quellcode lesen, was hier möglich ist (kein Bauschritt, keine
zusammengepressten Dateien, keine fremden Bibliotheken) — und einmalpost selbst betreiben.

Weiter **nicht** versprochen:

- dass ein gelöschter Inhalt physisch nicht mehr rekonstruierbar ist — Datenbanken und
  Dateisysteme geben Speicher verzögert frei;
- dass ein Angreifer mit Zugriff auf eines der beteiligten Geräte nichts findet: Der Link
  steht im Browserverlauf, der Inhalt möglicherweise in der Zwischenablage;
- Anonymität gegenüber dem Netzbetreiber.

Die Auffüllung verbirgt die Länge nicht, sie **vergröbert** sie: Klartexte bis 252 Byte sind
an der gespeicherten Länge nicht zu unterscheiden, ab 253 Byte gilt die nächste Stufe.

---

## Aufbau

Kein Framework, kein Bauschritt, keine Laufzeitabhängigkeit im Frontend. Composer und npm
sind ausschließlich Entwicklungswerkzeuge; **nichts davon wird ausgeliefert**.

```
public/            Dokumentenstamm
  index.php          einziger Einstiegspunkt, zugleich Router
  assets/
    krypto.js        AES-256-GCM, base64url, Auffüllen — die einzige Krypto-Stelle
    create.js        Formularseite
    reveal.js        Anzeigeseite, prüft den Schlüssel vor dem Abruf
    theme-default.css  schmucklose, vollständig bedienbare Fassung
src/               außerhalb des Dokumentenstamms
  autoload.php       eigener Autoloader, bewusst ohne Composer
  SecretStore.php    Anlegen, atomarer Verbrauch, Aufräumen
  RateLimiter.php    HMAC der Adresse mit täglich wechselndem Schlüssel
  Http/              Request, Response, Router, SecurityHeaders
  templates/         Layout und Seiten
config/            Zugangsdaten (nicht im Repository)
bin/cleanup.php    Aufräumskript für den Cron
db/                Schema und MariaDB-Event
tests/             Einheit, Integration, Browser
tools/             Prüfwerkzeuge
```

**Voraussetzungen:** PHP 8.3 oder neuer mit `pdo_mysql`, `mbstring` und `json`, MariaDB 10.5
oder neuer (wegen `DELETE ... RETURNING`), ein Webserver, der alles an `public/index.php`
weiterreicht.

---

## Selbst betreiben

```bash
git clone <adresse> einmalpost && cd einmalpost

# Zugangsdaten anlegen
cp config/config.example.php config/config.php
php -r 'echo base64_encode(random_bytes(32)), PHP_EOL;'   # Pepper erzeugen, eintragen

# Schema einspielen
mariadb -u benutzer -p meine_datenbank < db/schema.sql
mariadb -u benutzer -p meine_datenbank < db/event.sql     # zweites Aufräumnetz
```

Der Dienst braucht **kein** `composer install`, um zu laufen — `src/autoload.php` genügt.
Composer wird nur für die Tests gebraucht.

**Der Dokumentenstamm muss auf `public/` zeigen.** `src/`, `config/`, `bin/` und `db/` liegen
daneben und dürfen über HTTP nicht erreichbar sein. Alles, was keine vorhandene Datei ist,
geht an `public/index.php`:

```nginx
# nginx
location / { try_files $uri $uri/ /index.php$is_args$args; }
```

Für Apache liegt eine passende `.htaccess` bereits in `public/`.

Der Datenbankbenutzer braucht nur `SELECT`, `INSERT`, `UPDATE` und `DELETE`.

---

## Prüfen

```bash
composer install                        # PHPUnit 11, PHPStan 2
npm install && npx playwright install   # Chromium, Firefox, WebKit
make verify
```

Bei jedem Push und jedem Pull Request läuft derselbe Lauf auf GitHub
(`.github/workflows/verify.yml`) — bewusst **ohne** `theme.css` und Schriften, also gegen
genau die Fassung, die ein fremder Klon bekommt.

`make verify` führt der Reihe nach aus und bricht beim **ersten** Fehlschlag ab:

| Stufe | Umfang |
|---|---|
| PHPStan Level 9 | `src`, `public`, `bin`, `tools`, `tests` |
| Einheitstests | mit Abdeckungsmessung |
| Integrationstests | gegen eine **echte** MariaDB, nicht gegen SQLite oder Attrappen |
| Browsertests | drei Browser × helles und dunkles Farbschema, dazu ein Telefonprofil |

Die Testdatenbank wird vor jedem Lauf frisch angelegt; überschreibbar über `TEST_DB`,
`TEST_SOCKET`, `TEST_DB_USER`, `TEST_DB_PASS`. Für den Test des MariaDB-Events muss der
Event-Scheduler laufen (`SET GLOBAL event_scheduler = ON`) — sonst wird dieser Test rot, und
das ist Absicht: Ein Event, das nicht läuft, ist kein zweites Netz.

Weitere Ziele:

```bash
make coverage       # Abdeckung über beide PHP-Ebenen zusammen
make check-secrets  # durchsucht die GESAMTE Git-Historie nach Zugangsdaten
make verify-live LIVE_URL=https://…   # Kopfzeilen gegen eine laufende Installation
```

### Was die Tests absichern

Nicht nur, dass der Dienst funktioniert — sondern dass er **kaputtgeht, wenn eine Zusage
bricht**. Unter anderem:

- Ein Nebenläufigkeitstest startet acht echte Prozesse, die sich auf denselben Zeitpunkt
  verabreden. Genau einer darf das Geheimnis bekommen. Eine **Gegenprobe** mit absichtlich
  nicht atomarem Zugriff belegt, dass der Aufbau eine Mehrfachauslieferung überhaupt bemerken
  würde.
- `GET /s/{id}` läuft im Test gegen einen Datenbankzugang, der bei jeder Berührung eine
  Ausnahme wirft.
- Zehn Vorschau-Bot-Kennungen rufen die Anzeigeseite ab; danach muss das Geheimnis noch da
  sein.
- Die drei Fehlerfälle „gibt es nicht", „abgelaufen" und „bereits abgerufen" werden byteweise
  verglichen — und über den Sitzungszähler `Com_delete` wird nachgezählt, dass jeder Fall
  **genau eine** Datenbankanweisung auslöst.
- Ein Prüfer durchsucht den Quelltext nach verbotenen Mustern (`innerHTML`, `eval`, schwache
  Zufallsquellen, AES ohne Authentifizierung, `SELECT` gefolgt von separatem `DELETE`,
  externe Ressourcen, Werbeaussagen).
- Kontraste werden gerechnet, nicht geschätzt: mindestens 4,5:1 in beiden Farbschemata.

---

## Betrieb

### Protokollierung abschalten

**Verpflichtend.** Ein Zugriffsprotokoll hält IP-Adresse, Zeitpunkt und die aufgerufene
Kennung fest — also genau das, was dieser Dienst nicht speichert.

Prüfen Sie, wie viele Protokolle Ihr Aufbau schreibt. Steht nginx als Proxy vor Apache, sind
es **vier**, nicht eines. Auch das Fehlerprotokoll gehört geprüft: Apache schreibt dort
standardmäßig `[client A.B.C.D]`, und Module wie ModSecurity betten die Adresse in ihren
eigenen Meldungstext ein, den `ErrorLogFormat` nicht filtert.

### Kopfzeilen

Von der Anwendung gesetzt:

```
Content-Security-Policy: default-src 'none'; script-src 'nonce-{zufall}' 'strict-dynamic';
  style-src 'self' 'unsafe-inline'; font-src 'self'; img-src 'self' data:; connect-src 'self';
  object-src 'none'; base-uri 'none'; frame-ancestors 'none'; form-action 'none';
  require-trusted-types-for 'script'
Referrer-Policy: no-referrer
X-Content-Type-Options: nosniff
Permissions-Policy: (restriktiv)
Cache-Control: no-store       (auf jeder Seite mit Nonce)
X-Robots-Tag: noindex, nofollow   (auf /s/* und /api/*)
```

`Strict-Transport-Security` setzt die Anwendung **absichtlich nicht** — das gehört auf die
Webserver-Ebene. Setzen es beide, kommt die Kopfzeile doppelt an, und nach RFC 6797
verarbeitet ein Browser nur die zuerst gesendete. Der Fehler wäre unsichtbar wirksam.
`make verify-live` prüft deshalb, dass sie **genau einmal** ankommt.

### Schemaänderungen

**Ein Deploy spielt Dateien hoch, aber ändert keine Tabellen.** Das ist Absicht — eine
automatische Migration bei jedem Hochspielen ist genau dann gefährlich, wenn man sie am
wenigsten erwartet.

Migrationen liegen in `db/migrationen/` und werden von Hand eingespielt:

```bash
( echo "USE <datenbank>;"; cat db/migrationen/001-anhaenge-bis-16-mb.sql ) | plesk db
```

Sie sind so geschrieben, dass ein zweiter Lauf nichts kaputtmacht.

`make deploy` prüft anschließend, ob das Schema zum hochgespielten Stand passt, und bricht
ab, wenn nicht. Der Anlass ist ein echter Fehler: Nach der Umstellung auf Anhänge stand in
der Produktion noch die alte Spalte, und der erste große Anhang scheiterte mit einem
Serverfehler — den niemand sah, weil er auch nirgends protokolliert wurde.

Einzeln prüfen lässt sich das mit:

```bash
php tools/schema-pruefen.php
```

### PHP-Grenzen

Ein Anhang von 16 MB wird als base64url im JSON-Rumpf rund 22 MB groß. `post_max_size` muss
entsprechend hoch stehen — **und zwar in der FPM-Pool-Konfiguration, nicht in einer
`.user.ini`**: PHP liest die `.user.ini` erst, wenn der Rumpf bereits verworfen wurde.

Bei Plesk:

```bash
printf 'post_max_size = 32M\nmemory_limit = 256M\nmax_execution_time = 120\n' > /tmp/php.ini
plesk bin site --update-php-settings <domain> -settings /tmp/php.ini
systemctl reload plesk-php83-fpm
```

### Aufräumen

Zwei voneinander unabhängige Netze — ein Cron und das MariaDB-Event aus `db/event.sql`:

```
*/10 * * * *  /pfad/zu/php /pfad/zu/bin/cleanup.php >/dev/null
```

Unabhängig davon prüft der Abruf `expires_at` immer selbst mit. Eine übersehene Zeile wird
also auch dann nicht ausgeliefert, wenn beide Aufräumwege ausfallen.

### Rate-Limit

Begrenzt neu erzeugte Links je Anschluss und Stunde. Gespeichert wird **nicht die
IP-Adresse**, sondern ein HMAC-SHA256 mit einem Schlüssel, der täglich aus dem Pepper
abgeleitet wird. Nach einem Tageswechsel ist der Bezug zur Adresse auch rechnerisch nicht
mehr herstellbar.

---

## Gestaltung

Getrennt wird nicht zwischen Code und Design, sondern zwischen **Funktion und Aussehen**:

| Im Repository | Nicht im Repository |
|---|---|
| Alle Vorlagen mit vollständiger Struktur und Klassennamen | `public/assets/theme.css` |
| `public/assets/theme-default.css` — schmucklos, aber vollständig bedienbar | Schriften, Logo, Bildmaterial |
| Alle Texte, FAQ, Sicherheitsseite | |

**Wer dieses Repository klont, bekommt einen vollständig bedienbaren Dienst in Grau.** Das ist
Absicht: Die Selbstbetriebs-Möglichkeit gehört zum Sicherheitsversprechen, und ein Besucher
muss die ausgelieferte Seitenstruktur mit dem Repository vergleichen können. Alles, was das
**Verhalten** betrifft, ist einsehbar — nur das Aussehen nicht.

Wer eigene Gestaltung will, legt eine `public/assets/theme.css` an; sie wird nach der
schmucklosen Fassung geladen und überschreibt sie. Regeln, die dabei gelten:

- **Keine externe Ressource** — keine Schrift, kein Bild, kein `url()` auf einen fremden
  Host. Die CSP mit `default-src 'none'` blockiert es ohnehin.
- **Die Anzeigeseite bleibt karg.** Dort steht der Klartext; jedes weitere Element ist
  zusätzliche Angriffsfläche.
- **Die FAQ-Aufklapper laufen ohne JavaScript** (`<details>`/`<summary>`).
- **Jede Kennung nur einmal je Seite** — `getElementById` liefert sonst das falsche Element.
- **`innerHTML` bleibt verboten**, auch in Theme-Code.

Die mitgelieferte Fassung verwendet **Barlow** und **Barlow Condensed** unter der
[SIL Open Font License 1.1](https://scripts.sil.org/OFL), als woff2 auf Latin beschränkt und
vom eigenen Server ausgeliefert. Kein Google Fonts, keine fremde Anfrage.

---

## Sprachen

Deutsch unter `/`, Englisch unter `/en/`. Kein automatischer Sprachwechsel nach
Browsereinstellung: Wer einen Link bekommt, soll die Seite sehen, auf die der Link zeigt.

Auf `/s/*` gibt es **keinen** Sprachumschalter. Ein gewöhnlicher Link würde das Fragment
verlieren — und damit den Schlüssel.

Impressum und Datenschutz gibt es nur auf Deutsch: Sie gelten nach deutschem Recht, und eine
Übersetzung wäre eine unverbindliche Zweitfassung. Der englische Fußbereich verweist auf sie
und kennzeichnet das.

Beide Fassungen tragen **dieselben Kennungen** in derselben Struktur — die Skripte sind
geteilt, und ein Test vergleicht die `id`-Listen beider Seiten.

## Mitwirken

Der verbindliche Rahmen steht in [CLAUDE.md](CLAUDE.md): das Sicherheitsversprechen, die
Krypto-Festlegungen, das Datenmodell, zwanzig Zusagen und die Liste der verbotenen Muster.
**Wer etwas ändert, liest zuerst diese Datei.**

Vier Regeln, die nicht verhandelbar sind:

1. Kein Test gilt als grün, bevor er ausgeführt wurde.
2. Jeder gefundene Fehler bekommt **zuerst** einen Test, der ihn reproduziert — rot — und
   danach die Korrektur.
3. Keine Attrappen für das, was geprüft werden soll. Die Datenbank im Integrationstest ist
   echt, der Browser im Browsertest ist echt.
4. Keine übersprungenen Tests. Wer einen Test nicht zum Laufen bringt, sagt es, statt ihn zu
   überspringen.

`make verify` muss durchlaufen, bevor etwas hochgespielt wird — `make deploy` erzwingt das
und prüft zusätzlich die gesamte Git-Historie auf Zugangsdaten.

### Eine Schwachstelle melden

Bitte vor der Veröffentlichung melden: **info@pixagentur.com**

---

## Lizenz

**GNU Affero General Public License v3.0** — der vollständige Text steht in
[LICENSE](LICENSE).

Die AGPL ist hier keine beliebige Wahl. Sie verlangt, dass auch derjenige den Quellcode
herausgibt, der die Software **über ein Netzwerk anbietet**, statt sie zu verteilen. Genau
das ist der Fall, um den es hier geht: Wer einmalpost betreibt, liefert seinen Besuchern das
JavaScript aus, das ihre Geheimnisse verschlüsselt. Ob dieses JavaScript tut, was es soll,
lässt sich nur prüfen, wenn der Quellcode dazu offenliegt.

Damit sichert die Lizenz genau den Punkt ab, an dem dieses Projekt seine eigene Grenze
einräumt (siehe [Was das Projekt nicht verspricht](#was-das-projekt-nicht-verspricht)): Wer
eine veränderte Fassung betreibt, muss die Änderungen offenlegen.

Nicht unter dieser Lizenz stehen die Schriften — **Barlow** und **Barlow Condensed** von
Jeremy Tribby unter der [SIL Open Font License 1.1](https://scripts.sil.org/OFL) — sowie die
nicht mitgelieferte Gestaltung (`theme.css`, Logo, Bildmaterial).
