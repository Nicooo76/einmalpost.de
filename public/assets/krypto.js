// einmalpost - gemeinsame Bausteine für beide Seiten.
//
// Verschlüsselt und entschlüsselt wird ausschließlich hier, im Browser. Der
// Server sieht weder Klartext noch Schlüssel. Keine externen Skripte, keine
// Bibliotheken - jede fremde Ressource könnte location.href samt Schlüssel
// auslesen.

'use strict';

var einmalpost = (function () {
    // AES-256-GCM. Kein CBC, kein CTR, nichts ohne Authentifizierung: Ohne
    // Authentifizierung ließe sich der Schlüsseltext unbemerkt verändern.
    var ALGORITHMUS = 'AES-GCM';
    var SCHLUESSEL_BYTES = 32; // 256 Bit
    var IV_BYTES = 12;
    var SALZ_BYTES = 16;
    var BLOCK = 256;
    var LAENGENFELD = 4;

    // Aufbau des payload:  version(1) ‖ [salz(16)] ‖ iv(12) ‖ ciphertext ‖ tag(16)
    //
    // Version 1: ohne Passphrase, kein Salz.
    // Version 2: mit Passphrase, 16 Byte Salz für die Ableitung.
    //
    // Das Versionsbyte steht vorn, damit sich das Format erweitern lässt,
    // ohne alte Links zu brechen - und damit die Anzeigeseite weiß, ob sie
    // nach einer Passphrase fragen muss, BEVOR sie das Geheimnis abruft.
    var VERSION_OHNE_PASSPHRASE = 1;
    var VERSION_MIT_PASSPHRASE = 2;

    // Aufbau des aufgefüllten Klartexts:
    //   typ(1) ‖ namenslaenge(2) ‖ name ‖ laenge(4) ‖ inhalt ‖ nullbytes
    var TYP_TEXT = 0;
    var TYP_DATEI = 1;

    // Ableitung der Passphrase. 600.000 Runden entsprechen der Empfehlung
    // des OWASP für PBKDF2 mit SHA-256 (Stand 2023) und dauern auf einem
    // Telefon etwa eine Sekunde. Wer das für zu langsam hält, hat noch nie
    // zugesehen, wie schnell eine Grafikkarte kurze Passphrasen durchprobiert.
    var PBKDF2_RUNDEN = 600000;

    function fordereWebCrypto() {
        if (!window.isSecureContext) {
            throw new Error('unsicherer_kontext');
        }
        if (!window.crypto || !window.crypto.subtle) {
            throw new Error('kein_webcrypto');
        }
    }

    // --- base64url ------------------------------------------------------

    function zuBase64Url(bytes) {
        var zeichen = '';
        var schritt = 0x8000;

        for (var i = 0; i < bytes.length; i += schritt) {
            zeichen += String.fromCharCode.apply(
                null,
                bytes.subarray(i, Math.min(i + schritt, bytes.length))
            );
        }

        return btoa(zeichen).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
    }

    function ausBase64Url(text) {
        if (!/^[A-Za-z0-9_-]+$/.test(text)) {
            throw new Error('kein_base64url');
        }

        var normal = text.replace(/-/g, '+').replace(/_/g, '/');

        while (normal.length % 4 !== 0) {
            normal += '=';
        }

        var roh = atob(normal);
        var bytes = new Uint8Array(roh.length);

        for (var i = 0; i < roh.length; i++) {
            bytes[i] = roh.charCodeAt(i);
        }

        return bytes;
    }

    // --- Auffüllen ------------------------------------------------------
    //
    // Nullbytes bis zum nächsten Vielfachen von 256 Byte, mindestens 256.
    // Ohne das verrät die gespeicherte Länge, ob dort ein Kennwort oder ein
    // Zertifikat liegt.

    function fuelleAuf(nutzlast) {
        var gesamt = Math.max(BLOCK, Math.ceil(nutzlast.length / BLOCK) * BLOCK);
        var gefuellt = new Uint8Array(gesamt); // bereits mit Nullbytes belegt

        gefuellt.set(nutzlast, 0);

        return gefuellt;
    }

    /**
     * Packt Typ, Namen und Inhalt in einen Block.
     *
     * @param {number} typ TYP_TEXT oder TYP_DATEI
     * @param {string} name Dateiname, bei Text leer
     * @param {Uint8Array} inhalt
     */
    function packe(typ, name, inhalt) {
        var namensBytes = new TextEncoder().encode(name);

        if (namensBytes.length > 65535) {
            throw new Error('dateiname_zu_lang');
        }

        var kopf = 1 + 2 + namensBytes.length + LAENGENFELD;
        var block = new Uint8Array(kopf + inhalt.length);
        var sicht = new DataView(block.buffer);

        block[0] = typ;
        sicht.setUint16(1, namensBytes.length, false);
        block.set(namensBytes, 3);
        sicht.setUint32(3 + namensBytes.length, inhalt.length, false);
        block.set(inhalt, kopf);

        return block;
    }

    /**
     * Liest zurück, was packe() geschrieben hat.
     *
     * Jede Längenangabe wird gegen den tatsächlich vorhandenen Puffer
     * geprüft, nicht geglaubt. Ist ein Wert größer als das, was da ist,
     * wird abgelehnt statt gelesen - sonst wird aus einem manipulierten
     * Längenfeld ein Lesezugriff über das Pufferende hinaus.
     */
    function entpacke(gefuellt) {
        if (gefuellt.length < 1 + 2 + LAENGENFELD) {
            throw new Error('zu_kurz');
        }

        var sicht = new DataView(gefuellt.buffer, gefuellt.byteOffset, gefuellt.byteLength);
        var typ = gefuellt[0];

        if (typ !== TYP_TEXT && typ !== TYP_DATEI) {
            throw new Error('unbekannter_typ');
        }

        var namensLaenge = sicht.getUint16(1, false);

        if (namensLaenge > gefuellt.length - 3 - LAENGENFELD) {
            throw new Error('namenslaenge_unglaubwuerdig');
        }

        var name = new TextDecoder('utf-8', { fatal: false })
            .decode(gefuellt.subarray(3, 3 + namensLaenge));

        var laengeAb = 3 + namensLaenge;
        var laenge = sicht.getUint32(laengeAb, false);
        var inhaltAb = laengeAb + LAENGENFELD;

        if (laenge > gefuellt.length - inhaltAb) {
            throw new Error('laengenfeld_unglaubwuerdig');
        }

        return {
            typ: typ,
            name: name,
            inhalt: gefuellt.subarray(inhaltAb, inhaltAb + laenge)
        };
    }

    // --- Schlüssel ------------------------------------------------------

    /**
     * Leitet aus dem Schlüssel im Link und der Passphrase den tatsächlichen
     * Schlüssel ab.
     *
     * Beides zusammen: Wer den Link abfängt, hat ohne die Passphrase nichts,
     * und wer die Passphrase errät, ohne den Link zu haben, ebenfalls.
     */
    async function leiteAb(schluesselBytes, passphrase, salz) {
        var material = await crypto.subtle.importKey(
            'raw',
            new TextEncoder().encode(passphrase),
            'PBKDF2',
            false,
            ['deriveBits']
        );

        var abgeleitet = new Uint8Array(await crypto.subtle.deriveBits(
            { name: 'PBKDF2', salt: salz, iterations: PBKDF2_RUNDEN, hash: 'SHA-256' },
            material,
            SCHLUESSEL_BYTES * 8
        ));

        // Beide Anteile verbinden. Ein XOR genügt hier: Der eine Anteil ist
        // gleichverteilter Zufall, damit ist das Ergebnis mindestens so gut
        // wie der bessere von beiden.
        var verbunden = new Uint8Array(SCHLUESSEL_BYTES);

        for (var i = 0; i < SCHLUESSEL_BYTES; i++) {
            verbunden[i] = schluesselBytes[i] ^ abgeleitet[i];
        }

        return verbunden;
    }

    // --- Verschlüsseln und Entschlüsseln --------------------------------

    /**
     * @param {number} typ TYP_TEXT oder TYP_DATEI
     * @param {string} name Dateiname, bei Text leer
     * @param {Uint8Array} inhalt
     * @param {string} passphrase Leer, wenn keine gewünscht ist
     */
    async function verschluesseleRoh(typ, name, inhalt, passphrase) {
        fordereWebCrypto();

        // 256 Bit aus crypto.getRandomValues.
        var schluesselBytes = crypto.getRandomValues(new Uint8Array(SCHLUESSEL_BYTES));
        var mitPassphrase = typeof passphrase === 'string' && passphrase !== '';

        var salz = mitPassphrase
            ? crypto.getRandomValues(new Uint8Array(SALZ_BYTES))
            : new Uint8Array(0);

        var wirklicherSchluessel = mitPassphrase
            ? await leiteAb(schluesselBytes, passphrase, salz)
            : schluesselBytes;

        var schluessel = await crypto.subtle.importKey(
            'raw',
            wirklicherSchluessel,
            { name: ALGORITHMUS },
            false,
            ['encrypt']
        );

        var iv = crypto.getRandomValues(new Uint8Array(IV_BYTES));
        var gefuellt = fuelleAuf(packe(typ, name, inhalt));

        var schluesseltext = new Uint8Array(
            await crypto.subtle.encrypt({ name: ALGORITHMUS, iv: iv }, schluessel, gefuellt)
        );

        var version = mitPassphrase ? VERSION_MIT_PASSPHRASE : VERSION_OHNE_PASSPHRASE;
        var payload = new Uint8Array(1 + salz.length + IV_BYTES + schluesseltext.length);

        payload[0] = version;
        payload.set(salz, 1);
        payload.set(iv, 1 + salz.length);
        payload.set(schluesseltext, 1 + salz.length + IV_BYTES);

        // Der Schlüssel für den Link ist immer der zufällige Anteil - der
        // abgeleitete steht nirgends und entsteht bei jedem Abruf neu.
        return { payload: payload, schluessel: schluesselBytes, mitPassphrase: mitPassphrase };
    }

    async function verschluessele(klartext, passphrase) {
        return verschluesseleRoh(TYP_TEXT, '', new TextEncoder().encode(klartext), passphrase);
    }

    async function verschluesseleDatei(name, bytes, passphrase) {
        return verschluesseleRoh(TYP_DATEI, name, bytes, passphrase);
    }

    /**
     * Verlangt dieser payload eine Passphrase?
     *
     * Steht im ersten Byte und lässt sich lesen, ohne zu entschlüsseln.
     */
    function brauchtPassphrase(payload) {
        return payload.length > 0 && payload[0] === VERSION_MIT_PASSPHRASE;
    }

    async function entschluessele(payload, schluesselBytes, passphrase) {
        fordereWebCrypto();

        if (schluesselBytes.length !== SCHLUESSEL_BYTES) {
            throw new Error('schluessel_falsche_laenge');
        }

        if (payload.length < 1) {
            throw new Error('payload_zu_kurz');
        }

        var version = payload[0];

        if (version !== VERSION_OHNE_PASSPHRASE && version !== VERSION_MIT_PASSPHRASE) {
            throw new Error('unbekannte_version');
        }

        var salzLaenge = version === VERSION_MIT_PASSPHRASE ? SALZ_BYTES : 0;

        // 1 Byte Version + Salz + 12 Byte IV + mindestens das 16-Byte-Tag.
        if (payload.length < 1 + salzLaenge + IV_BYTES + 16) {
            throw new Error('payload_zu_kurz');
        }

        var salz = payload.subarray(1, 1 + salzLaenge);
        var iv = payload.subarray(1 + salzLaenge, 1 + salzLaenge + IV_BYTES);
        var schluesseltext = payload.subarray(1 + salzLaenge + IV_BYTES);

        var wirklicherSchluessel = version === VERSION_MIT_PASSPHRASE
            ? await leiteAb(schluesselBytes, typeof passphrase === 'string' ? passphrase : '', salz)
            : schluesselBytes;

        var schluessel = await crypto.subtle.importKey(
            'raw',
            wirklicherSchluessel,
            { name: ALGORITHMUS },
            false,
            ['decrypt']
        );

        // Schlägt die Prüfung des Tags fehl, wirft decrypt. Es gibt keinen
        // halb entschlüsselten Inhalt - weder bei falschem Schlüssel noch
        // bei falscher Passphrase noch bei einem gekippten Bit.
        var gefuellt = new Uint8Array(
            await crypto.subtle.decrypt({ name: ALGORITHMUS, iv: iv }, schluessel, schluesseltext)
        );

        var entpackt = entpacke(gefuellt);

        return {
            istDatei: entpackt.typ === TYP_DATEI,
            name: entpackt.name,
            bytes: entpackt.inhalt,
            text: entpackt.typ === TYP_TEXT
                ? new TextDecoder('utf-8', { fatal: false }).decode(entpackt.inhalt)
                : ''
        };
    }

    return {
        zuBase64Url: zuBase64Url,
        ausBase64Url: ausBase64Url,
        fuelleAuf: fuelleAuf,
        packe: packe,
        entpacke: entpacke,
        verschluessele: verschluessele,
        verschluesseleDatei: verschluesseleDatei,
        entschluessele: entschluessele,
        brauchtPassphrase: brauchtPassphrase,
        BLOCK: BLOCK,
        IV_BYTES: IV_BYTES,
        SALZ_BYTES: SALZ_BYTES,
        TYP_TEXT: TYP_TEXT,
        TYP_DATEI: TYP_DATEI,
        PBKDF2_RUNDEN: PBKDF2_RUNDEN
    };
})();
