// Der QR-Code muss lesbar sein, nicht nur hübsch.
//
// Geprüft wird auf drei Ebenen: die Zwischenschritte gegen bekannte Werte
// aus ISO/IEC 18004, die Struktur der Matrix, und zuletzt das Entscheidende
// - ein echter Barcode-Leser bekommt den Link zurück, den wir hineingegeben
// haben. Ein selbst geschriebener Kodierer ohne diesen Test wäre eine
// Behauptung.

import { test, expect } from '@playwright/test';
import { fileURLToPath } from 'node:url';
import { erzeugeGeheimnis } from './helfer.js';

// Für die Rückles-Tests: Der Dienst lädt selbst keine data:-Bilder -
// img-src 'self' verbietet sie, und der QR hängt als Inline-SVG im DOM.
// Nur diese Tests lesen das SVG über ein data:-Bild in einen Leser zurück.
// Das geschieht auf einem Prüfstand ohne die CSP des Dienstes; der
// Kodierer wird ihm direkt aus der ausgelieferten Datei mitgegeben.
//
// Der Prüfstand muss vom selben Ursprung kommen, nicht von about:blank:
// BarcodeDetector gibt es nur im sicheren Kontext, und eine Seite ohne
// Herkunft hat keinen. Deshalb beantwortet der Test eine eigene Route
// selbst - gleicher Ursprung, aber ohne die Kopfzeilen des Dienstes.
const QR_DATEI = fileURLToPath(new URL('../../public/assets/qr.js', import.meta.url));

async function qrPruefstand(context) {
    const seite = await context.newPage();
    await seite.route('**/qr-pruefstand', (route) => route.fulfill({
        contentType: 'text/html',
        body: '<!doctype html><title>QR-Prüfstand</title>',
    }));
    await seite.goto('/qr-pruefstand');
    await seite.addScriptTag({ path: QR_DATEI });
    return seite;
}

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
    test('Bewertungsregel 3 zählt nur vollständige Suchmuster', async ({ page }) => {
        // Die Regel bestraft Folgen, die einem Suchmuster ähneln. Je Stelle
        // muss die **ganze** Folge zu einem der beiden Muster passen. Wird
        // stattdessen Position für Position gegen beide zugleich geprüft,
        // gilt auch eine Mischform als Treffer - die Strafe fällt zu hoch
        // aus und die Maskenwahl wird systematisch schlechter.
        //
        // Geprüft wird die Regel für sich. In der Gesamtbewertung überlagern
        // die anderen drei Regeln den Unterschied so weit, dass ein Test
        // darauf auch mit dem Fehler grün bliebe.
        await page.goto('/');

        const ergebnis = await page.evaluate(() => {
            const MUSTER = [1, 0, 1, 1, 1, 0, 1, 0, 0, 0, 0];
            const UMGEKEHRT = MUSTER.slice().reverse();

            const leer = () => Array.from({ length: 11 }, () => new Array(11).fill(0));
            const mitZeile = (folge) => {
                const m = leer();
                for (let k = 0; k < 11; k++) { m[0][k] = folge[k]; }
                return m;
            };

            // Vorn wie das Muster, hinten wie die Umkehrung - und damit weder
            // das eine noch das andere.
            const misch = MUSTER.slice(0, 7).concat(UMGEKEHRT.slice(7));

            return {
                muster: window.qr._zaehleSuchmuster(mitZeile(MUSTER)),
                umgekehrt: window.qr._zaehleSuchmuster(mitZeile(UMGEKEHRT)),
                misch: window.qr._zaehleSuchmuster(mitZeile(misch)),
                leer: window.qr._zaehleSuchmuster(leer()),
                istMisch: misch.join('') !== MUSTER.join('') && misch.join('') !== UMGEKEHRT.join('')
            };
        });

        // Die Mischform ist wirklich eine - sonst prüfte der Test nichts.
        expect(ergebnis.istMisch).toBe(true);

        // Beide echten Muster zählen, in beide Richtungen gleich.
        expect(ergebnis.muster).toBe(1);
        expect(ergebnis.umgekehrt).toBe(1);

        // Die Mischform zählt nicht. Mit der früheren Prüfung war sie ein
        // Treffer - genau daran wird dieser Test rot, wenn jemand sie
        // wiederherstellt.
        expect(ergebnis.misch).toBe(0);
        expect(ergebnis.leer).toBe(0);
    });

    test('in einem wirklichen QR-Code findet die Regel die Suchmuster', async ({ page }) => {
        await page.goto('/');

        const treffer = await page.evaluate(() => {
            const matrix = window.qr.erzeuge('https://einmalpost.de/s/AAAAAAAAAAAAAAAAAAAAAA#' + 'A'.repeat(43));

            return { gefunden: window.qr._zaehleSuchmuster(matrix), kante: matrix.length };
        });

        // Die drei Suchmuster in den Ecken erzeugen solche Folgen; null wäre
        // ein Zeichen dafür, dass die Zählung gar nichts mehr findet.
        expect(treffer.kante).toBeGreaterThan(20);
        expect(treffer.gefunden).toBeGreaterThan(0);
    });

    test('ein echter Barcode-Leser bekommt den Link zurück', async ({ context, browserName }) => {
        test.skip(browserName !== 'chromium', 'BarcodeDetector gibt es nur in Chromium.');

        const seite = await qrPruefstand(context);

        const vorhanden = await seite.evaluate(() => 'BarcodeDetector' in window);
        test.skip(!vorhanden, 'BarcodeDetector in dieser Chromium-Fassung nicht verfügbar.');

        const ergebnis = await seite.evaluate(async () => {
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

    test('auch ein Link mit Passphrase-Markierung wird zurückgelesen', async ({ context, browserName }) => {
        test.skip(browserName !== 'chromium', 'BarcodeDetector gibt es nur in Chromium.');

        const seite = await qrPruefstand(context);
        const vorhanden = await seite.evaluate(() => 'BarcodeDetector' in window);
        test.skip(!vorhanden, 'BarcodeDetector nicht verfügbar.');

        const ergebnis = await seite.evaluate(async () => {
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
