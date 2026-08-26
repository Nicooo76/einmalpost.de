// Anzeigen: abrufen, entschlüsseln, einmal zeigen.
//
// Die Seite selbst hat die Datenbank nicht angefasst. Erst ein Knopfdruck
// verbraucht das Geheimnis - deshalb können Vorschau-Bots hier nichts
// verbrennen.

'use strict';

(function () {
    // Ein Schlüssel sind 256 Bit, das sind als base64url ohne Auffüllzeichen
    // genau 43 Zeichen.
    var SCHLUESSEL_ZEICHEN = 43;

    // Erst nach dieser Zeit wird "Wird entschlüsselt …" eingeblendet. Sonst
    // blitzt der Hinweis nur auf.
    var LADEHINWEIS_AB_MS = 300;

    var abschnitte = [
        'bestaetigung', 'ergebnis', 'nurKopiertFertig', 'unvollstaendig',
        'fehlgeschlagen', 'fortgeschrieben', 'zuVieleAnfragen'
    ];

    var laedt = document.getElementById('laedt');
    var inhalt = document.getElementById('inhalt');
    var statuszeile = document.getElementById('statuszeile');
    var fehlerFeld = document.getElementById('fehler');
    var kopieren = document.getElementById('kopieren');

    function zeige(name) {
        for (var i = 0; i < abschnitte.length; i++) {
            var abschnitt = document.getElementById(abschnitte[i]);
            abschnitt.hidden = abschnitte[i] !== name;
        }
    }

    function zeigeFehler(text) {
        fehlerFeld.textContent = text;
        fehlerFeld.hidden = false;
    }

    function idAusAdresse() {
        var teile = window.location.pathname.split('/');

        return teile[teile.length - 1] || '';
    }

    function schluesselAusFragment() {
        // Das Fragment wird nie an den Server gesendet.
        return window.location.hash.replace(/^#/, '');
    }

    /**
     * Ist der Schlüssel überhaupt vollständig?
     *
     * Diese Prüfung entscheidet mehr, als sie aussieht: Chat- und
     * Mailprogramme kürzen lange Adressen beim Anzeigen. Die ID im Pfad ist
     * dann meist noch vollständig, der Schlüssel dahinter nicht. Wer trotzdem
     * abruft, löscht den Inhalt auf dem Server - und kann ihn danach nicht
     * entschlüsseln. Das Geheimnis wäre vernichtet, ohne dass es jemand
     * gelesen hat.
     */
    function schluesselIstVollstaendig(text) {
        return new RegExp('^[A-Za-z0-9_-]{' + SCHLUESSEL_ZEICHEN + '}$').test(text);
    }

    /**
     * Holt das Geheimnis und entschlüsselt es. Gibt den Klartext zurück oder
     * null, wenn ein Zustand bereits angezeigt wurde.
     */
    async function holeUndEntschluessele() {
        var schluesselText = schluesselAusFragment();

        // Vor dem Abruf, nicht danach.
        if (!schluesselIstVollstaendig(schluesselText)) {
            zeige('unvollstaendig');

            return null;
        }

        var anzeigen = setTimeout(function () {
            laedt.hidden = false;
        }, LADEHINWEIS_AB_MS);

        try {
            var antwort = await fetch('/api/reveal', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ id: idAusAdresse() })
            });

            if (antwort.status === 429) {
                zeige('zuVieleAnfragen');

                return null;
            }

            if (!antwort.ok) {
                // Ein Zustand für alle drei Fälle: gibt es nicht, abgelaufen,
                // schon abgerufen. Der Server unterscheidet sie nicht, und
                // diese Seite tut es auch nicht.
                zeige('fortgeschrieben');

                return null;
            }

            var daten = await antwort.json();
            var payload = einmalpost.ausBase64Url(daten.payload);
            var schluessel = einmalpost.ausBase64Url(schluesselText);

            return await einmalpost.entschluessele(payload, schluessel);
        } catch (fehler) {
            // Ab hier ist das Geheimnis auf dem Server bereits gelöscht -
            // der Abruf hat es verbraucht, auch wenn das Entschlüsseln
            // scheitert. Das sagt der Zustand auch deutlich.
            zeige('fehlgeschlagen');

            return null;
        } finally {
            clearTimeout(anzeigen);
            laedt.hidden = true;
        }
    }

    document.getElementById('anzeigen').addEventListener('click', async function () {
        var klartext = await holeUndEntschluessele();

        if (klartext === null) {
            return;
        }

        // textContent in ein <pre>, nie innerHTML. Eine XSS-Nutzlast im
        // Geheimnis erscheint dadurch als Text und führt nichts aus.
        inhalt.textContent = klartext;
        zeige('ergebnis');
    });

    document.getElementById('nurKopieren').addEventListener('click', async function () {
        var klartext = await holeUndEntschluessele();

        if (klartext === null) {
            return;
        }

        try {
            await navigator.clipboard.writeText(klartext);
            zeige('nurKopiertFertig');
        } catch (fehler) {
            // Die Zwischenablage war nicht erreichbar. Der Text darf jetzt
            // nicht verlorengehen - er ist auf dem Server schon gelöscht.
            // Also doch anzeigen, und sagen warum.
            inhalt.textContent = klartext;
            statuszeile.textContent = 'ANGEZEIGT UND GELÖSCHT';
            zeige('ergebnis');
            zeigeFehler(
                'Das Kopieren war nicht möglich, deshalb wird der Text angezeigt. '
                + 'Er wäre sonst verloren gewesen.'
            );
        }
    });

    kopieren.addEventListener('click', async function () {
        try {
            await navigator.clipboard.writeText(inhalt.textContent);
            kopieren.textContent = 'KOPIERT';
        } catch (fehler) {
            kopieren.textContent = 'KOPIEREN NICHT MÖGLICH — BITTE VON HAND MARKIEREN';
        }
    });

    document.getElementById('erneut').addEventListener('click', function () {
        window.location.reload();
    });
})();
