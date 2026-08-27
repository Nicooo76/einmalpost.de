// Erstellen: verschlüsseln, senden, Link zeigen.
//
// Der Schlüssel wird an das Fragment des Links gehängt - hinter das #.
// Browser übertragen das Fragment grundsätzlich nicht an Server. Er verlässt
// diesen Rechner nur, wenn der Benutzer den Link selbst weitergibt.

'use strict';

(function () {
    // Was ein Absender hineinlegen darf. Muss zur Prüfung auf dem Server
    // passen (SecretStore::NUTZLAST_MAX_BYTES) - hier nur, damit der Fehler
    // schon vor dem Hochladen auffällt und nicht danach.
    var NUTZLAST_MAX = 16000000;

    // Kennzeichnet einen Link, der zusätzlich eine Passphrase braucht. Die
    // Anzeigeseite erkennt daran, dass sie fragen muss - und zwar BEVOR sie
    // abruft, denn ein Abruf verbraucht das Geheimnis.
    var PASSPHRASE_MARKE = 'p.';


    // Die Zustände stehen als Text in der Seite; nur diese wenigen Meldungen
    // entstehen im Skript. Sie hier zu halten ist kürzer, als für jede ein
    // verstecktes Element in beide Vorlagen zu schreiben.
    var TEXTE = {
        de: {
            keinInhalt: 'Bitte geben Sie einen Text ein oder wählen Sie eine Datei.',
            zuGross: function (h, m) { return 'Diese Datei ist ' + h + ' groß. Möglich sind ' + m + '.'; },
            zuGrossServer: function (m) { return 'Das war zu groß für den Server. Möglich sind ' + m + '.'; },
            zuViele: 'Zu viele Anfragen. Von Ihrem Anschluss wurden in kurzer Zeit sehr viele '
                + 'Links erzeugt. Versuchen Sie es in einer Stunde noch einmal.',
            abgelehnt: 'Der Server hat den Inhalt nicht angenommen.',
            fehlgeschlagen: 'Das Verschlüsseln ist fehlgeschlagen. Ihr Inhalt wurde nicht gesendet.',
            kopiert: 'KOPIERT',
            kopierenGeht: 'KOPIEREN NICHT MÖGLICH — BITTE VON HAND MARKIEREN',
            qrZeigen: 'ALS QR-CODE',
            qrVerstecken: 'QR-CODE AUSBLENDEN',
            qrFehler: 'Der QR-Code ließ sich nicht erzeugen. Der Link darüber gilt trotzdem.',
            qrBeschriftung: 'QR-Code mit dem Link',
            erzeugen: 'LINK ERZEUGEN',
            verschluesselt: 'WIRD VERSCHLÜSSELT …'
        },
        en: {
            keinInhalt: 'Please enter a text or choose a file.',
            zuGross: function (h, m) { return 'This file is ' + h + '. The limit is ' + m + '.'; },
            zuGrossServer: function (m) { return 'That was too large for the server. The limit is ' + m + '.'; },
            zuViele: 'Too many requests. A lot of links were created from your connection in a '
                + 'short time. Please try again in an hour.',
            abgelehnt: 'The server did not accept the content.',
            fehlgeschlagen: 'Encryption failed. Your content was not sent.',
            kopiert: 'COPIED',
            kopierenGeht: 'COPYING NOT POSSIBLE — PLEASE SELECT BY HAND',
            qrZeigen: 'AS QR CODE',
            qrVerstecken: 'HIDE QR CODE',
            qrFehler: 'The QR code could not be created. The link above is still valid.',
            qrBeschriftung: 'QR code containing the link',
            erzeugen: 'CREATE LINK',
            verschluesselt: 'ENCRYPTING …'
        }
    };

    var T = TEXTE[document.body.dataset.sprache === 'en' ? 'en' : 'de'];

    var formular = document.getElementById('formular');
    var eingabe = document.getElementById('geheimnis');
    var dateiFeld = document.getElementById('datei');
    var dateiInfo = document.getElementById('dateiInfo');
    var passphraseFeld = document.getElementById('passphrase');
    var ttlFeld = document.getElementById('ttl');
    var absenden = document.getElementById('absenden');
    var ergebnis = document.getElementById('ergebnis');
    var linkFeld = document.getElementById('link');
    var kopieren = document.getElementById('kopieren');
    var qrZeigen = document.getElementById('qrZeigen');
    var qrBereich = document.getElementById('qrBereich');
    var qrFlaeche = document.getElementById('qrFlaeche');
    var passphraseHinweis = document.getElementById('passphraseHinweis');
    var fehlerFeld = document.getElementById('fehler');

    function zeigeFehler(text) {
        fehlerFeld.textContent = text;
        fehlerFeld.hidden = false;
    }

    function versteckeFehler() {
        fehlerFeld.textContent = '';
        fehlerFeld.hidden = true;
    }

    function lesbareGroesse(bytes) {
        if (bytes < 1024) { return bytes + ' Byte'; }
        if (bytes < 1048576) { return (bytes / 1024).toFixed(1) + ' KB'; }

        return (bytes / 1048576).toFixed(1) + ' MB';
    }

    // --- Datei auswählen ------------------------------------------------

    dateiFeld.addEventListener('change', function () {
        versteckeFehler();

        var datei = dateiFeld.files && dateiFeld.files[0];

        if (!datei) {
            dateiInfo.hidden = true;
            eingabe.disabled = false;

            return;
        }

        dateiInfo.textContent = datei.name + ' — ' + lesbareGroesse(datei.size);
        dateiInfo.hidden = false;

        // Entweder Text oder Datei. Beides zugleich wäre eine zweite
        // Betriebsart, die niemand erwartet.
        eingabe.disabled = true;
        eingabe.value = '';

        if (datei.size > NUTZLAST_MAX) {
            zeigeFehler(T.zuGross(lesbareGroesse(datei.size), lesbareGroesse(NUTZLAST_MAX)));
        }
    });

    // --- Absenden -------------------------------------------------------

    formular.addEventListener('submit', async function (ereignis) {
        ereignis.preventDefault();
        versteckeFehler();

        var datei = dateiFeld.files && dateiFeld.files[0];
        var klartext = eingabe.value;
        var passphrase = passphraseFeld.value;

        if (!datei && klartext === '') {
            zeigeFehler(T.keinInhalt);
            return;
        }

        if (datei && datei.size > NUTZLAST_MAX) {
            zeigeFehler(T.zuGross(lesbareGroesse(datei.size), lesbareGroesse(NUTZLAST_MAX)));
            return;
        }

        absenden.disabled = true;
        absenden.textContent = datei ? T.verschluesselt : T.erzeugen;

        try {
            var verschluesselt = datei
                ? await einmalpost.verschluesseleDatei(
                    datei.name,
                    new Uint8Array(await datei.arrayBuffer()),
                    passphrase
                )
                : await einmalpost.verschluessele(klartext, passphrase);

            var antwort = await fetch('/api/create', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    payload: einmalpost.zuBase64Url(verschluesselt.payload),
                    ttl: parseInt(ttlFeld.value, 10)
                })
            });

            if (antwort.status === 429) {
                zeigeFehler(T.zuViele);
                return;
            }

            if (antwort.status === 413) {
                zeigeFehler(T.zuGrossServer(lesbareGroesse(NUTZLAST_MAX)));
                return;
            }

            if (!antwort.ok) {
                zeigeFehler(T.abgelehnt);
                return;
            }

            var daten = await antwort.json();
            var schluesselText = einmalpost.zuBase64Url(verschluesselt.schluessel);

            var link = window.location.origin + '/s/' + daten.id + '#'
                + (verschluesselt.mitPassphrase ? PASSPHRASE_MARKE : '') + schluesselText;

            // textContent, nie innerHTML.
            linkFeld.textContent = link;
            passphraseHinweis.hidden = !verschluesselt.mitPassphrase;
            qrBereich.hidden = true;
            ergebnis.hidden = false;

            // Der Klartext hat hier nichts mehr zu suchen.
            eingabe.value = '';
            passphraseFeld.value = '';
            dateiFeld.value = '';
        } catch (fehler) {
            zeigeFehler(T.fehlgeschlagen);
        } finally {
            absenden.disabled = false;
            absenden.textContent = T.erzeugen;
        }
    });

    // --- Kopieren und QR-Code -------------------------------------------

    kopieren.addEventListener('click', async function () {
        try {
            await navigator.clipboard.writeText(linkFeld.textContent);
            kopieren.textContent = T.kopiert;
        } catch (fehler) {
            kopieren.textContent = T.kopierenGeht;
        }
    });

    qrZeigen.addEventListener('click', function () {
        if (!qrBereich.hidden) {
            qrBereich.hidden = true;
            qrZeigen.textContent = T.qrZeigen;

            return;
        }

        try {
            // Der Code entsteht hier im Browser. Ein Dienst, der ihn
            // erzeugt, bekäme den Link samt Schlüssel zu sehen.
            var matrix = qr.erzeuge(linkFeld.textContent);

            while (qrFlaeche.firstChild) {
                qrFlaeche.removeChild(qrFlaeche.firstChild);
            }

            qrFlaeche.appendChild(qr.alsSvg(matrix, 240, T.qrBeschriftung));
            qrBereich.hidden = false;
            qrZeigen.textContent = T.qrVerstecken;
        } catch (fehler) {
            zeigeFehler(T.qrFehler);
        }
    });
})();
