#!/usr/bin/env bash
#
# Statischer Verbotsprüfer. Durchsucht den ausgelieferten Code nach Mustern,
# die dort nicht vorkommen dürfen, und bricht mit Fehler ab, wenn er fündig
# wird.
#
# Gedacht als schneller Tripwire an zwei Stellen:
#   - als erster Schritt von "make verify"
#   - als Git-Hook vor jedem Commit (.git/hooks/pre-commit)
#
# Die gründliche Prüfung leistet tests/Unit/VerboteneMusterTest.php über den
# Zerteiler der Sprache selbst. Dieses Skript ist die grobe, kommentar-
# überspringende Vorstufe, die keine Testumgebung braucht.
#
# Ausnahmen werden NICHT per Kommentar im Code unterdrückt, sondern hier
# ausdrücklich benannt und am Ende gemeldet.

set -uo pipefail

cd "$(dirname "$0")/.." || exit 2

ORDNER=(src public bin db)
BEANSTANDET=0

# Volle-Zeile-Kommentare überspringen (//, --, #, *, /*). grep -rn stellt
# jeder Fundstelle "datei:zeile:" voran - der Kommentar-Anfang steht also
# hinter dem Zeilennummern-Doppelpunkt, nicht am Zeilenanfang. Inline-
# Kommentare bleiben absichtlich in der Prüfung: Code neben einem Kommentar
# wird geprüft.
KEIN_KOMMENTAR=':[0-9]+:[[:space:]]*(//|--|#|\*|/\*)'

# Sanktionierte Ausnahme: Die Client-Adresse wird an genau einer Stelle
# gelesen - in der Anfrage-Schicht, um sie an den HMAC des Rate-Limits zu
# geben. Sie wird nie gespeichert. Diese eine Datei ist erlaubt und wird unten
# gemeldet.
AUSNAHME_REMOTE_ADDR='src/Http/Request.php'

# Sanktionierte Ausnahme: Der XML-Namensraum für SVG sieht aus wie eine
# Adresse, ist aber eine Kennung. Kein Browser ruft ihn ab; er steht in der
# SVG-Spezifikation und ist für createElementNS zwingend. Die Ausnahme nennt
# genau diese eine Zeichenkette - jede andere http-Adresse, auch in derselben
# Datei, fällt weiterhin auf.
AUSNAHME_SVG_NS='http://www\.w3\.org/2000/svg'

pruefe() {
    local muster="$1" name="$2" erlaubt="${3:-}"
    local treffer
    # Nur Code. Lizenztexte, Schriftdateien und Bilder enthalten keine
    # ausführbaren Muster - die OFL etwa nennt in ihrem Text eine
    # http-Adresse, die nie abgerufen wird.
    treffer=$(grep -rnE "$muster" "${ORDNER[@]}" \
        --include="*.php" --include="*.js" --include="*.css" \
        --include="*.sql" --include="*.html" \
        2>/dev/null | grep -vE "$KEIN_KOMMENTAR")

    if [ -n "$erlaubt" ]; then
        treffer=$(echo "$treffer" | grep -v "$erlaubt")
    fi

    # Leere Zeilen aus den grep -v-Ketten entfernen.
    treffer=$(echo "$treffer" | grep -vE '^[[:space:]]*$' || true)

    if [ -n "$treffer" ]; then
        echo "  VERBOTEN: $name"
        echo "$treffer" | sed 's/^/      /'
        BEANSTANDET=1
    fi
}

echo "==> Statischer Verbotsprüfer"

# --- Gefährliche DOM-Zugriffe und Codeausführung ---
pruefe '\binnerHTML\b'         'innerHTML'
pruefe '\bouterHTML\b'         'outerHTML'
pruefe 'document[[:space:]]*\.[[:space:]]*write' 'document.write'
pruefe '(^|[^._a-zA-Z])eval[[:space:]]*\(' 'eval('
pruefe 'new[[:space:]]+Function[[:space:]]*\(' 'new Function('

# --- Schwache Zufalls-/Hashquellen ---
pruefe '\buniqid[[:space:]]*\('  'uniqid('
pruefe '\bmt_rand[[:space:]]*\(' 'mt_rand('
pruefe '(^|[^._a-zA-Z])rand[[:space:]]*\(' 'rand('
pruefe '\bmd5[[:space:]]*\('   'md5('
pruefe '\bsha1[[:space:]]*\('  'sha1('
pruefe '\bMath[[:space:]]*\.[[:space:]]*random' 'Math.random'

# --- Verschlüsselung ohne Authentifizierung ---
pruefe 'AES-CBC' 'AES-CBC'
pruefe 'AES-CTR' 'AES-CTR'

# --- Externe Ressourcen ---
pruefe 'http://'                'http://' "$AUSNAHME_SVG_NS"
pruefe '<script[^>]+src[[:space:]]*=[[:space:]]*["'"'"']https?://' 'externes <script src>'
pruefe 'cdn\.'                  'cdn.'
pruefe 'googleapis'            'googleapis'
pruefe 'fonts\.g'              'fonts.g (Google Fonts)'
pruefe 'cdnjs|unpkg\.com|jsdelivr' 'CDN'
pruefe 'googletagmanager|google-analytics|matomo|plausible|piwik' 'Statistikdienst'

# --- Daten, die nicht gespeichert werden dürfen ---
pruefe 'created_at'  'created_at (Erstellungszeitpunkt)'
pruefe 'user_agent|HTTP_USER_AGENT' 'user_agent'
pruefe 'REMOTE_ADDR' 'REMOTE_ADDR außerhalb der Anfrage-Schicht' "$AUSNAHME_REMOTE_ADDR"
pruefe 'HTTP_X_FORWARDED_FOR' 'X-Forwarded-For'

# --- Werbeaussagen ---
pruefe '100[[:space:]]*%[[:space:]]*sicher' 'Werbeaussage "100% sicher"'
pruefe 'unknackbar' 'Werbeaussage "unknackbar"'
pruefe 'milit(a|ä)r' 'Werbeaussage "militärisch"'

echo ""
echo "  Sanktionierte Ausnahmen (bewusst erlaubt, nicht unterdrückt):"
echo "    REMOTE_ADDR in $AUSNAHME_REMOTE_ADDR - die Adresse wird dort gelesen,"
echo "    um sie an den HMAC des Rate-Limits zu geben, und nie gespeichert."
echo "    Der SVG-Namensraum in public/assets/qr.js - eine XML-Kennung, die nie"
echo "    abgerufen wird, und für createElementNS zwingend."

echo ""
if [ "$BEANSTANDET" -ne 0 ]; then
    echo "Verbotene Muster gefunden. Commit/Lauf abgebrochen."
    exit 1
fi

echo "Keine verbotenen Muster im ausgelieferten Code."
exit 0
