// Zusage 13: Umlaute, Emoji, Zeilenumbrüche, Tabulatoren, Nullbyte kommen
//            unverändert zurück.
// Zusage 11: Das Auffüllen ergibt Längenstufen.
// Zusage 14: XSS-Nutzlasten erscheinen als Text und führen nichts aus.

import { test, expect } from '@playwright/test';
import { db, erzeugeGeheimnis, zeigeAn } from './helfer.js';

// Die im Auftrag geforderten Testdaten, vollständig.
const PFLICHTDATEN = {
    'Umlaute': 'äöüÄÖÜß',
    'Emoji mit Flagge': '🔑👀🇩🇪',
    'Zeilenumbrueche gemischt': 'eins\r\nzwei\ndrei\r\nvier',
    'Tabulatoren': 'Spalte1\tSpalte2\t\tSpalte4',
    // Ein echtes Nullbyte, hier als Ausdruck erzeugt.
    'Nullbyte in der Mitte': 'davor' + String.fromCharCode(0) + 'danach',
    'Arabisch': 'مرحبا بالعالم، هذا نص سري',
    'Japanisch': 'これは秘密のメッセージです。パスワード：ひみつ',
    // Basiszeichen plus kombinierendes Zeichen, nicht die fertige Form.
    'Kombinierter Buchstabe': 'e' + String.fromCharCode(0x0301) + ' a' + String.fromCharCode(0x030A),
    'Tausend gleiche Zeichen': 'A'.repeat(1000)
};

test.describe('Inhalte kommen unveraendert zurueck', () => {
    /**
     * Die Kryptoschicht selbst, im echten Browser.
     *
     * Bewusst ohne den Umweg über das Eingabefeld: Ein <textarea> wandelt
     * CR-LF beim Auslesen in LF um - das ist eine Eigenschaft von HTML und
     * hätte mit der Verschlüsselung nichts zu tun. Geprüft wird hier, was
     * dieses Projekt zusichert.
     */
    for (const [name, text] of Object.entries(PFLICHTDATEN)) {
        test(`Zusage 13, Kryptoschicht: ${name}`, async ({ page }) => {
            await page.goto('/');

            const ergebnis = await page.evaluate(async (klartext) => {
                const verschluesselt = await window.einmalpost.verschluessele(klartext);

                // Der Weg über base64url ist derselbe wie im Betrieb.
                const kodiert = window.einmalpost.zuBase64Url(verschluesselt.payload);
                const zurueck = window.einmalpost.ausBase64Url(kodiert);

                const klar = await window.einmalpost.entschluessele(zurueck, verschluesselt.schluessel);

                return {
                    gleich: klar === klartext,
                    zurueck: klar,
                    laenge: verschluesselt.payload.length
                };
            }, text);

            expect(ergebnis.zurueck).toBe(text);
            expect(ergebnis.gleich).toBe(true);

            // payload = iv(12) + ciphertext + tag(16); der Klartextblock ist
            // ein Vielfaches von 256.
            expect((ergebnis.laenge - 28) % 256).toBe(0);
        });
    }

    /**
     * Und dieselben Daten über die volle Kette: Eingabefeld, Server,
     * Anzeigeseite. Ohne die Zeilenumbrüche, die das Eingabefeld selbst
     * verändert.
     */
    for (const [name, text] of Object.entries(PFLICHTDATEN)) {
        if (name === 'Zeilenumbrueche gemischt') {
            continue;
        }

        test(`Zusage 13, volle Kette: ${name}`, async ({ page }) => {
            await page.goto('/');

            // Direkt setzen statt tippen: Ein Nullbyte lässt sich nicht
            // eintippen, und die Daten sollen unverändert ankommen.
            await page.evaluate((wert) => {
                document.getElementById('geheimnis').value = wert;
            }, text);

            await page.click('#absenden');
            await page.waitForSelector('#ergebnis:not([hidden])');

            const link = await page.textContent('#link');
            const adresse = new URL(link);

            const anzeige = await zeigeAn(page, adresse.pathname + adresse.hash);

            expect(anzeige.erfolgreich).toBe(true);
            expect(anzeige.inhalt).toBe(text);
        });
    }

    test('Zeilenumbrueche ueber die volle Kette, so wie das Eingabefeld sie liefert', async ({ page }) => {
        await page.goto('/');

        await page.evaluate(() => {
            document.getElementById('geheimnis').value = 'eins\r\nzwei\ndrei';
        });

        // Was das Eingabefeld tatsächlich herausgibt - HTML normalisiert
        // CR-LF zu LF, bevor das Programm den Wert überhaupt sieht.
        const wieGeliefert = await page.evaluate(() => document.getElementById('geheimnis').value);

        await page.click('#absenden');
        await page.waitForSelector('#ergebnis:not([hidden])');

        const adresse = new URL(await page.textContent('#link'));
        const anzeige = await zeigeAn(page, adresse.pathname + adresse.hash);

        expect(anzeige.inhalt).toBe(wieGeliefert);
        expect(anzeige.inhalt).toContain('\n');
    });
});

test.describe('Zusage 11: Auffuellen', () => {
    test('Klartexte bis 252 Byte ergeben dieselbe Laengenstufe', async ({ page }) => {
        await page.goto('/');

        const laengen = await page.evaluate(async () => {
            const messen = async (anzahl) => {
                const verschluesselt = await window.einmalpost.verschluessele('A'.repeat(anzahl));

                return verschluesselt.payload.length;
            };

            return {
                eins: await messen(1),
                fuenf: await messen(5),
                zweihundertfuenfzig: await messen(250),
                zweihundertzweiundfuenfzig: await messen(252),
                zweihundertdreiundfuenfzig: await messen(253)
            };
        });

        // 4 Byte Längenfeld plus 252 Byte Klartext füllen genau einen Block.
        // Allem darunter ist die gespeicherte Länge nicht anzusehen - einem
        // Kennwort so wenig wie einem halben Absatz.
        const ersteStufe = 12 + 256 + 16;

        expect(laengen.eins).toBe(ersteStufe);
        expect(laengen.fuenf).toBe(ersteStufe);
        expect(laengen.zweihundertfuenfzig).toBe(ersteStufe);
        expect(laengen.zweihundertzweiundfuenfzig).toBe(ersteStufe);

        // Ein Byte mehr passt nicht mehr in den Block und liegt deshalb in
        // der nächsten Stufe. Auch das ist eine Stufe und keine verwertbare
        // Länge.
        expect(laengen.zweihundertdreiundfuenfzig).toBe(12 + 512 + 16);
    });

        test('jede Laenge landet auf einer Stufe von 256 Byte', async ({ page }) => {
        await page.goto('/');

        const stufen = await page.evaluate(async () => {
            const ergebnis = [];

            for (const anzahl of [1, 2, 100, 251, 252, 253, 300, 500, 508, 509, 764, 765, 1000]) {
                const verschluesselt = await window.einmalpost.verschluessele('A'.repeat(anzahl));
                ergebnis.push([anzahl, verschluesselt.payload.length]);
            }

            return ergebnis;
        });

        const gesehen = new Set();

        for (const [anzahl, laenge] of stufen) {
            expect((laenge - 28) % 256, `${anzahl} Byte ergaben ${laenge} Byte payload`).toBe(0);
            expect(laenge).toBeGreaterThanOrEqual(12 + 256 + 16);
            gesehen.add(laenge);
        }

        // Zwölf verschiedene Längen, aber nur eine Handvoll Stufen.
        expect(gesehen.size).toBeLessThanOrEqual(5);
    });

    test('die Stufe steht auch so in der Datenbank', async ({ page }) => {
        const kurz = await erzeugeGeheimnis(page, 'A'.repeat(5));
        const laenger = await erzeugeGeheimnis(page, 'A'.repeat(252));
        const naechsteStufe = await erzeugeGeheimnis(page, 'A'.repeat(253));

        // Gespeichert wird die Stufe, nicht die Länge: 5 und 252 Byte sind
        // in der Datenbank nicht auseinanderzuhalten.
        expect(db('laenge', kurz.id)).toBe(String(12 + 256 + 16));
        expect(db('laenge', kurz.id)).toBe(db('laenge', laenger.id));
        expect(db('laenge', naechsteStufe.id)).toBe(String(12 + 512 + 16));
    });
});

test.describe('Zusage 14: XSS', () => {
    const NUTZLASTEN = [
        '<script>window.geknackt = true;</script>',
        '<img src=x onerror="window.geknackt = true">',
        '<svg onload="window.geknackt=true">',
        '"><script>window.geknackt=1</script>',
        'javascript:window.geknackt=1',
        '<iframe src="javascript:window.geknackt=1"></iframe>',
        '<body onload=window.geknackt=1>',
        '<a href="#" onclick="window.geknackt=1">klick</a>'
    ];

    for (const nutzlast of NUTZLASTEN) {
        test(`erscheint als Text: ${nutzlast.slice(0, 28)}`, async ({ page }) => {
            const geheimnis = await erzeugeGeheimnis(page, nutzlast);
            const anzeige = await zeigeAn(page, geheimnis.pfad);

            // Zeichen für Zeichen derselbe Text.
            expect(anzeige.inhalt).toBe(nutzlast);

            // Und nichts davon wurde ausgeführt.
            expect(await page.evaluate(() => window.geknackt)).toBeUndefined();

            // Der Inhalt steht als Text im <pre>, nicht als Auszeichnung.
            const kindElemente = await page.locator('#inhalt *').count();
            expect(kindElemente, 'Im Anzeigefeld sind HTML-Elemente entstanden').toBe(0);
        });
    }
});
