// Zusage 12: Grenzwerte 0, 1, Maximum, Maximum+1 verhalten sich wie festgelegt.
// Zusage 19: Fehlerhafte Eingaben erzeugen keinen Fehler 500.

import { test, expect } from '@playwright/test';
import { zeigeAn } from './helfer.js';

// Was ein Absender höchstens hineinlegen darf. Muss zu
// SecretStore::NUTZLAST_MAX_BYTES passen.
const NUTZLAST_MAX = 16000000;

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

    /**
     * Ein Text knapp unter der Grenze.
     *
     * Bewusst nicht die vollen 16 MB: Der Browser müsste sie erst erzeugen,
     * dann verschlüsseln, dann als base64 übertragen - das dauert Minuten
     * und prüft nichts, was eine Million Zeichen nicht auch prüfen. Die
     * Grenze selbst wird dort geprüft, wo sie durchgesetzt wird:
     * tests/Integration/SecretStoreTest.php.
     */
    test('ein Text von einer Million Zeichen geht durch', async ({ page }) => {
        await page.goto('/');
        await page.evaluate(() => {
            document.getElementById('geheimnis').value = 'A'.repeat(1000000);
        });

        await page.click('#absenden');
        await page.waitForSelector('#ergebnis:not([hidden])', { timeout: 60000 });

        const adresse = new URL(await page.textContent('#link'));
        const anzeige = await zeigeAn(page, adresse.pathname + adresse.hash);

        expect(anzeige.inhalt).toHaveLength(1000000);
    });

    test('eine zu große Datei wird vor dem Senden abgelehnt', async ({ page }) => {
        await page.goto('/');

        const anfragen = [];
        page.on('request', (a) => a.method() === 'POST' && anfragen.push(a.url()));

        // Eine Datei knapp über der Grenze, im Browser erzeugt.
        await page.evaluate((max) => {
            const daten = new Uint8Array(max + 1024);
            const datei = new File([daten], 'zu-gross.bin', { type: 'application/octet-stream' });
            const behaelter = new DataTransfer();
            behaelter.items.add(datei);
            document.getElementById('datei').files = behaelter.files;
            document.getElementById('datei').dispatchEvent(new Event('change'));
        }, NUTZLAST_MAX);

        await page.waitForSelector('#fehler:not([hidden])', { timeout: 30000 });

        expect(await page.textContent('#fehler')).toContain('Möglich sind');
        expect(anfragen, 'Es wurde trotzdem gesendet').toHaveLength(0);
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
