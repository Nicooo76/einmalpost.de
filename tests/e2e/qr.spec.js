// Der QR-Code muss lesbar sein, nicht nur hübsch.
//
// Geprüft wird auf drei Ebenen: die Zwischenschritte gegen bekannte Werte
// aus ISO/IEC 18004, die Struktur der Matrix, und zuletzt das Entscheidende
// - ein echter Barcode-Leser bekommt den Link zurück, den wir hineingegeben
// haben. Ein selbst geschriebener Kodierer ohne diesen Test wäre eine
// Behauptung.

import { test, expect } from '@playwright/test';
import { erzeugeGeheimnis } from './helfer.js';

test.describe('QR-Kodierer', () => {
    test('die Formatinformation stimmt mit der Spezifikation überein', async ({ page }) => {
        await page.goto('/');

        // Die 8 Werte für Fehlerkorrekturstufe M, Masken 0 bis 7, sind in
        // ISO/IEC 18004 Tabelle C.1 festgeschrieben.
        const erwartet = [
            0b101010000010010, 0b101000100100101, 0b101111001111100, 0b101101101001011,
            0b100010111111001, 0b100000011001110, 0b100111110010111, 0b100101010100000
        ];

        const berechnet = await page.evaluate(
            () => Array.from({ length: 8 }, (_, m) => window.qr._formatBits(m))
        );

        expect(berechnet).toEqual(erwartet);
    });

    test('die Fehlerkorrektur stimmt mit einem bekannten Vektor überein', async ({ page }) => {
        await page.goto('/');

        // Bekannter Testfall: 16 Datencodewörter, 10 Prüfwörter (Version 1,
        // Stufe M). Die Rechnung ist unabhängig davon, wie die Daten
        // entstanden sind.
        const ergebnis = await page.evaluate(() => window.qr._fehlerkorrektur(
            [32, 91, 11, 120, 209, 114, 220, 77, 67, 64, 236, 17, 236, 17, 236, 17], 10
        ));

        expect(ergebnis).toEqual([196, 35, 39, 119, 235, 215, 231, 226, 93, 23]);
    });

    test('die Version wächst mit der Länge', async ({ page }) => {
        await page.goto('/');

        const stufen = await page.evaluate(() => ({
            kurz: window.qr.versionFuer(10),
            mittel: window.qr.versionFuer(100),
            lang: window.qr.versionFuer(200)
        }));

        expect(stufen.kurz).toBe(1);
        expect(stufen.mittel).toBeGreaterThanOrEqual(6);
        expect(stufen.lang).toBeGreaterThanOrEqual(9);
    });

    test('die Matrix hat die vorgeschriebene Struktur', async ({ page }) => {
        await page.goto('/');

        const befund = await page.evaluate(() => {
            const m = window.qr.erzeuge('https://einmalpost.de/s/AAAAAAAAAAAAAAAAAAAAAA#' + 'B'.repeat(43));
            const g = m.length;

            // Suchmuster: in drei Ecken ein 7x7-Ring mit 3x3-Kern.
            const suchmuster = (z, s) =>
                m[z][s] === 1 && m[z + 6][s] === 1 && m[z][s + 6] === 1 &&
                m[z + 1][s + 1] === 0 && m[z + 3][s + 3] === 1;

            // Taktmuster: abwechselnd, auf Zeile und Spalte 6.
            let takt = true;
            for (let i = 8; i < g - 8; i++) {
                if (m[6][i] !== (i % 2 === 0 ? 1 : 0)) { takt = false; }
                if (m[i][6] !== (i % 2 === 0 ? 1 : 0)) { takt = false; }
            }

            return {
                groesse: g,
                quadratisch: m.every((zeile) => zeile.length === g),
                nurNullEins: m.every((zeile) => zeile.every((w) => w === 0 || w === 1)),
                obenLinks: suchmuster(0, 0),
                obenRechts: suchmuster(0, g - 7),
                untenLinks: suchmuster(g - 7, 0),
                takt,
                immerDunkel: m[g - 8][8] === 1
            };
        });

        // Version 6 bis 8 für einen Link dieser Länge: 17 + 4v.
        expect(befund.groesse).toBeGreaterThanOrEqual(41);
        expect((befund.groesse - 17) % 4).toBe(0);
        expect(befund.quadratisch).toBe(true);
        expect(befund.nurNullEins).toBe(true);
        expect(befund.obenLinks).toBe(true);
        expect(befund.obenRechts).toBe(true);
        expect(befund.untenLinks).toBe(true);
        expect(befund.takt).toBe(true);
        expect(befund.immerDunkel).toBe(true);
    });

    /**
     * Der eigentliche Nachweis: Ein Leser bekommt heraus, was hineinging.
     *
     * Chromium bringt einen Barcode-Leser mit. Firefox und WebKit nicht -
     * dort lässt sich diese Zusicherung nicht prüfen, und das steht hier,
     * statt in einem stillen Übersprung zu verschwinden.
     */
    test('ein echter Barcode-Leser bekommt den Link zurück', async ({ page, browserName }) => {
        test.skip(browserName !== 'chromium', 'BarcodeDetector gibt es nur in Chromium.');

        await page.goto('/');

        const vorhanden = await page.evaluate(() => 'BarcodeDetector' in window);
        test.skip(!vorhanden, 'BarcodeDetector in dieser Chromium-Fassung nicht verfügbar.');

        const ergebnis = await page.evaluate(async () => {
            const text = 'https://einmalpost.de/s/dSWBvgCoTlZqXeZEF7yfxw#'
                + '6iuWgajhdWNvkgSef7P9t29hsTLbL7Sx-AqD1O4MdJs';

            const svg = window.qr.alsSvg(window.qr.erzeuge(text), 320);
            const roh = new XMLSerializer().serializeToString(svg);
            const bild = new Image();
            bild.src = 'data:image/svg+xml;base64,' + btoa(unescape(encodeURIComponent(roh)));
            await bild.decode();

            const flaeche = new OffscreenCanvas(320, 320);
            const stift = flaeche.getContext('2d');
            stift.fillStyle = '#fff';
            stift.fillRect(0, 0, 320, 320);
            stift.drawImage(bild, 0, 0, 320, 320);

            const leser = new BarcodeDetector({ formats: ['qr_code'] });
            const treffer = await leser.detect(flaeche);

            return { hineingegeben: text, gelesen: treffer.map((t) => t.rawValue) };
        });

        expect(ergebnis.gelesen, 'Der Leser hat gar keinen Code gefunden').toHaveLength(1);
        expect(ergebnis.gelesen[0]).toBe(ergebnis.hineingegeben);
    });

    test('auch ein Link mit Passphrase-Markierung wird zurückgelesen', async ({ page, browserName }) => {
        test.skip(browserName !== 'chromium', 'BarcodeDetector gibt es nur in Chromium.');

        await page.goto('/');
        const vorhanden = await page.evaluate(() => 'BarcodeDetector' in window);
        test.skip(!vorhanden, 'BarcodeDetector nicht verfügbar.');

        const ergebnis = await page.evaluate(async () => {
            const text = 'https://einmalpost.de/s/dSWBvgCoTlZqXeZEF7yfxw#p.'
                + '6iuWgajhdWNvkgSef7P9t29hsTLbL7Sx-AqD1O4MdJs';

            const svg = window.qr.alsSvg(window.qr.erzeuge(text), 320);
            const roh = new XMLSerializer().serializeToString(svg);
            const bild = new Image();
            bild.src = 'data:image/svg+xml;base64,' + btoa(unescape(encodeURIComponent(roh)));
            await bild.decode();

            const flaeche = new OffscreenCanvas(320, 320);
            const stift = flaeche.getContext('2d');
            stift.fillStyle = '#fff';
            stift.fillRect(0, 0, 320, 320);
            stift.drawImage(bild, 0, 0, 320, 320);

            const treffer = await new BarcodeDetector({ formats: ['qr_code'] }).detect(flaeche);

            return { hineingegeben: text, gelesen: treffer.map((t) => t.rawValue) };
        });

        expect(ergebnis.gelesen[0]).toBe(ergebnis.hineingegeben);
    });

    test('der Knopf zeigt den Code, und er enthält keine fremde Adresse', async ({ page }) => {
        await erzeugeGeheimnis(page, 'QR-Probe');

        await page.click('#qrZeigen');
        await expect(page.locator('#qrBereich')).toBeVisible();

        const svg = page.locator('#qrFlaeche svg');
        await expect(svg).toBeVisible();

        // Der Code entsteht hier, nicht bei einem Dienst.
        const inhalt = await page.locator('#qrFlaeche').innerHTML();
        expect(inhalt).not.toContain('http://');
        expect(inhalt).not.toContain('https://');
        expect(inhalt).toContain('<path');
    });
});
