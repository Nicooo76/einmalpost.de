// Anhänge gehen denselben Weg wie Texte: verschlüsselt im Browser,
// aufgefüllt, einmal abrufbar. Der Server sieht weder Inhalt noch Namen.

import { test, expect } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { db, existiert } from './helfer.js';

/**
 * Legt eine Datei über die Oberfläche an. Die Datei entsteht im Browser -
 * so braucht der Test keine Dateien auf der Platte.
 */
async function ladeDatei(page, name, bytes, passphrase) {
    await page.goto('/');

    await page.evaluate(({ name, bytes }) => {
        const datei = new File([new Uint8Array(bytes)], name, { type: 'application/octet-stream' });
        const behaelter = new DataTransfer();
        behaelter.items.add(datei);
        const feld = document.getElementById('datei');
        feld.files = behaelter.files;
        feld.dispatchEvent(new Event('change'));
    }, { name, bytes: Array.from(bytes) });

    if (passphrase) {
        await page.fill('#passphrase', passphrase);
    }

    await page.click('#absenden');
    await page.waitForSelector('#ergebnis:not([hidden])', { timeout: 60000 });

    const adresse = new URL(await page.textContent('#link'));

    return {
        pfad: adresse.pathname + adresse.hash,
        id: adresse.pathname.split('/').pop()
    };
}

/**
 * Lädt die Datei tatsächlich herunter und liest sie von der Platte.
 *
 * Bewusst über den echten Weg - einen Klick auf den Ladeknopf - und nicht
 * über fetch auf die blob-Adresse: Die Inhaltsrichtlinie mit
 * connect-src 'self' verbietet fetch dorthin, und ein Test, der einen
 * Umweg nimmt, prüft nicht mehr das, was der Empfänger tut.
 */
async function heruntergeladeneBytes(page) {
    const [download] = await Promise.all([
        page.waitForEvent('download'),
        page.click('#dateiLaden')
    ]);

    const pfad = await download.path();

    return Array.from(new Uint8Array(readFileSync(pfad)));
}

/**
 * Namen, an denen ein Dateiname scheitern kann.
 *
 * Nicht ausgedacht: Leerzeichen, Klammern und Umlaute stehen in echten
 * Rechnungen und Verträgen, und die Endung entscheidet darüber, ob das
 * Betriebssystem die Datei überhaupt öffnet. Kommt sie nicht mit zurück,
 * hat der Empfänger eine Datei, die er nicht benutzen kann.
 */
const NAMEN = [
    'Rechnung Mai 2026.pdf',
    'Prüfbericht Größe.pdf',
    'Vertrag (final) #3.docx',
    'Foto vom 1. Mai.jpg',
    'kein.punkt.im.namen.txt',
    'GROSS.PDF',
    'Unterlagen 2026.zip'
];

test.describe('Anhänge', () => {
    test('ohne Namen bekommt die Datei wenigstens keinen falschen', async ({ page }) => {
        // Der Rückfall greift nur, wenn gar kein Name ankommt. Über das
        // Dateifeld ist das nicht möglich - dort trägt jede Datei einen. Der
        // Test hält fest, dass der Rückfall trotzdem etwas Brauchbares
        // liefert und nicht etwa leer bleibt.
        const inhalt = new TextEncoder().encode('ohne Namen');
        const geheimnis = await ladeDatei(page, '', inhalt);

        await page.goto('about:blank');
        await page.goto(geheimnis.pfad);
        await page.click('#anzeigen');
        await page.waitForSelector('#ergebnis:not([hidden])', { timeout: 30000 });

        const angezeigt = (await page.textContent('#dateiName')).trim();

        expect(angezeigt).not.toBe('');
        expect(await page.locator('#dateiLaden').getAttribute('download')).not.toBe('');

        // Und der Inhalt kommt trotzdem vollständig zurück.
        expect(await heruntergeladeneBytes(page)).toEqual(Array.from(inhalt));
    });

    for (const name of NAMEN) {
        test(`der Name bleibt: ${name}`, async ({ page }) => {
            const inhalt = new TextEncoder().encode('Inhalt zu ' + name);
            const geheimnis = await ladeDatei(page, name, inhalt);

            await page.goto('about:blank');
            await page.goto(geheimnis.pfad);
            await page.click('#anzeigen');
            await page.waitForSelector('#ergebnis:not([hidden])', { timeout: 30000 });

            // Was auf der Seite steht ...
            expect(await page.textContent('#dateiName')).toBe(name);

            // ... was im Markup steht ...
            expect(await page.locator('#dateiLaden').getAttribute('download')).toBe(name);

            // ... und vor allem: unter welchem Namen der Browser sie
            // tatsächlich anbietet. Das Attribut kann gesetzt sein und der
            // Browser es trotzdem verwerfen - dann bekäme der Empfänger eine
            // Datei ohne Endung, die sich nicht öffnen lässt.
            const [download] = await Promise.all([
                page.waitForEvent('download'),
                page.click('#dateiLaden')
            ]);

            // Verglichen wird nach Unicode-Normalisierung: WebKit zerlegt
            // beim Speichern den Umlaut in Buchstabe und Zeichen (NFD),
            // während die Vorlage ihn als ein Zeichen führt (NFC). Für das
            // Dateisystem und für den Blick des Empfängers ist das dasselbe -
            // ein Vergleich Byte für Byte wäre hier zu streng und würde einen
            // Unterschied melden, den niemand sieht.
            const vorschlag = download.suggestedFilename();

            expect(vorschlag.normalize('NFC')).toBe(name.normalize('NFC'));

            // Die Endung dagegen wird hart geprüft. Ohne sie öffnet das
            // Betriebssystem die Datei nicht - der Empfänger hätte etwas,
            // das er nicht benutzen kann.
            const endung = name.slice(name.lastIndexOf('.'));

            expect(vorschlag.endsWith(endung)).toBe(true);
        });
    }

    test('eine Datei kommt unverändert zurück', async ({ page }) => {
        const inhalt = new TextEncoder().encode('Zugangsdaten\nBenutzer: m.schulz\n');
        const geheimnis = await ladeDatei(page, 'zugang.txt', inhalt);

        await page.goto('about:blank');
        await page.goto(geheimnis.pfad);
        await page.click('#anzeigen');
        await page.waitForSelector('#ergebnis:not([hidden])', { timeout: 30000 });

        await expect(page.locator('#dateiErgebnis')).toBeVisible();
        expect(await page.textContent('#dateiName')).toBe('zugang.txt');
        expect(await page.locator('#dateiLaden').getAttribute('download')).toBe('zugang.txt');

        expect(await heruntergeladeneBytes(page)).toEqual(Array.from(inhalt));
    });

    test('auch beliebige Bytes kommen unverändert zurück', async ({ page }) => {
        // Alle 256 Bytewerte, dazu Nullbytes am Anfang und Ende.
        const bytes = new Uint8Array(1024);
        for (let i = 0; i < 1024; i++) { bytes[i] = (i * 7) % 256; }
        bytes[0] = 0; bytes[1023] = 0;

        const geheimnis = await ladeDatei(page, 'binaer.bin', bytes);

        await page.goto('about:blank');
        await page.goto(geheimnis.pfad);
        await page.click('#anzeigen');
        await page.waitForSelector('#ergebnis:not([hidden])', { timeout: 30000 });

        expect(await heruntergeladeneBytes(page)).toEqual(Array.from(bytes));
    });

    test('Umlaute und Emoji im Dateinamen überstehen den Weg', async ({ page }) => {
        const geheimnis = await ladeDatei(page, 'Prüfbericht Größe 🔑.txt', new TextEncoder().encode('x'));

        await page.goto('about:blank');
        await page.goto(geheimnis.pfad);
        await page.click('#anzeigen');
        await page.waitForSelector('#ergebnis:not([hidden])', { timeout: 30000 });

        expect(await page.textContent('#dateiName')).toBe('Prüfbericht Größe 🔑.txt');
    });

    test('weder Inhalt noch Name stehen in der Datenbank', async ({ page }) => {
        const inhalt = new TextEncoder().encode('DateiInhaltEinmalig-9f3c');
        const geheimnis = await ladeDatei(page, 'NameEinmalig-4a7b.txt', inhalt);

        expect(existiert(geheimnis.id)).toBe(true);
        expect(db('suche', 'DateiInhaltEinmalig-9f3c')).toBe('0');
        expect(db('suche', 'NameEinmalig-4a7b')).toBe('0');
        expect(db('suche', '.txt')).toBe('0');
    });

    test('eine Datei wird genauso einmal ausgeliefert', async ({ page }) => {
        const geheimnis = await ladeDatei(page, 'einmal.txt', new TextEncoder().encode('nur einmal'));

        await page.goto('about:blank');
        await page.goto(geheimnis.pfad);
        await page.click('#anzeigen');
        await page.waitForSelector('#ergebnis:not([hidden])', { timeout: 30000 });

        await page.goto('about:blank');
        await page.goto(geheimnis.pfad);
        await page.click('#anzeigen');
        await page.waitForSelector('#fortgeschrieben:not([hidden])', { timeout: 30000 });

        expect(existiert(geheimnis.id)).toBe(false);
    });

    test('eine Datei mit Passphrase braucht beides', async ({ page }) => {
        const inhalt = new TextEncoder().encode('doppelt gesichert');
        const geheimnis = await ladeDatei(page, 'geheim.txt', inhalt, 'zweiter-weg');

        await page.goto('about:blank');
        await page.goto(geheimnis.pfad);
        await page.click('#anzeigen');
        await page.waitForSelector('#passphraseAbfrage:not([hidden])');

        await page.fill('#passphraseEingabe', 'zweiter-weg');
        await page.click('#passphraseAbsenden');
        await page.waitForSelector('#ergebnis:not([hidden])', { timeout: 30000 });

        expect(await heruntergeladeneBytes(page)).toEqual(Array.from(inhalt));
    });

    test('bei einer Datei gibt es keinen Kopieren-Knopf', async ({ page }) => {
        const geheimnis = await ladeDatei(page, 'x.bin', new Uint8Array([1, 2, 3]));

        await page.goto('about:blank');
        await page.goto(geheimnis.pfad);
        await page.click('#anzeigen');
        await page.waitForSelector('#ergebnis:not([hidden])', { timeout: 30000 });

        // Eine Datei lässt sich nicht in die Zwischenablage legen; der Knopf
        // wäre eine Zusage, die nicht einzuhalten ist.
        await expect(page.locator('#kopierZeile')).toBeHidden();
        await expect(page.locator('#inhalt')).toBeHidden();
        await expect(page.locator('#dateiLaden')).toBeVisible();
    });

    test('Text und Datei zugleich geht nicht', async ({ page }) => {
        await page.goto('/');
        await page.fill('#geheimnis', 'ein Text');

        await page.evaluate(() => {
            const datei = new File([new Uint8Array([1, 2, 3])], 'x.bin');
            const behaelter = new DataTransfer();
            behaelter.items.add(datei);
            const feld = document.getElementById('datei');
            feld.files = behaelter.files;
            feld.dispatchEvent(new Event('change'));
        });

        // Sobald eine Datei gewählt ist, ist das Textfeld gesperrt und leer.
        await expect(page.locator('#geheimnis')).toBeDisabled();
        expect(await page.inputValue('#geheimnis')).toBe('');
        await expect(page.locator('#dateiInfo')).toBeVisible();
    });

    test('die Auffüllung gilt auch für Dateien', async ({ page }) => {
        const klein = await ladeDatei(page, 'a.bin', new Uint8Array(10));
        const groesser = await ladeDatei(page, 'a.bin', new Uint8Array(200));

        // Gleicher Name, verschieden große Inhalte - in der Datenbank
        // dieselbe Länge.
        expect(db('laenge', klein.id)).toBe(db('laenge', groesser.id));
    });
});
