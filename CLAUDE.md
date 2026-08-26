# einmalpost.de — Arbeitsrahmen

Dieses Dokument ist der verbindliche Rahmen für alle Sitzungen an diesem Projekt.
Spätere Sitzungen kennen den ursprünglichen Auftrag nicht mehr — sie kennen nur diese Datei.
Was hier steht, ist das Ergebnis einer Sicherheitsanalyse und **nicht verhandelbar**.
Wer von einem Punkt abweichen will, fragt vorher, statt es anders zu machen.

---

## 1. Was der Dienst tut

einmalpost.de überträgt ein Geheimnis genau einmal und vergisst es danach.

Der Absender tippt einen Text ein und bekommt einen Link. Wer diesen Link öffnet und den
Anzeigen-Knopf drückt, sieht den Inhalt genau einmal. Danach ist er unwiederbringlich weg.

---

## 2. Das Sicherheitsversprechen

**Der Server darf zu keinem Zeitpunkt den Klartext oder den Schlüssel sehen.**

Verschlüsselt und entschlüsselt wird ausschließlich im Browser. Der Schlüssel steht nur im
Fragment der Adresse — dem Teil hinter dem `#` — und wird von Browsern grundsätzlich nicht
an Server übertragen.

**Der Prüfstein für jede Entscheidung lautet:**
> Wenn jemand die Datenbank und den ganzen Server erbeutet, darf er nichts Lesbares finden.

Jede Änderung an diesem Projekt wird gegen diesen Satz gehalten. Wenn eine Änderung ihn
schwächt, wird sie nicht gebaut — auch dann nicht, wenn sie bequemer wäre.

---

## 3. Stack

- PHP 8.3+, **ohne Framework, ohne Build-Schritt**
- MariaDB (Plesk Obsidian), nginx
- Frontend: reines JavaScript mit WebCrypto, **keine Laufzeitabhängigkeiten, keine externen Ressourcen**
- Composer und npm ausschließlich für Entwicklungs- und Testwerkzeuge. **Nichts davon wird ausgeliefert.**
- Zielumgebung: Plesk-vhost, Entwicklung über VS Code Remote SSH

**Entwicklungsmaschine** (Stand 2026-08-26): PHP 8.5.9, MariaDB 12.3.2 (Homebrew, erreichbar
als Systemnutzer über den Unix-Socket), Composer 2.10, Node 22.22, GNU Make 3.81.

**Zielumgebung** (Stand 2026-08-26, geprüft, nicht angenommen): Plesk Obsidian auf
Ubuntu 24.04, nginx als Proxy vor Apache, **MariaDB 10.11**, PHP 8.3 über FPM.
`DELETE ... RETURNING` wurde dort im Funktionstest gegen eine temporäre Tabelle als
funktionierend nachgewiesen. Der Code wird gegen **PHP 8.3** als Mindestversion geprüft.

Servername, Pfade und Zugang stehen in `BETRIEB.local.md` und `deploy.local.mk` — beide
liegen außerhalb des Repositorys.

---

## 4. Kryptografie, exakt so

- **AES-256-GCM** über `crypto.subtle`. Niemals AES-CBC oder AES-CTR, niemals Verschlüsselung
  ohne Authentifizierung.
- **Schlüssel:** 256 Bit aus `crypto.getRandomValues`, als `raw` exportiert, base64url in das
  Fragment.
- **IV:** 12 Byte, pro Geheimnis frisch aus `crypto.getRandomValues`.
- **Geheimnis-ID:** `random_bytes(16)` in PHP, gespeichert als `BINARY(16)`, im Link base64url
  (22 Zeichen).

### Aufbau des payload

```
version(1) ‖ [salz(16)] ‖ iv(12) ‖ ciphertext ‖ tag(16)
```

- **Version 1:** ohne Passphrase, kein Salz.
- **Version 2:** mit Passphrase, 16 Byte Salz für die Ableitung.

Das Versionsbyte steht vorn, damit sich das Format erweitern lässt — und damit die
Anzeigeseite weiß, ob sie nach einer Passphrase fragen muss, **bevor** sie abruft.

### Aufbau des Klartexts, vor dem Verschlüsseln

```
typ(1) ‖ namenslaenge(2) ‖ name ‖ laenge(4) ‖ inhalt ‖ nullbytes
```

`typ` ist 0 für Text und 1 für eine Datei. Aufgefüllt wird mit Nullbytes bis zum nächsten
Vielfachen von **256 Byte**, mindestens 256. *Ohne das verrät die gespeicherte Länge, ob dort
ein Kennwort oder ein Zertifikat liegt.*

Der Kopf ist bei einem Text 7 Byte lang. Daraus folgt die Grenze der ersten Stufe:
**Klartexte bis 249 Byte sind an ihrer gespeicherten Länge nicht zu unterscheiden**; ab
250 Byte gilt die nächste Stufe.

**Jede Längenangabe wird beim Entpacken geprüft, nicht geglaubt.** Ist ein Wert größer als
der verfügbare Puffer, wird abgelehnt statt gelesen — sonst wird aus einem manipulierten
Längenfeld ein Lesezugriff über das Pufferende hinaus.

### Passphrase

Freiwillig. Ist eine gesetzt, wird der tatsächliche Schlüssel aus **beidem** abgeleitet:

```
schluessel = zufall(32) XOR PBKDF2-SHA256(passphrase, salz, 600.000 Runden)
```

Im Link steht weiterhin nur der Zufallsanteil, gekennzeichnet durch das Präfix `p.` im
Fragment. Wer den Link abfängt, hat ohne die Passphrase nichts; wer die Passphrase errät,
ohne den Link zu haben, ebenfalls.

600.000 Runden entsprechen der OWASP-Empfehlung für PBKDF2 mit SHA-256 und dauern auf einem
Telefon etwa eine Sekunde.

**Gefragt wird vor dem Abruf.** Würde erst abgerufen und dann gefragt, wäre das Geheimnis bei
einem Tippfehler verbraucht und unwiederbringlich weg.

### Größen

| | |
|---|---|
| Was ein Absender hineinlegen darf | **16 MB** (16.000.000 Byte), Text oder Datei |
| Höchstgröße des payload | 16.500.000 Byte, hart geprüft und als CHECK in der Datenbank |
| Warum nicht mehr | MariaDBs `max_allowed_packet` liegt bei 16 MiB; ein größerer payload ließe sich gar nicht erst schreiben |

## 5. Datenmodell — genau diese Spalten, keine weiteren

```sql
CREATE TABLE secrets (
  id          BINARY(16) NOT NULL PRIMARY KEY,
  payload     LONGBLOB   NOT NULL,
  expires_at  DATETIME   NOT NULL,
  KEY idx_expires (expires_at),
  CONSTRAINT payload_hoechstens_16m CHECK (LENGTH(payload) <= 16500000)
) ENGINE=InnoDB;
```

### Warum `MEDIUMBLOB` und nicht `VARBINARY(65536)`

Ursprünglich war `VARBINARY(65536)` vorgesehen. Das ist in MariaDB nicht anlegbar, und zwar
auf beiden Maschinen unterschiedlich nicht anlegbar (gemessen am 2026-08-26):

- **Entwicklungsmaschine** (12.3.2, `sql_mode` enthält `STRICT_TRANS_TABLES`):
  `ERROR 1074 — Column length too big for column 'payload' (max = 65532)`. Die Tabelle
  entsteht nicht.
- **Zielserver** (10.11.14, `sql_mode` **ohne** Strict): Die Anweisung wird angenommen und
  die Spalte still zu `mediumblob` umgewandelt. In der Produktion stünde damit eine andere
  Spalte, als das Repository behauptet.
- `VARBINARY(65532)` scheitert auf **beiden** an `ERROR 1118 — Row size too large`, weil das
  InnoDB-Zeilenlimit von 65535 Byte für die ganze Zeile gilt.

`MEDIUMBLOB` schreibt hin, was der Zielserver ohnehin daraus macht. Der `CHECK`-Constraint
hält die vorgegebene 64-KB-Grenze fest — jetzt in der Datenbank und nicht nur in PHP.
Geprüft: 65536 Byte werden angenommen, 65537 abgelehnt.

Der Payload kann strukturell ohnehin nie größer als **65.308 Byte** werden: 12 Byte IV +
höchstens 255 Blöcke à 256 Byte + 16 Byte Tag. Der nächste Block läge bei 65.564 Byte und
damit über der Grenze.

### `sql_mode` wird pro Verbindung gesetzt

Jede Datenbankverbindung setzt beim Aufbau `sql_mode = 'STRICT_ALL_TABLES'`.

Der Zielserver läuft **ohne** Strict-Modus. Gemessen: 70.000 Byte in eine `BLOB`-Spalte
ergaben dort 65.535 gespeicherte Byte — **stillschweigend, ohne Fehler**. Für diesen Dienst
hieße das ein beschädigt gespeichertes Geheimnis, dessen GCM-Prüfung beim Empfänger
fehlschlägt, während der Absender es für zugestellt hält. Mit `STRICT_ALL_TABLES` wird
derselbe INSERT abgelehnt.

Die Einstellung ist **verbindungslokal** und ändert nichts am Server — andere vhosts auf
derselben Maschine bleiben unberührt.

**Ausdrücklich verboten** sind `created_at`, `ip`, `user_agent`, `subject`, `filename`,
`view_count`, `referrer` und jede andere Spalte, die Rückschlüsse auf Personen oder
Zeitpunkte erlaubt.

> Was nicht gespeichert wird, kann niemand herausverlangen.

Das Rate-Limit bekommt eine **eigene Tabelle** mit einem HMAC der IP-Adresse (täglich
wechselnder Schlüssel aus der Konfiguration), einer Zählerspalte und einem Ablaufzeitpunkt
eine Stunde in der Zukunft. **Die IP selbst wird nirgends im Klartext gespeichert.**

---

## 6. Endpunkte

| Methode | Pfad | Verhalten |
|---|---|---|
| GET | `/` | Formular zum Erstellen. Statisch. |
| POST | `/api/create` | Nimmt `payload` (base64url) und `ttl` in Sekunden. Erlaubt sind **ausschließlich 3600, 86400 und 604800**; alles andere wird abgelehnt. Führt **nur ein INSERT** aus und gibt die ID zurück. |
| GET | `/s/{id}` | Liefert ein **statisches** HTML-Gerüst mit einem Knopf und **fasst die Datenbank überhaupt nicht an**. |
| POST | `/api/reveal` | Nimmt die ID, **verbraucht das Geheimnis atomar**, gibt den `payload` zurück. |

### Warum `/s/{id}` die Datenbank nicht anfassen darf

Das ist der Schutz gegen Vorschau-Bots. Slack, Teams und Microsoft Safe Links rufen Links
automatisch ab und würden das Geheimnis sonst verbrennen, bevor der Empfänger es sieht.
Diese Bots führen kein JavaScript aus und senden kein POST. Deshalb steht zwischen Link und
Klartext ein Knopf, und deshalb ist die GET-Route strikt statisch.

---

## 7. Der atomare Verbrauch

**Die kritischste Stelle im Projekt.**

```sql
DELETE FROM secrets
 WHERE id = ? AND expires_at > UTC_TIMESTAMP()
RETURNING payload;
```

MariaDB kann `DELETE ... RETURNING` und erledigt Lesen und Löschen in einem atomaren Schritt.

**Es gibt genau diesen einen Pfad.** Der ursprünglich als Rückfall vorgesehene Weg über
`START TRANSACTION` / `SELECT ... FOR UPDATE` / `DELETE` / `COMMIT` ist **ersatzlos
entfallen**, nachdem auf dem Zielserver (MariaDB 10.11.14) nachgewiesen wurde, dass
`DELETE ... RETURNING` dort funktioniert. Begründung: Ein Codepfad, der lokal nie ausgeführt
wird und erst in der Produktion zum ersten Mal zählt, ist an dieser Stelle nicht akzeptabel.

Wer diesen Dienst je auf eine Datenbank ohne `DELETE ... RETURNING` portiert, baut den
zweiten Pfad **zusammen mit einer zweiten, eigenständigen Atomaritätsprüfung** — der
Nebenläufigkeitstest läuft dann einmal je Pfad. Ein zweiter Pfad ohne eigenen Test ist
schlimmer als kein zweiter Pfad.

**Niemals ein `SELECT` und danach ein separates `DELETE` ohne Transaktion.** Dazwischen liegt
ein Fenster von Millisekunden, in dem zwei gleichzeitige Anfragen beide den Klartext bekommen
— und Mail-Gateways prüfen Links regelmäßig mehrfach und parallel.

---

## 8. Einheitliche Fehlerantwort

Für „gibt es nicht", „abgelaufen" und „bereits abgerufen" gilt:

- **derselbe Statuscode (404)**
- **derselbe Antworttext**
- **derselbe Codepfad**
- **dieselbe Datenbankabfrage**

Kein zusätzlicher Zweig, kein früher Ausstieg. Sonst verrät die Antwort, ob eine ID jemals
existiert hat. Auch eine gültige, aber unbekannte ID läuft durch dieselbe Abfrage wie eine
existierende — die Abfrage entscheidet, nicht ein `if` davor.

---

## 9. Frontend-Regeln

- Der entschlüsselte Klartext wird **ausschließlich über `textContent`** in ein `<pre>`
  geschrieben. `innerHTML` ist **im gesamten Projekt verboten**.
- Keine externen Skripte, kein CDN, keine Schriften von fremden Servern, keine Statistik,
  keine Einbettungen. *Jede externe Ressource könnte `location.href` samt Schlüssel auslesen.*
- **Keine Weiterleitungen auf `/s/{id}`** — Fragmente überleben Redirects.
- Bei fehlgeschlagener GCM-Prüfung gibt es einen Fehler und **keinen halb entschlüsselten
  Inhalt**.

---

## 10. HTTP-Kopfzeilen

### Von PHP gesetzt

```
Content-Security-Policy: default-src 'none'; script-src 'nonce-{zufall}' 'strict-dynamic'; style-src 'unsafe-inline'; connect-src 'self'; object-src 'none'; base-uri 'none'; frame-ancestors 'none'; form-action 'none'; require-trusted-types-for 'script'
Referrer-Policy: no-referrer
X-Content-Type-Options: nosniff
Permissions-Policy: (restriktiv — alles abschalten, was nicht gebraucht wird)
Cache-Control: no-store   (auf jeder Seite mit Nonce)
```

`default-src 'none'`, `connect-src 'self'` und **`frame-ancestors 'none'`** wurden nach der
Prüfsitzung (2026-08-26) ergänzt — Freigabe durch den Auftraggeber. `frame-ancestors 'none'`
schließt die Lücke, dass sich die Anzeigeseite in einen fremden Rahmen einbetten ließ: Ein
Angreifer konnte einen Empfänger dazu bringen, das Geheimnis unbeabsichtigt zu verbrennen
(Zusage 3 am Zweck, nicht am Wortlaut). `style-src 'unsafe-inline'` ist nötig, weil die
Vorlagen ein Inline-`<style>` tragen; `connect-src 'self'` erlaubt genau die eigenen Aufrufe
an `/api/*` und nichts sonst. Geprüft von `tests/e2e/einbettung.spec.js` (versucht die
Einbettung tatsächlich) und der Kopfzeilenprobe in `tests/e2e/csp.spec.js`.

### Von nginx über Plesk gesetzt — **nicht** von PHP

```
Strict-Transport-Security: max-age=63072000; includeSubDomains; preload
```

**HSTS wird auf nginx-Ebene über Plesk gesetzt und darf im PHP-Code nicht vorkommen.**
Setzen es beide, kommt die Kopfzeile doppelt an; wie ein Client zwei widersprüchliche
`Strict-Transport-Security`-Zeilen auflöst, ist nichts, worauf sich eine Sicherheitszusage
stützen darf. Es gilt: **genau eine Quelle, und das ist nginx.**

Daraus folgen zwei Prüfungen auf zwei Ebenen:

- **Hier, im Testlauf:** Die von PHP gesetzte Kopfzeilenliste enthält **kein**
  `Strict-Transport-Security`. Das ist eine Zusicherung über den Code und wird als Test
  geschrieben.
- **`make verify-live`, gegen die Produktion:** Die Kopfzeile kommt **genau einmal** an — nicht
  keinmal, nicht zweimal.

**Der Nonce ist pro Antwort frisch aus `random_bytes` und mindestens 128 Bit lang.**

**Jede Seite, die einen Nonce ausliefert, bekommt zusätzlich `Cache-Control: no-store`.**
Sonst entwertet eine zwischengespeicherte Seite die CSP durch Nonce-Wiederverwendung: Ein
Angreifer, der den Nonce einer ausgelieferten Seite kennt, kann ihn in einer erneut
ausgelieferten Kopie derselben Seite für eigenes Skript verwenden. Ein Nonce, der zweimal
gilt, ist kein Nonce.

---

## 11. Betrieb

- `payload` auf **64 KB** begrenzt, **serverseitig hart geprüft**
- Rate-Limit wie in Abschnitt 5 beschrieben
- Aufräumskript für abgelaufene Zeilen als **Cron**, zusätzlich ein **MariaDB-Event** als
  zweites Netz. Der Test zu Zusage 10 prüft den Zustand des Event-Schedulers und wird rot,
  solange er `OFF` ist — das Einschalten ist ein Betriebsschritt mit Freigabe, keine
  Codeänderung.
- Beim Abruf **zusätzlich `expires_at` prüfen**, damit eine übersehene Zeile trotzdem nicht
  ausgeliefert wird
- **Das nginx-Zugriffsprotokoll für diesen vhost muss abgeschaltet werden** — Betriebsschritt
  in der README
- **HSTS und TLS (Betriebsschritte in der README, nicht im Code):**
  1. HSTS-Schalter im Plesk-vhost aktiv — die Kopfzeile kommt von nginx, nie von PHP.
  2. OCSP-Anheftung (OCSP Stapling) im Plesk-vhost aktiv.
  3. Anmeldung bei `hstspreload.org` **erst nach grünem `make verify-live`**. Eine
     Preload-Eintragung ist praktisch nicht zurückzunehmen; wer sie vor dem Nachweis setzt,
     sperrt sich bei einem Fehler selbst aus.
- **MariaDB-Event-Scheduler:** Das Event als zweites Netz läuft nur, wenn
  `@@event_scheduler = ON` ist. Auf dem Zielserver stand er beim Aufsetzen (2026-08-26) auf
  `OFF`. Das Einschalten ist eine **serverweite** Einstellung (`my.cnf`) und braucht eine
  ausdrückliche Freigabe. Solange er aus ist, ist das zweite Netz eine Behauptung — der Test
  zu Zusage 10 prüft den Zustand des Schedulers deshalb mit und nimmt ihn nicht an.
- Zugangsdaten über `config.php` außerhalb des Web-Roots oder über Umgebungsvariablen —
  **niemals im Repository**

---

## 12. Oberfläche und Gestaltung

Die Technik stand und war abgenommen, bevor die Gestaltung dazukam. Diese Reihenfolge ist
der Grund, warum ein Gestaltungsfehler jetzt nur noch kosmetisch sein kann.

**Die oberste Regel: Keine Änderung an der Gestaltung darf eine der zwanzig Zusagen brechen.**
Wo Aussehen und Zusage kollidieren, gewinnt die Zusage — und es wird gefragt, nicht
entschieden.

### Getrennt wird nach Funktion und Aussehen, nicht nach Code und Design

| Im Repository | Nicht im Repository |
|---|---|
| Alle Vorlagen mit vollständiger Struktur und Klassennamen | `public/assets/theme.css` |
| `public/assets/theme-default.css` — schmucklos, aber vollständig bedienbar | Schriftdateien, Logo, Bildmaterial |
| Alle Texte, FAQ, Sicherheitsseite | |

Wer das Repository klont, bekommt einen **funktionierenden Dienst in Grau**. Das ist Absicht:
Die Selbstbetriebs-Möglichkeit gehört zum Sicherheitsversprechen, und ein Besucher muss die
ausgelieferte Seitenstruktur mit dem Repository vergleichen können.

`theme.css` und die Bilddateien kommen über `make deploy` auf den Server und stehen in
`.gitignore`. `make check-secrets` prüft, dass sie nie eingecheckt werden — und dass
`theme-default.css` umgekehrt immer im Repository bleibt.

### Was die Gestaltung nicht brechen darf

- **Die Vorlagen tragen keine Gestaltung.** Kein Farbwert, kein `<style>`-Block, kein
  `style`-Attribut. Sonst läge Aussehen im Repository, und es ließe sich nicht überschreiben.
- **Die FAQ-Aufklapper laufen ohne JavaScript.** `<details>` und `<summary>`, per CSS
  gestaltet. Kein Skript für ein Aufklappmenü.
- **Die Anzeigeseite bleibt karg.** Kopfbereich, Inhalt, Kopieren-Knopf, Hinweise,
  Fußbereich. Kein Werbetext, keine FAQ, keine zusätzlichen Skripte — dort steht der
  Klartext, und jedes weitere Element ist zusätzliche Angriffsfläche.
- **Keine externe Ressource, in keiner Datei.** Keine Schrift, kein Bild, kein Skript, kein
  `url()` auf einen fremden Host — auch nicht in `theme.css`. Die CSP mit `default-src 'none'`
  würde es ohnehin blockieren; der Punkt ist, dass es gar nicht erst versucht wird. Die
  einzige Ausnahme ist der Verweis auf `pixagentur.com` im Fußbereich, als gewöhnlicher Link
  ohne Zählparameter.
- **`innerHTML` bleibt verboten, auch in Theme-Code.** Der Klartext geht weiterhin
  ausschließlich über `textContent` in das `<pre>`.
- **Jede Kennung nur einmal je Seite.** `getElementById` liefert das erste Element; eine
  doppelte ID lässt das Skript in den falschen Knoten schreiben. Beim Bauen ist genau das
  passiert — der Sprunganker trug dieselbe Kennung wie der Behälter für den Klartext.
- **Kontrast messen, nicht schätzen.** Mindestens 4,5:1, in beiden Farbschemata. Im Zweifel
  gewinnt Lesbarkeit gegen die Vorlage.
- **Schriften liegen im eigenen Projekt.** Barlow und Barlow Condensed unter der SIL Open
  Font License 1.1, als woff2 auf Latin beschränkt.

### Farbschema

Folgt der Systemeinstellung über `prefers-color-scheme`. **Kein Umschalter** — der bräuchte
Speicher im Browser und damit Angriffsfläche, die für Kosmetik nicht eröffnet wird.

## 13. Die Zusagen

Daran wird dieses Projekt gemessen. Aus den ursprünglich zwanzig sind mit Passphrase,
Anhängen und QR-Code drei weitere geworden. Jede Zusage ist entweder durch einen Test abgedeckt oder
in `UEBERGABE.md` ausdrücklich als „noch nicht abgedeckt" markiert. Eine dritte Möglichkeit
gibt es nicht.

| # | Zusage |
|---|---|
| 1 | Der Schlüssel erreicht den Server nie |
| 2 | Der Klartext liegt nie in der Datenbank |
| 3 | Ein Geheimnis wird höchstens einmal ausgeliefert, auch bei gleichzeitigen Anfragen |
| 4 | `GET /s/{id}` verbraucht nichts und fragt die Datenbank nicht ab |
| 5 | Vorschau-Bots verbrennen nichts (Slackbot, Twitterbot, Outlook, curl, wget) |
| 6 | Falscher Schlüssel liefert keinen Teilklartext |
| 7 | Ein gekipptes Bit im `payload` lässt den Abruf fehlschlagen |
| 8 | Die drei Fehlerfälle sind byteweise und zeitlich ununterscheidbar |
| 9 | Abgelaufene Geheimnisse werden nicht ausgeliefert, auch ohne Cron |
| 10 | Der Aufräum-Cron löscht abgelaufene Zeilen; bei abgeschaltetem Cron übernimmt das MariaDB-Event |
| 11 | Auffüllen: Klartexte bis 249 Byte ergeben dieselbe Längenstufe; die gespeicherte Länge verrät nur die Blockstufe |
| 12 | Grenzwerte 0, 1, Maximum, Maximum+1 verhalten sich wie festgelegt — Maximum ist 16 MB Nutzlast |
| 13 | Umlaute, Emoji, Zeilenumbrüche, Tabulatoren, Nullbyte kommen unverändert zurück |
| 14 | XSS-Nutzlasten erscheinen als Text und führen nichts aus |
| 15 | Das Schema enthält exakt die vorgesehenen Spalten |
| 16 | Rate-Limit greift und läuft von selbst ab |
| 17 | Alle Kopfzeilen sind gesetzt; ein eingeschmuggeltes Fremdskript wird durch die CSP blockiert |
| 18 | Keine IP-Adresse im Klartext, weder in der Datenbank noch in einem Protokoll |
| 19 | Fehlerhafte Eingaben erzeugen keinen Fehler 500 und keine Ausnahme |
| 20 | Der Code enthält keines der verbotenen Muster |
| 21 | Eine Passphrase erreicht den Server nie und steht in keiner Adresse |
| 22 | Ein Anhang kommt Byte für Byte zurück, Name und Inhalt liegen nie im Klartext |
| 23 | Der QR-Code enthält denselben Link und entsteht im Browser |

### Pflichttestdaten für Zusage 13

Mindestens diese Eingaben müssen unverändert zurückkommen:

- `äöüÄÖÜß`
- `🔑👀🇩🇪`
- `\r\n` und `\n` gemischt
- Tabulatoren
- ein `\0` in der Mitte
- arabischer Text
- japanischer Text
- ein kombinierter Unicode-Buchstabe (Basiszeichen + kombinierendes Zeichen)
- eine Zeichenkette aus 1.000 gleichen Zeichen

---

## 14. Die Abnahme findet nicht hier statt

Diese Sitzung baut den Dienst und die Tests der ersten beiden Ebenen. **Die Abnahme erfolgt
in einer eigenen Sitzung mit einem separaten Prüfauftrag**, durch ein Modell, das diesen Code
nicht geschrieben hat und ihm nicht glaubt.

Diese Prüfsitzung wird **jede der zwanzig Zusagen einzeln absichtlich sabotieren** und dabei
messen, ob die hier gebauten Tests die Sabotage bemerken. Ein Test, der nach dem Ausbau der
Schutzmaßnahme, die er angeblich prüft, weiterhin grün bleibt, zählt als Fehlschlag — nicht
als Test.

Daraus folgt für die Arbeit hier:

- Tests werden so gebaut, dass sie **rot werden, wenn die Zusage bricht** — nicht so, dass sie
  grün bleiben, solange der Code sich normal verhält.
- `UEBERGABE.md` ist eine **Behauptungssammlung, keine Abnahme**. Sie hält fest, welcher Test
  welche Zusage abdeckt — und wo ausdrücklich nichts abgedeckt ist.
- Wo eine Abkürzung genommen wurde, wird sie benannt, statt kaschiert. Die Prüfsitzung findet
  sie ohnehin; sie ungenannt zu lassen, kostet nur Vertrauen.

---

## 15. Verbotene Muster

Diese Muster dürfen im Projekt nicht vorkommen. Zusage 20 wird durch einen Test geprüft, der
den Quelltext danach durchsucht.

- `innerHTML`, `outerHTML`, `document.write`, `eval`, `new Function`
- `uniqid()`, `mt_rand()`, `rand()`, `md5(time())` für IDs, Schlüssel oder Sicherheitsrelevantes
- AES-CBC, AES-CTR, jede Verschlüsselung ohne Authentifizierung
- `SELECT` gefolgt von separatem `DELETE` beim Abruf
- Speichern von IP-Adresse, User-Agent, Erstellungszeitpunkt oder Aufrufzähler
- Externe Skripte, CDN, Web-Fonts, Statistikdienste
- Frameworks, Build-Schritte, Laufzeitabhängigkeiten im Frontend
- Werbeaussagen wie „100 % sicher", „unknackbar", „militärische Verschlüsselung"
- Farbwerte, `<style>`-Blöcke oder `style`-Attribute **in den Vorlagen** (die Gestaltung
  gehört in die Stilvorlagen, siehe Abschnitt 12)
- Gestaltung in `theme-default.css` über Schwarz auf Weiß hinaus — die schmucklose Fassung
  bleibt schmucklos
- Doppelt vergebene Kennungen (`id`) auf einer Seite

---

## 16. Testgerüst

Das Gerüst steht, **bevor** der erste Fachcode entsteht.

| Ebene | Werkzeug | Wer schreibt sie |
|---|---|---|
| Einheit | PHPUnit 11 | diese Sitzung |
| Integration gegen **echte** MariaDB | PHPUnit | diese Sitzung |
| Browser | Playwright (Chromium, Firefox, WebKit) | diese Sitzung, Grundlauf |
| Statisch | PHPStan Level 9 | diese Sitzung |
| Beweisende Prüfungen, Angriffe, Mutationstest | — | eigene Prüfsitzung |

Die Integrationstests laufen **gegen eine echte MariaDB**, nicht gegen SQLite und nicht gegen
Attrappen. `DELETE ... RETURNING` und `FOR UPDATE` verhalten sich sonst anders, und genau
dieses Verhalten soll geprüft werden. Die Testdatenbank ist separat und wird vor jedem Lauf
frisch angelegt.

`make verify` führt der Reihe nach aus: PHPStan → Einheitstests mit Abdeckungsmessung →
Integrationstests → Playwright. Es bricht beim **ersten** Fehlschlag ab und liefert einen
Rückgabewert ungleich null. Es muss von Anfang an durchlaufen, auch mit null Tests.

`make verify-live` ist davon getrennt und läuft **gegen die laufende Produktion**. Es prüft,
was sich nur dort prüfen lässt — die tatsächlich ausgelieferten Kopfzeilen samt der Frage,
ob `Strict-Transport-Security` **genau einmal** ankommt, und dass das nginx-Zugriffsprotokoll
für den vhost aus ist. `verify-live` ist die Voraussetzung für die Preload-Anmeldung
(Abschnitt 11) und wird nie automatisch aus `verify` heraus aufgerufen.

---

## 17. Regeln beim Arbeiten

1. **Behaupte nie, ein Test sei grün, ohne ihn ausgeführt zu haben.** Zeig die tatsächliche
   Ausgabe.
2. **Jeder gefundene Fehler bekommt zuerst einen Test, der ihn reproduziert — rot — und
   danach die Korrektur.** Nicht umgekehrt.
3. **Keine Attrappen für das, was geprüft werden soll.** Die Datenbank im Integrationstest ist
   echt, der Browser im Browsertest ist echt.
4. **Keine übersprungenen Tests.** Wenn ein Test nicht zum Laufen zu bringen ist, wird das
   gesagt, statt ihn zu überspringen.
5. **Wenn ein Test schwer zu schreiben ist, weil der Code schlecht schneidbar ist, ändere den
   Code — nicht den Test.**

---

## 18. Sprache

Deutsch für Oberfläche, Dokumentation und Kommunikation. Code-Bezeichner auf Englisch.
