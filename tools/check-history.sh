#!/usr/bin/env bash
#
# Durchsucht die GESAMTE Git-Historie nach Zugangsdaten - nicht nur den
# aktuellen Stand.
#
# Ein "git rm" entfernt eine Datei aus dem Arbeitsverzeichnis, nicht aus der
# Vergangenheit. Wer erst beim Push nachsieht, sieht zu spät nach.
#
# Aufruf: make check-secrets

set -uo pipefail

BEANSTANDET=0

echo "==> Wurden Zugangsdateien jemals erfasst?"
# Ein flacher Klon hat keine Historie. Die Prüfung liefe durch und meldete
# "sauber", ohne einen einzigen alten Commit gesehen zu haben - das wäre
# schlimmer als keine Prüfung, weil es wie eine bestandene aussieht.
if [ "$(git rev-parse --is-shallow-repository 2>/dev/null)" = "true" ]; then
    echo "  FEHLER: flacher Klon - die Historie ist hier gar nicht vorhanden."
    echo "          Vollständig holen (git fetch --unshallow) oder in der"
    echo "          fortlaufenden Prüfung fetch-depth: 0 setzen."
    exit 1
fi

TREFFER=$(git log --all --full-history --oneline \
    -- 'config/config.php' 'config/*.local.php' '.env' '.env.*' '*.pem' '*.key' 'id_rsa*' 2>/dev/null)

if [ -n "$TREFFER" ]; then
    echo "  BEANSTANDET - diese Commits enthalten Zugangsdateien:"
    echo "$TREFFER" | sed 's/^/    /'
    BEANSTANDET=1
else
    echo "  ok - keine"
fi

echo "==> Verdächtige Dateinamen in der gesamten Historie"
NAMEN=$(git rev-list --all --objects | awk '{print $2}' | sort -u \
    | grep -E '(^|/)config/config\.php$|\.env($|\.)|\.pem$|\.key$|id_rsa' | grep -v 'example' || true)

if [ -n "$NAMEN" ]; then
    echo "$NAMEN" | sed 's/^/    geprüft: /'
else
    echo "  ok - keine"
fi

echo "==> Inhalte aller Blobs (ausgefüllte Zugangsdaten, private Schlüssel)"
GEFUNDEN=""

while read -r BLOB; do
    [ "$(git cat-file -t "$BLOB" 2>/dev/null)" = "blob" ] || continue

    if git cat-file -p "$BLOB" 2>/dev/null | grep -qE "BEGIN [A-Z ]*PRIVATE KEY"; then
        GEFUNDEN="$GEFUNDEN $BLOB(privater Schlüssel)"
        continue
    fi

    # Gesucht wird nicht "lang", sondern "sieht nach Zufallsdaten aus":
    # mindestens 20 Zeichen aus dem base64- oder hex-Alphabet, ohne
    # Leerzeichen. Testwerte wie 'egal' oder '!!! kein base64 !!!' fallen
    # damit heraus, erzeugte Werte (base64_encode(...)) sind Funktionsaufrufe
    # und keine Literale. Beide Anführungszeichen werden berücksichtigt -
    # eine von PHP geschriebene Konfiguration nutzt doppelte.
    if git cat-file -p "$BLOB" 2>/dev/null \
        | grep -E "[\"']?(db_password|rate_pepper)[\"']?\s*=>\s*[\"'][A-Za-z0-9+/=_-]{20,}[\"']" \
        | grep -qv "BITTE-ERSETZEN"; then
        GEFUNDEN="$GEFUNDEN $BLOB"
    fi
done < <(git rev-list --all --objects | awk '{print $1}' | sort -u)

if [ -n "$GEFUNDEN" ]; then
    echo "  BEANSTANDET - Blobs mit ausgefüllten Zugangsdaten:"
    for B in $GEFUNDEN; do
        echo "    $B  ->  $(git rev-list --all --objects | grep "^${B%%(*}" | head -1 | cut -d' ' -f2-)"
    done
    BEANSTANDET=1
else
    echo "  ok - keine"
fi

echo "==> Greift die Suche überhaupt? (Gegenprobe an der echten config.php)"
if [ -f config/config.php ]; then
    if grep -qE "[\"']?rate_pepper[\"']?\s*=>\s*[\"'][A-Za-z0-9+/=_-]{20,}[\"']" config/config.php; then
        echo "  ok - eine echte Konfiguration würde erkannt"
    else
        echo "  BEANSTANDET - das Suchmuster erkennt die echte config.php nicht."
        BEANSTANDET=1
    fi
else
    echo "  übersprungen - keine lokale config.php vorhanden"
fi

echo "==> Liegen die Betriebsinterna außerhalb des Repositorys?"
# Servername, Pfade und der vhost-Benutzer. Letzterer ist zugleich der FTP-
# und SSH-Login - in einem öffentlichen Repository wäre er die halbe
# Zugangsangabe.
INTERNA=$(git ls-files -- 'deploy.local.mk' 'BETRIEB.local.md' 'PRUEFBERICHT.md' 'UEBERGABE.md' 2>/dev/null)

if [ -n "$INTERNA" ]; then
    echo "  BEANSTANDET - diese Dateien sind erfasst und gehören nicht ins Repository:"
    echo "$INTERNA" | sed 's/^/    /'
    BEANSTANDET=1
else
    echo "  ok - nicht erfasst"
fi

echo "==> Stehen Serverpfade oder Zugangsnamen im erfassten Stand?"
# Absolute vhost-Pfade, Plesk-Benutzernamen (name.tld_zufall) und öffentliche
# IP-Adressen. Beispieladressen aus RFC 5737 (192.0.2.x, 198.51.100.x,
# 203.0.113.x) sind für Dokumentation gedacht und deshalb erlaubt.
SPUREN=""

while read -r DATEI; do
    [ -f "$DATEI" ] || continue

    case "$DATEI" in
        *.woff2|*.png|*.jpg|*.ico|*.pdf) continue ;;
        # Der Prüfer enthält die gesuchten Muster selbst - sonst könnte er
        # nicht danach suchen.
        tools/check-history.sh) continue ;;
    esac

    if grep -qE '/var/www/vhosts|/home/[a-z0-9_-]+/public_html' "$DATEI" 2>/dev/null; then
        SPUREN="$SPUREN $DATEI(Serverpfad)"
    fi

    if grep -qE '[a-z0-9-]+\.[a-z]{2,4}_[a-z0-9]{8,}' "$DATEI" 2>/dev/null; then
        SPUREN="$SPUREN $DATEI(vhost-Benutzer)"
    fi

    # Vor der IP-Suche die Pfaddaten von SVG-Zeichen entfernen: Darin stehen
    # Zahlenfolgen wie "5.47 7.59.4.07", die einer Adresse gleichen, aber
    # Koordinaten sind. Zusätzlich muss jede Gruppe ein gültiges Oktett sein
    # (0-255) - "3.58 0 0 3.58" scheitert daran ohnehin.
    if sed -E 's/ d="[^"]*"//g' "$DATEI" 2>/dev/null \
        | grep -oE '\b((25[0-5]|2[0-4][0-9]|1?[0-9]?[0-9])\.){3}(25[0-5]|2[0-4][0-9]|1?[0-9]?[0-9])\b' \
        | grep -qvE '^(127\.|0\.0\.0\.0|255\.|192\.0\.2\.|198\.51\.100\.|203\.0\.113\.|10\.|172\.(1[6-9]|2[0-9]|3[01])\.|192\.168\.)'; then
        SPUREN="$SPUREN $DATEI(IP-Adresse)"
    fi
done < <(git ls-files)

if [ -n "$SPUREN" ]; then
    echo "  BEANSTANDET - Betriebsinterna im erfassten Stand:"
    for S in $SPUREN; do echo "    $S"; done
    BEANSTANDET=1
else
    echo "  ok - keine"
fi

echo "==> Liegt die Gestaltung außerhalb des Repositorys?"
# theme.css, Schriften und Bildmaterial gehören dem Betreiber, nicht dem
# Repository. Wer klont, bekommt die schmucklose Fassung - das ist Absicht
# und keine Nachlässigkeit.
GESTALTUNG=$(git ls-files -- 'public/assets/theme.css' 'public/assets/fonts/*' 'public/assets/img/*' 2>/dev/null)

if [ -n "$GESTALTUNG" ]; then
    echo "  BEANSTANDET - diese Dateien sind erfasst und gehören nicht ins Repository:"
    echo "$GESTALTUNG" | sed 's/^/    /'
    BEANSTANDET=1
else
    echo "  ok - nicht erfasst"
fi

echo "==> Wurde die Gestaltung jemals erfasst?"
FRUEHER=$(git log --all --full-history --oneline -- 'public/assets/theme.css' 'public/assets/fonts/*' 2>/dev/null)

if [ -n "$FRUEHER" ]; then
    echo "  BEANSTANDET - diese Commits enthalten die Gestaltung:"
    echo "$FRUEHER" | sed 's/^/    /'
    BEANSTANDET=1
else
    echo "  ok - nie erfasst"
fi

echo "==> Bleibt die schmucklose Fassung im Repository?"
if git ls-files --error-unmatch public/assets/theme-default.css >/dev/null 2>&1; then
    echo "  ok - theme-default.css ist erfasst"
else
    echo "  BEANSTANDET - theme-default.css fehlt im Repository. Wer klont, bekommt"
    echo "                dann eine unbedienbare Seite ohne jede Stilangabe."
    BEANSTANDET=1
fi

echo "==> Ist config/config.php ignoriert?"
if git check-ignore -q config/config.php 2>/dev/null; then
    echo "  ok"
else
    echo "  BEANSTANDET - config/config.php steht nicht in .gitignore."
    BEANSTANDET=1
fi

echo
if [ "$BEANSTANDET" -ne 0 ]; then
    echo "Es gibt Beanstandungen. NICHT pushen, bevor sie geklärt sind."
    echo "Eine einmal veröffentlichte Zugangsangabe gilt als verbrannt und muss"
    echo "gewechselt werden - sie aus der Historie zu entfernen genügt nicht."
    exit 1
fi

echo "Die Historie ist sauber."
exit 0
