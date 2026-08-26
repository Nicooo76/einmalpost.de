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

    // Vorangestellt, wenn zusätzlich eine Passphrase nötig ist. Steht im
    // Fragment und erreicht den Server nie - die Anzeigeseite weiß dadurch
    // vor dem Abruf, dass sie fragen muss.
    var PASSPHRASE_MARKE = 'p.';

    // Erst nach dieser Zeit wird "Wird entschlüsselt …" eingeblendet. Sonst
    // blitzt der Hinweis nur auf.
    var LADEHINWEIS_AB_MS = 300;

    var abschnitte = [
        'bestaetigung', 'passphraseAbfrage', 'ergebnis', 'nurKopiertFertig',
        'unvollstaendig', 'fehlgeschlagen', 'fortgeschrieben', 'zuVieleAnfragen'
    ];

    var laedt = document.getElementById('laedt');
    var inhalt = document.getElementById('inhalt');
    var statuszeile = document.getElementById('statuszeile');
    var fehlerFeld = document.getElementById('fehler');
    var kopieren = document.getElementById('kopieren');
    var kopierZeile = document.getElementById('kopierZeile');
    var dateiErgebnis = document.getElementById('dateiErgebnis');
    var dateiName = document.getElementById('dateiName');
    var dateiLaden = document.getElementById('dateiLaden');
    var passphraseEingabe = document.getElementById('passphraseEingabe');

    // Merkt sich, was nach der Passphrase-Eingabe geschehen soll.
    var offenerWunsch = null;

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

    function fragmentInhalt() {
        // Das Fragment wird nie an den Server gesendet.
        return window.location.hash.replace(/^#/, '');
    }

    function brauchtPassphrase() {
        return fragmentInhalt().indexOf(PASSPHRASE_MARKE) === 0;
    }

    function schluesselAusFragment() {
        var roh = fragmentInhalt();

        return brauchtPassphrase() ? roh.slice(PASSPHRASE_MARKE.length) : roh;
    }

    /**
     * Ist der Schlüssel überhaupt vollständig?
     *
     * Diese Prüfung entscheidet mehr, als sie aussieht: Chat- und
     * Mailprogramme kürzen lange Adressen beim Anzeigen. Die Kennung im Pfad
     * ist dann meist noch vollständig, der Schlüssel dahinter nicht. Wer
     * trotzdem abruft, löscht den Inhalt auf dem Server - und kann ihn
     * danach nicht entschlüsseln. Das Geheimnis wäre vernichtet, ohne dass
     * es jemand gelesen hat.
     */
    function schluesselIstVollstaendig(text) {
        return new RegExp('^[A-Za-z0-9_-]{' + SCHLUESSEL_ZEICHEN + '}$').test(text);
    }

    /**
     * Holt das Geheimnis und entschlüsselt es.
     *
     * @return {Promise<object|null>} null, wenn bereits ein Zustand steht.
     */
    async function holeUndEntschluessele(passphrase) {
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

            return await einmalpost.entschluessele(payload, schluessel, passphrase);
        } catch (fehler) {
            // Ab hier ist das Geheimnis auf dem Server bereits gelöscht -
            // der Abruf hat es verbraucht, auch wenn das Entschlüsseln
            // scheitert. Das sagt der Zustand auch deutlich.
            var wegenPassphrase = brauchtPassphrase();

            document.getElementById('grundSchluessel').hidden = wegenPassphrase;
            document.getElementById('grundPassphrase').hidden = !wegenPassphrase;

            zeige('fehlgeschlagen');

            return null;
        } finally {
            clearTimeout(anzeigen);
            laedt.hidden = true;
        }
    }

    /**
     * Stellt das Ergebnis dar - Text im <pre>, Datei als Ladeknopf.
     */
    function stelleDar(ergebnis) {
        if (ergebnis.istDatei) {
            var blob = new Blob([ergebnis.bytes], { type: 'application/octet-stream' });

            dateiName.textContent = ergebnis.name || 'datei';
            dateiLaden.href = URL.createObjectURL(blob);
            dateiLaden.download = ergebnis.name || 'datei';
            dateiErgebnis.hidden = false;
            inhalt.hidden = true;
            kopierZeile.hidden = true;
        } else {
            // textContent in ein <pre>, nie innerHTML. Eine XSS-Nutzlast im
            // Geheimnis erscheint dadurch als Text und führt nichts aus.
            inhalt.textContent = ergebnis.text;
            inhalt.hidden = false;
            dateiErgebnis.hidden = true;
            kopierZeile.hidden = false;
        }

        zeige('ergebnis');
    }

    /**
     * Führt einen Wunsch aus - entweder sofort oder nach der Passphrase.
     */
    async function fuehreAus(wunsch, passphrase) {
        var ergebnis = await holeUndEntschluessele(passphrase);

        if (ergebnis === null) {
            return;
        }

        if (wunsch === 'anzeigen') {
            stelleDar(ergebnis);

            return;
        }

        // Kopieren, ohne anzuzeigen.
        if (ergebnis.istDatei) {
            // Eine Datei lässt sich nicht in die Zwischenablage legen.
            stelleDar(ergebnis);
            zeigeFehler('Das ist eine Datei — sie wird zum Herunterladen angeboten.');

            return;
        }

        try {
            await navigator.clipboard.writeText(ergebnis.text);
            zeige('nurKopiertFertig');
        } catch (fehler) {
            // Die Zwischenablage war nicht erreichbar. Der Text darf jetzt
            // nicht verlorengehen - er ist auf dem Server schon gelöscht.
            // Also doch anzeigen, und sagen warum.
            statuszeile.textContent = 'ANGEZEIGT UND GELÖSCHT';
            stelleDar(ergebnis);
            zeigeFehler(
                'Das Kopieren war nicht möglich, deshalb wird der Text angezeigt. '
                + 'Er wäre sonst verloren gewesen.'
            );
        }
    }

    /**
     * Nimmt einen Wunsch entgegen und fragt vorher nach der Passphrase,
     * falls der Link eine verlangt.
     */
    async function starte(wunsch) {
        fehlerFeld.hidden = true;

        if (!schluesselIstVollstaendig(schluesselAusFragment())) {
            zeige('unvollstaendig');

            return;
        }

        if (brauchtPassphrase()) {
            // Fragen, bevor abgerufen wird. Ein Abruf verbraucht das
            // Geheimnis, auch wenn die Passphrase danach nicht passt.
            offenerWunsch = wunsch;
            zeige('passphraseAbfrage');
            passphraseEingabe.focus();

            return;
        }

        await fuehreAus(wunsch, '');
    }

    document.getElementById('anzeigen').addEventListener('click', function () {
        starte('anzeigen');
    });

    document.getElementById('nurKopieren').addEventListener('click', function () {
        starte('kopieren');
    });

    document.getElementById('passphraseAbsenden').addEventListener('click', async function () {
        var passphrase = passphraseEingabe.value;

        if (passphrase === '') {
            zeigeFehler('Bitte geben Sie die Passphrase ein.');

            return;
        }

        passphraseEingabe.value = '';
        await fuehreAus(offenerWunsch || 'anzeigen', passphrase);
    });

    passphraseEingabe.addEventListener('keydown', function (ereignis) {
        if (ereignis.key === 'Enter') {
            ereignis.preventDefault();
            document.getElementById('passphraseAbsenden').click();
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
