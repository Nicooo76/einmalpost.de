// Zusage 12: Grenzwerte 0, 1, Maximum, Maximum+1 verhalten sich wie festgelegt.
// Zusage 19: Fehlerhafte Eingaben erzeugen keinen Fehler 500.

import { test, expect } from '@playwright/test';
import { zeigeAn } from './helfer.js';

// Größter Klartext, der noch in die 64-KB-Grenze passt:
// 4 Byte Längenfeld + Klartext, aufgefüllt auf 255 Blöcke à 256 Byte,
// dazu 12 Byte IV und 16 Byte Tag ergibt 65308 Byte payload.
const GROESSTER_KLARTEXT = 255 * 256 - 4;

test.describe('Grenzwerte', () => {
    test('0 Zeichen: der Browser sendet gar nicht erst', async ({ page }) => {
        await page.goto('/');

        const anfragen = [];
        page.on('request', (a) => a.method() === 'POST' && anfragen.push(a.url()));

        await page.click('#absenden');
        await page.waitForSelector('#fehler:not([hidden])');

        expect(await page.textContent('#fehler')).toContain('Bitte geben Sie einen Text ein');
        expect(anfragen).toHaveLength(0);
    });

    test('1 Zeichen: geht durch', async ({ page }) => {
        await page.goto('/');
        await page.fill('#geheimnis', 'x');
        await page.click('#absenden');
        await page.waitForSelector('#ergebnis:not([hidden])');

        const adresse = new URL(await page.textContent('#link'));
        const anzeige = await zeigeAn(page, adresse.pathname + adresse.hash);

        expect(anzeige.inhalt).toBe('x');
    });

    test('genau das Maximum: geht durch', async ({ page }) => {
        await page.goto('/');
        await page.evaluate((anzahl) => {
            document.getElementById('geheimnis').value = 'A'.repeat(anzahl);
        }, GROESSTER_KLARTEXT);

        await page.click('#absenden');
        await page.waitForSelector('#ergebnis:not([hidden])', { timeout: 30000 });

        const adresse = new URL(await page.textContent('#link'));
        const anzeige = await zeigeAn(page, adresse.pathname + adresse.hash);

        expect(anzeige.inhalt).toHaveLength(GROESSTER_KLARTEXT);
    });

    test('ein Zeichen über dem Maximum: der Server lehnt ab', async ({ page }) => {
        await page.goto('/');
        await page.evaluate((anzahl) => {
            document.getElementById('geheimnis').value = 'A'.repeat(anzahl);
        }, GROESSTER_KLARTEXT + 1);

        await page.click('#absenden');
        await page.waitForSelector('#fehler:not([hidden])', { timeout: 30000 });

        expect(await page.textContent('#fehler')).toContain('nicht angenommen');
    });
});

test.describe('Zusage 19: unbrauchbare Eingaben ergeben keinen Fehler 500', () => {
    const ANFRAGEN = [
        ['leerer Rumpf', ''],
        ['kein JSON', 'das ist kein json'],
        ['JSON-Feld fehlt', '{}'],
        ['falscher Typ', '{"payload": 42, "ttl": "viel"}'],
        ['null-Werte', '{"payload": null, "ttl": null}'],
        ['tief verschachtelt', '{"payload":{"a":{"b":{"c":{"d":{"e":{"f":{"g":1}}}}}}},"ttl":3600}'],
        ['Feld als Liste', '{"payload": ["a"], "ttl": [3600]}'],
        ['negative Lebensdauer', '{"payload":"AAAA","ttl":-1}'],
        ['riesige Lebensdauer', '{"payload":"AAAA","ttl":999999999999}'],
        ['payload kein base64url', '{"payload":"!!!!","ttl":3600}'],
        ['SQL im Feld', '{"payload":"AAAA","ttl":3600,"x":"1 OR 1=1; DROP TABLE secrets"}'],
        ['Nullbyte im Feld', '{"payload":"AA\\u0000AA","ttl":3600}']
    ];

    for (const [name, rumpf] of ANFRAGEN) {
        test(`create: ${name}`, async ({ request }) => {
            const antwort = await request.post('/api/create', {
                headers: { 'Content-Type': 'application/json' },
                data: rumpf
            });

            expect(antwort.status(), `${name} ergab ${antwort.status()}`).toBeLessThan(500);
            expect(await antwort.text()).not.toContain('Fatal');
            expect(await antwort.text()).not.toContain('Stack trace');
            expect(await antwort.text()).not.toContain('/Users/');
        });

        test(`reveal: ${name}`, async ({ request }) => {
            const antwort = await request.post('/api/reveal', {
                headers: { 'Content-Type': 'application/json' },
                data: rumpf
            });

            expect(antwort.status(), `${name} ergab ${antwort.status()}`).toBeLessThan(500);
            expect(await antwort.text()).not.toContain('Exception');
        });
    }

    test('unbekannte Pfade und Methoden ergeben keinen 500', async ({ request }) => {
        const pfade = ['/gibtsnicht', '/api', '/../config/config.php', '/s/', '/s/%00', '/index.php'];

        for (const pfad of pfade) {
            const antwort = await request.get(pfad);
            expect(antwort.status(), pfad).toBeLessThan(500);
        }
    });
});
