// Erstellen: verschlüsseln, senden, Link zeigen.
//
// Der Schlüssel wird an das Fragment des Links gehängt - hinter das #.
// Browser übertragen das Fragment grundsätzlich nicht an Server. Er verlässt
// diesen Rechner nur, wenn der Benutzer den Link selbst weitergibt.

'use strict';

(function () {
    var formular = document.getElementById('formular');
    var eingabe = document.getElementById('geheimnis');
    var ttlFeld = document.getElementById('ttl');
    var absenden = document.getElementById('absenden');
    var ergebnis = document.getElementById('ergebnis');
    var linkFeld = document.getElementById('link');
    var kopieren = document.getElementById('kopieren');
    var fehlerFeld = document.getElementById('fehler');

    function zeigeFehler(text) {
        fehlerFeld.textContent = text;
        fehlerFeld.hidden = false;
    }

    function versteckeFehler() {
        fehlerFeld.textContent = '';
        fehlerFeld.hidden = true;
    }

    formular.addEventListener('submit', async function (ereignis) {
        ereignis.preventDefault();
        versteckeFehler();

        var klartext = eingabe.value;

        if (klartext === '') {
            zeigeFehler('Bitte geben Sie einen Text ein.');
            return;
        }

        absenden.disabled = true;

        try {
            var verschluesselt = await einmalpost.verschluessele(klartext);

            var antwort = await fetch('/api/create', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    payload: einmalpost.zuBase64Url(verschluesselt.payload),
                    ttl: parseInt(ttlFeld.value, 10)
                })
            });

            if (antwort.status === 429) {
                zeigeFehler(
                    'Zu viele Anfragen. Von Ihrem Anschluss wurden in kurzer Zeit sehr viele '
                    + 'Links erzeugt. Versuchen Sie es in einer Stunde noch einmal.'
                );
                return;
            }

            if (!antwort.ok) {
                zeigeFehler('Der Server hat den Text nicht angenommen.');
                return;
            }

            var daten = await antwort.json();

            var link = window.location.origin + '/s/' + daten.id
                + '#' + einmalpost.zuBase64Url(verschluesselt.schluessel);

            // textContent, nie innerHTML.
            linkFeld.textContent = link;
            ergebnis.hidden = false;

            // Der Klartext hat hier nichts mehr zu suchen.
            eingabe.value = '';
        } catch (fehler) {
            zeigeFehler('Das Verschlüsseln ist fehlgeschlagen. Ihr Text wurde nicht gesendet.');
        } finally {
            absenden.disabled = false;
        }
    });

    kopieren.addEventListener('click', async function () {
        try {
            await navigator.clipboard.writeText(linkFeld.textContent);
            kopieren.textContent = 'KOPIERT';
        } catch (fehler) {
            kopieren.textContent = 'KOPIEREN NICHT MÖGLICH — BITTE VON HAND MARKIEREN';
        }
    });
})();
