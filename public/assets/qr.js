// QR-Code, im Browser erzeugt.
//
// Selbst geschrieben und nicht eingebunden: Die Sicherheitsseite sagt zu,
// dass dieses Projekt ohne fremde Bibliotheken auskommt. Eine eingebundene
// Bibliothek wäre genau das - und jede Zeile davon liefe auf derselben
// Seite, auf der Geheimnisse entschlüsselt werden.
//
// Umfang: Byte-Modus, Fehlerkorrektur M, Versionen 1 bis 10 (bis 213 Byte).
// Das deckt einen Link mit Kennung, Schlüssel und Passphrase-Markierung ab.
// Nach ISO/IEC 18004.

'use strict';

var qr = (function () {
    // --- Rechnen im Galois-Feld GF(256), Polynom 0x11D -------------------

    var EXP = new Uint8Array(512);
    var LOG = new Uint8Array(256);

    (function () {
        var x = 1;

        for (var i = 0; i < 255; i++) {
            EXP[i] = x;
            LOG[x] = i;
            x <<= 1;

            if (x & 0x100) {
                x ^= 0x11D;
            }
        }

        for (var j = 255; j < 512; j++) {
            EXP[j] = EXP[j - 255];
        }
    })();

    function mul(a, b) {
        return (a === 0 || b === 0) ? 0 : EXP[LOG[a] + LOG[b]];
    }

    /**
     * Erzeugerpolynom für n Fehlerkorrektur-Codewörter.
     */
    function erzeuger(n) {
        var poly = [1];

        for (var i = 0; i < n; i++) {
            var neu = new Array(poly.length + 1).fill(0);

            for (var j = 0; j < poly.length; j++) {
                neu[j] ^= poly[j];
                neu[j + 1] ^= mul(poly[j], EXP[i]);
            }

            poly = neu;
        }

        return poly;
    }

    /**
     * Reed-Solomon: die Fehlerkorrektur-Codewörter zu einem Datenblock.
     */
    function fehlerkorrektur(daten, anzahl) {
        var poly = erzeuger(anzahl);
        var rest = new Array(anzahl).fill(0);

        for (var i = 0; i < daten.length; i++) {
            var faktor = daten[i] ^ rest[0];
            rest.shift();
            rest.push(0);

            if (faktor !== 0) {
                for (var j = 0; j < anzahl; j++) {
                    rest[j] ^= mul(poly[j + 1], faktor);
                }
            }
        }

        return rest;
    }

    // --- Kennzahlen je Version, Fehlerkorrekturstufe M -------------------
    //
    // [Datencodewörter gesamt, EC-Codewörter je Block,
    //  Blöcke Gruppe 1, Wörter je Block Gruppe 1,
    //  Blöcke Gruppe 2, Wörter je Block Gruppe 2]
    var VERSIONEN = {
        1:  [16,  10, 1, 16, 0, 0],
        2:  [28,  16, 1, 28, 0, 0],
        3:  [44,  26, 1, 44, 0, 0],
        4:  [64,  18, 2, 32, 0, 0],
        5:  [86,  24, 2, 43, 0, 0],
        6:  [108, 16, 4, 27, 0, 0],
        7:  [124, 18, 4, 31, 0, 0],
        8:  [154, 22, 2, 38, 2, 39],
        9:  [182, 22, 3, 36, 2, 37],
        10: [216, 26, 4, 43, 1, 44]
    };

    // Mittelpunkte der Ausrichtungsmuster je Version.
    var AUSRICHTUNG = {
        1: [], 2: [6, 18], 3: [6, 22], 4: [6, 26], 5: [6, 30],
        6: [6, 34], 7: [6, 22, 38], 8: [6, 24, 42], 9: [6, 26, 46], 10: [6, 28, 50]
    };

    function versionFuer(laenge) {
        for (var v = 1; v <= 10; v++) {
            // 4 Bit Modus + 8 oder 16 Bit Länge + Daten, in Byte gerechnet.
            var laengenBits = v < 10 ? 8 : 16;
            var noetig = Math.ceil((4 + laengenBits + laenge * 8) / 8);

            if (noetig <= VERSIONEN[v][0]) {
                return v;
            }
        }

        throw new Error('zu_lang_fuer_qr');
    }

    // --- Daten zu Codewörtern -------------------------------------------

    function codewoerter(bytes, version) {
        var kennzahlen = VERSIONEN[version];
        var kapazitaet = kennzahlen[0];
        var laengenBits = version < 10 ? 8 : 16;

        var bits = [];

        function schreibe(wert, anzahl) {
            for (var i = anzahl - 1; i >= 0; i--) {
                bits.push((wert >> i) & 1);
            }
        }

        schreibe(0b0100, 4);              // Byte-Modus
        schreibe(bytes.length, laengenBits);

        for (var i = 0; i < bytes.length; i++) {
            schreibe(bytes[i], 8);
        }

        // Abschluss, höchstens vier Nullbits.
        var frei = kapazitaet * 8 - bits.length;
        schreibe(0, Math.min(4, frei));

        while (bits.length % 8 !== 0) {
            bits.push(0);
        }

        var daten = [];

        for (var b = 0; b < bits.length; b += 8) {
            var wert = 0;

            for (var k = 0; k < 8; k++) {
                wert = (wert << 1) | bits[b + k];
            }

            daten.push(wert);
        }

        // Auffüllen mit den vorgeschriebenen Wechselwerten.
        var fueller = [0xEC, 0x11];

        for (var f = 0; daten.length < kapazitaet; f++) {
            daten.push(fueller[f % 2]);
        }

        // In Blöcke teilen, je Block die Fehlerkorrektur rechnen.
        var ecAnzahl = kennzahlen[1];
        var bloecke = [];
        var ecBloecke = [];
        var versatz = 0;

        for (var g = 0; g < 2; g++) {
            var anzahlBloecke = kennzahlen[2 + g * 2];
            var woerterJeBlock = kennzahlen[3 + g * 2];

            for (var n = 0; n < anzahlBloecke; n++) {
                var block = daten.slice(versatz, versatz + woerterJeBlock);
                versatz += woerterJeBlock;
                bloecke.push(block);
                ecBloecke.push(fehlerkorrektur(block, ecAnzahl));
            }
        }

        // Verschränkt zusammensetzen: erst die Daten, dann die Prüfwörter.
        var ergebnis = [];
        var maxDaten = Math.max.apply(null, bloecke.map(function (b) { return b.length; }));

        for (var s = 0; s < maxDaten; s++) {
            for (var t = 0; t < bloecke.length; t++) {
                if (s < bloecke[t].length) {
                    ergebnis.push(bloecke[t][s]);
                }
            }
        }

        for (var u = 0; u < ecAnzahl; u++) {
            for (var w = 0; w < ecBloecke.length; w++) {
                ergebnis.push(ecBloecke[w][u]);
            }
        }

        return ergebnis;
    }

    // --- Die Matrix ------------------------------------------------------

    function leereMatrix(groesse) {
        var m = [];

        for (var i = 0; i < groesse; i++) {
            m.push(new Array(groesse).fill(null));
        }

        return m;
    }

    function setzeSuchmuster(m, zeile, spalte) {
        for (var i = -1; i <= 7; i++) {
            for (var j = -1; j <= 7; j++) {
                var z = zeile + i;
                var s = spalte + j;

                if (z < 0 || z >= m.length || s < 0 || s >= m.length) {
                    continue;
                }

                var innen = (i >= 0 && i <= 6 && (j === 0 || j === 6))
                    || (j >= 0 && j <= 6 && (i === 0 || i === 6))
                    || (i >= 2 && i <= 4 && j >= 2 && j <= 4);

                m[z][s] = innen ? 1 : 0;
            }
        }
    }

    function setzeAusrichtung(m, version) {
        var punkte = AUSRICHTUNG[version];

        for (var a = 0; a < punkte.length; a++) {
            for (var b = 0; b < punkte.length; b++) {
                var zeile = punkte[a];
                var spalte = punkte[b];

                // Nicht über die Suchmuster in den drei Ecken.
                if (m[zeile][spalte] !== null) {
                    continue;
                }

                for (var i = -2; i <= 2; i++) {
                    for (var j = -2; j <= 2; j++) {
                        var rand = Math.max(Math.abs(i), Math.abs(j));
                        m[zeile + i][spalte + j] = (rand !== 1) ? 1 : 0;
                    }
                }
            }
        }
    }

    function setzeTaktmuster(m) {
        for (var i = 8; i < m.length - 8; i++) {
            var wert = (i % 2 === 0) ? 1 : 0;

            if (m[6][i] === null) { m[6][i] = wert; }
            if (m[i][6] === null) { m[i][6] = wert; }
        }
    }

    /**
     * Formatinformation: 5 Bit Nutzdaten, auf 15 Bit mit BCH gesichert.
     */
    function formatBits(maske) {
        var daten = (0b00 << 3) | maske;   // 00 = Fehlerkorrekturstufe M
        var rest = daten << 10;

        for (var i = 14; i >= 10; i--) {
            if ((rest >> i) & 1) {
                rest ^= 0b10100110111 << (i - 10);
            }
        }

        return ((daten << 10) | rest) ^ 0b101010000010010;
    }

    function setzeFormat(m, maske) {
        var bits = formatBits(maske);
        var groesse = m.length;

        for (var i = 0; i < 15; i++) {
            // Das höchstwertige Bit zuerst: Die Spezifikation zählt den
            // 15-Bit-String von links, nicht von rechts.
            var bit = (bits >> (14 - i)) & 1;

            // Erste Kopie, um das linke obere Suchmuster.
            if (i < 6) {
                m[8][i] = bit;
            } else if (i < 8) {
                m[8][i + 1] = bit;
            } else if (i === 8) {
                m[7][8] = bit;
            } else {
                m[14 - i][8] = bit;
            }

            // Zweite Kopie, verteilt auf die anderen beiden Ecken.
            if (i < 8) {
                m[groesse - 1 - i][8] = bit;
            } else {
                m[8][groesse - 15 + i] = bit;
            }
        }

        // Immer dunkel, nach Spezifikation.
        m[groesse - 8][8] = 1;
    }

    function istReserviert(m, zeile, spalte) {
        return m[zeile][spalte] !== null;
    }

    function setzeDaten(m, daten, maske) {
        var groesse = m.length;
        var bitIndex = 0;
        var richtungHoch = true;

        for (var spalte = groesse - 1; spalte > 0; spalte -= 2) {
            // Die senkrechte Taktspalte wird übersprungen.
            if (spalte === 6) {
                spalte = 5;
            }

            for (var z = 0; z < groesse; z++) {
                var zeile = richtungHoch ? (groesse - 1 - z) : z;

                for (var d = 0; d < 2; d++) {
                    var s = spalte - d;

                    if (istReserviert(m, zeile, s)) {
                        continue;
                    }

                    var bit = 0;

                    if (bitIndex < daten.length * 8) {
                        bit = (daten[bitIndex >> 3] >> (7 - (bitIndex & 7))) & 1;
                    }

                    bitIndex++;

                    m[zeile][s] = bit ^ (maskeGilt(maske, zeile, s) ? 1 : 0);
                }
            }

            richtungHoch = !richtungHoch;
        }
    }

    function maskeGilt(maske, zeile, spalte) {
        switch (maske) {
            case 0: return (zeile + spalte) % 2 === 0;
            case 1: return zeile % 2 === 0;
            case 2: return spalte % 3 === 0;
            case 3: return (zeile + spalte) % 3 === 0;
            case 4: return (Math.floor(zeile / 2) + Math.floor(spalte / 3)) % 2 === 0;
            case 5: return ((zeile * spalte) % 2) + ((zeile * spalte) % 3) === 0;
            case 6: return (((zeile * spalte) % 2) + ((zeile * spalte) % 3)) % 2 === 0;
            default: return (((zeile + spalte) % 2) + ((zeile * spalte) % 3)) % 2 === 0;
        }
    }

    /**
     * Bewertung nach den vier Regeln der Spezifikation. Je kleiner, desto
     * besser lesbar - Scanner tun sich mit gleichförmigen Flächen schwer.
     */
    /**
     * Zählt die Stellen, an denen eine Folge einem Suchmuster gleicht.
     *
     * Je Stelle muss die **ganze** Folge zu einem der beiden Muster passen.
     * Wird stattdessen Position für Position gegen beide zugleich geprüft,
     * gilt auch eine Mischform als Treffer, obwohl sie keinem von beiden
     * entspricht - die Strafe fiele zu hoch aus und die Maskenwahl damit
     * systematisch schlechter.
     *
     * Steht als eigene Funktion da, weil sie sich nur so für sich prüfen
     * lässt: In der Gesamtbewertung überlagern die anderen drei Regeln den
     * Unterschied.
     */
    function zaehleSuchmuster(m) {
        var MUSTER = [1, 0, 1, 1, 1, 0, 1, 0, 0, 0, 0];
        var UMGEKEHRT = MUSTER.slice().reverse();
        var groesse = m.length;
        var treffer = 0;
        var i, j, k;

        for (i = 0; i < groesse; i++) {
            for (j = 0; j + 10 < groesse; j++) {
                var waagerechtVor = true;
                var waagerechtZurueck = true;
                var senkrechtVor = true;
                var senkrechtZurueck = true;

                for (k = 0; k < 11; k++) {
                    if (m[i][j + k] !== MUSTER[k]) { waagerechtVor = false; }
                    if (m[i][j + k] !== UMGEKEHRT[k]) { waagerechtZurueck = false; }
                    if (m[j + k][i] !== MUSTER[k]) { senkrechtVor = false; }
                    if (m[j + k][i] !== UMGEKEHRT[k]) { senkrechtZurueck = false; }
                }

                if (waagerechtVor || waagerechtZurueck) { treffer++; }
                if (senkrechtVor || senkrechtZurueck) { treffer++; }
            }
        }

        return treffer;
    }

    function bewerte(m) {
        var groesse = m.length;
        var punkte = 0;
        var i, j, k;

        // Regel 1: Reihen gleicher Farbe.
        for (var richtung = 0; richtung < 2; richtung++) {
            for (i = 0; i < groesse; i++) {
                var laufFarbe = -1;
                var laufLaenge = 0;

                for (j = 0; j < groesse; j++) {
                    var wert = richtung === 0 ? m[i][j] : m[j][i];

                    if (wert === laufFarbe) {
                        laufLaenge++;
                    } else {
                        if (laufLaenge >= 5) { punkte += laufLaenge - 2; }
                        laufFarbe = wert;
                        laufLaenge = 1;
                    }
                }

                if (laufLaenge >= 5) { punkte += laufLaenge - 2; }
            }
        }

        // Regel 2: gleichfarbige Zweierblöcke.
        for (i = 0; i < groesse - 1; i++) {
            for (j = 0; j < groesse - 1; j++) {
                var a = m[i][j];

                if (a === m[i][j + 1] && a === m[i + 1][j] && a === m[i + 1][j + 1]) {
                    punkte += 3;
                }
            }
        }

        // Regel 3: Muster, das einem Suchmuster ähnelt.
        punkte += 40 * zaehleSuchmuster(m);

        // Regel 4: Abweichung vom hälftigen Verhältnis hell zu dunkel.
        var dunkel = 0;

        for (i = 0; i < groesse; i++) {
            for (j = 0; j < groesse; j++) {
                if (m[i][j] === 1) { dunkel++; }
            }
        }

        var anteil = (dunkel * 100) / (groesse * groesse);
        punkte += Math.floor(Math.abs(anteil - 50) / 5) * 10;

        return punkte;
    }

    /**
     * Erzeugt die Matrix für einen Text. Rückgabe: Array von Zeilen mit 0/1.
     */
    function erzeuge(text, festeMaske) {
        var bytes = new TextEncoder().encode(text);
        var version = versionFuer(bytes.length);
        var daten = codewoerter(bytes, version);
        var groesse = 17 + version * 4;

        var beste = null;
        var bestePunkte = Infinity;

        var von = (typeof festeMaske === 'number') ? festeMaske : 0;
        var bis = (typeof festeMaske === 'number') ? festeMaske : 7;

        for (var maske = von; maske <= bis; maske++) {
            var m = leereMatrix(groesse);

            setzeSuchmuster(m, 0, 0);
            setzeSuchmuster(m, 0, groesse - 7);
            setzeSuchmuster(m, groesse - 7, 0);
            setzeAusrichtung(m, version);
            setzeTaktmuster(m);

            // Die Plätze der Formatinformation vorab belegen, damit die
            // Datenplatzierung sie überspringt.
            for (var i = 0; i < 9; i++) {
                if (m[8][i] === null) { m[8][i] = 0; }
                if (m[i][8] === null) { m[i][8] = 0; }
            }

            for (var j = 0; j < 8; j++) {
                if (m[8][groesse - 1 - j] === null) { m[8][groesse - 1 - j] = 0; }
                if (m[groesse - 1 - j][8] === null) { m[groesse - 1 - j][8] = 0; }
            }

            setzeDaten(m, daten, maske);
            setzeFormat(m, maske);

            var punkte = bewerte(m);

            if (punkte < bestePunkte) {
                bestePunkte = punkte;
                beste = m;
            }
        }

        return beste;
    }

    /**
     * Zeichnet die Matrix als SVG-Element.
     *
     * Kein innerHTML: Jedes Element entsteht über createElementNS, wie
     * überall in diesem Projekt.
     *
     * Die Textalternative kommt von außen: Der Kodierer weiß nicht, in
     * welcher Sprache die Seite steht, und ein fest deutscher Text stünde
     * sonst auch auf der englischen Fassung.
     */
    function alsSvg(matrix, kantenlaenge, textalternative) {
        var NS = 'http://www.w3.org/2000/svg';
        var rand = 4;                       // Ruhezone, nach Spezifikation
        var gesamt = matrix.length + rand * 2;

        var svg = document.createElementNS(NS, 'svg');
        svg.setAttribute('viewBox', '0 0 ' + gesamt + ' ' + gesamt);
        svg.setAttribute('width', String(kantenlaenge));
        svg.setAttribute('height', String(kantenlaenge));
        svg.setAttribute('role', 'img');
        svg.setAttribute('aria-label', textalternative || 'QR-Code mit dem Link');

        var grund = document.createElementNS(NS, 'rect');
        grund.setAttribute('width', String(gesamt));
        grund.setAttribute('height', String(gesamt));
        grund.setAttribute('fill', '#ffffff');
        svg.appendChild(grund);

        // Alle dunklen Felder in einem einzigen Pfad - das hält das Bild
        // klein und den Aufbau schnell.
        var pfadDaten = '';

        for (var zeile = 0; zeile < matrix.length; zeile++) {
            for (var spalte = 0; spalte < matrix.length; spalte++) {
                if (matrix[zeile][spalte] === 1) {
                    pfadDaten += 'M' + (spalte + rand) + ' ' + (zeile + rand) + 'h1v1h-1z';
                }
            }
        }

        var pfad = document.createElementNS(NS, 'path');
        pfad.setAttribute('d', pfadDaten);
        pfad.setAttribute('fill', '#000000');
        svg.appendChild(pfad);

        return svg;
    }

    /**
     * Die Reihenfolge, in der Datenbits gesetzt werden. Nur zur Prüfung:
     * Bit 0 muss nach Spezifikation unten rechts landen.
     */
    function _platzfolge(version) {
        var groesse = 17 + version * 4;
        var m = leereMatrix(groesse);

        setzeSuchmuster(m, 0, 0);
        setzeSuchmuster(m, 0, groesse - 7);
        setzeSuchmuster(m, groesse - 7, 0);
        setzeAusrichtung(m, version);
        setzeTaktmuster(m);

        for (var i = 0; i < 9; i++) {
            if (m[8][i] === null) { m[8][i] = 0; }
            if (m[i][8] === null) { m[i][8] = 0; }
        }

        for (var j = 0; j < 8; j++) {
            if (m[8][groesse - 1 - j] === null) { m[8][groesse - 1 - j] = 0; }
            if (m[groesse - 1 - j][8] === null) { m[groesse - 1 - j][8] = 0; }
        }

        var folge = [];
        var richtungHoch = true;

        for (var spalte = groesse - 1; spalte > 0; spalte -= 2) {
            if (spalte === 6) { spalte = 5; }

            for (var z = 0; z < groesse; z++) {
                var zeile = richtungHoch ? (groesse - 1 - z) : z;

                for (var d = 0; d < 2; d++) {
                    var sp = spalte - d;

                    if (m[zeile][sp] === null) {
                        folge.push([zeile, sp]);
                    }
                }
            }

            richtungHoch = !richtungHoch;
        }

        return folge;
    }

    return {
        erzeuge: erzeuge,
        _platzfolge: _platzfolge,
        alsSvg: alsSvg,
        versionFuer: versionFuer,
        // Für die Prüfung zugänglich.
        _bewerte: bewerte,
        _zaehleSuchmuster: zaehleSuchmuster,
        _fehlerkorrektur: fehlerkorrektur,
        _codewoerter: codewoerter,
        _formatBits: formatBits
    };
})();
