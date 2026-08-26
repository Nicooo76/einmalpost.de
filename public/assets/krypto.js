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
    var BLOCK = 256;
    var LAENGENFELD = 4;

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
    // 4 Byte Länge als Big-Endian-uint32, dann die UTF-8-Bytes, dann
    // Nullbytes bis zum nächsten Vielfachen von 256 Byte, mindestens 256.
    //
    // Ohne das verrät die gespeicherte Länge, ob dort ein Passwort oder ein
    // Zertifikat liegt.

    function fuelleAuf(klartextBytes) {
        var brutto = LAENGENFELD + klartextBytes.length;
        var gesamt = Math.max(BLOCK, Math.ceil(brutto / BLOCK) * BLOCK);

        var gefuellt = new Uint8Array(gesamt); // bereits mit Nullbytes belegt
        new DataView(gefuellt.buffer).setUint32(0, klartextBytes.length, false);
        gefuellt.set(klartextBytes, LAENGENFELD);

        return gefuellt;
    }

    function entferneAuffuellung(gefuellt) {
        if (gefuellt.length < LAENGENFELD) {
            throw new Error('zu_kurz');
        }

        var sicht = new DataView(gefuellt.buffer, gefuellt.byteOffset, gefuellt.byteLength);
        var laenge = sicht.getUint32(0, false);

        // Das Längenfeld wird geprüft, nicht geglaubt. Ist der Wert größer
        // als der verfügbare Puffer, wird abgelehnt statt gelesen - sonst
        // wird aus einem manipulierten Längenfeld ein Lesezugriff über das
        // Pufferende hinaus.
        if (laenge > gefuellt.length - LAENGENFELD) {
            throw new Error('laengenfeld_unglaubwuerdig');
        }

        return gefuellt.subarray(LAENGENFELD, LAENGENFELD + laenge);
    }

    // --- Verschlüsseln und Entschlüsseln --------------------------------

    async function verschluessele(klartext) {
        fordereWebCrypto();

        // 256 Bit aus crypto.getRandomValues.
        var schluesselBytes = crypto.getRandomValues(new Uint8Array(SCHLUESSEL_BYTES));

        var schluessel = await crypto.subtle.importKey(
            'raw',
            schluesselBytes,
            { name: ALGORITHMUS },
            true,
            ['encrypt']
        );

        // Als raw exportiert - dieselben Bytes, die oben erzeugt wurden.
        var exportiert = new Uint8Array(await crypto.subtle.exportKey('raw', schluessel));

        var iv = crypto.getRandomValues(new Uint8Array(IV_BYTES));
        var gefuellt = fuelleAuf(new TextEncoder().encode(klartext));

        var schluesseltext = new Uint8Array(
            await crypto.subtle.encrypt({ name: ALGORITHMUS, iv: iv }, schluessel, gefuellt)
        );

        // payload = iv(12) ‖ ciphertext ‖ tag(16)
        // Das Tag hängt WebCrypto hinten an den Schlüsseltext an.
        var payload = new Uint8Array(IV_BYTES + schluesseltext.length);
        payload.set(iv, 0);
        payload.set(schluesseltext, IV_BYTES);

        return { payload: payload, schluessel: exportiert };
    }

    async function entschluessele(payload, schluesselBytes) {
        fordereWebCrypto();

        if (schluesselBytes.length !== SCHLUESSEL_BYTES) {
            throw new Error('schluessel_falsche_laenge');
        }

        // 12 Byte IV + mindestens das 16-Byte-Tag.
        if (payload.length < IV_BYTES + 16) {
            throw new Error('payload_zu_kurz');
        }

        var iv = payload.subarray(0, IV_BYTES);
        var schluesseltext = payload.subarray(IV_BYTES);

        var schluessel = await crypto.subtle.importKey(
            'raw',
            schluesselBytes,
            { name: ALGORITHMUS },
            false,
            ['decrypt']
        );

        // Schlägt die Prüfung des Tags fehl, wirft decrypt. Es gibt keinen
        // halb entschlüsselten Inhalt - weder bei falschem Schlüssel noch
        // bei einem gekippten Bit.
        var gefuellt = new Uint8Array(
            await crypto.subtle.decrypt({ name: ALGORITHMUS, iv: iv }, schluessel, schluesseltext)
        );

        return new TextDecoder('utf-8', { fatal: false }).decode(entferneAuffuellung(gefuellt));
    }

    return {
        zuBase64Url: zuBase64Url,
        ausBase64Url: ausBase64Url,
        fuelleAuf: fuelleAuf,
        entferneAuffuellung: entferneAuffuellung,
        verschluessele: verschluessele,
        entschluessele: entschluessele,
        BLOCK: BLOCK,
        IV_BYTES: IV_BYTES
    };
})();
