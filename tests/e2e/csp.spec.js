// Zusage 17: Ein eingeschmuggeltes Fremdskript wird durch die CSP blockiert.
//
// Geprüft wird gegen die echten Kopfzeilen: Die Antwort des Servers wird
// unterwegs abgefangen und um ein Skript ergänzt, die Kopfzeilen bleiben
// unangetastet. So entscheidet die tatsächlich ausgelieferte CSP.

import { test, expect } from '@playwright/test';
import { erzeugeGeheimnis, zeigeAn } from './helfer.js';

/**
 * Schmuggelt Auszeichnung in die Antwort des Servers.
 */
async function schmuggle(page, einschub) {
    await page.route('**/s/*', async (route) => {
        const antwort = await route.fetch();
        const html = (await antwort.text()).replace('</body>', einschub + '</body>');

        await route.fulfill({ response: antwort, body: html });
    });
}

test.describe('Die CSP hält', () => {
    test('ein eingeschmuggeltes Inline-Skript ohne Nonce läuft nicht', async ({ page }) => {
        const geheimnis = await erzeugeGeheimnis(page, 'bleibt geschützt');

        await schmuggle(page, '<script>window.eingeschmuggelt = true;</script>');

        await page.goto(geheimnis.pfad);

        expect(await page.evaluate(() => window.eingeschmuggelt)).toBeUndefined();
    });

    test('ein Skript mit falschem Nonce läuft nicht', async ({ page }) => {
        const geheimnis = await erzeugeGeheimnis(page, 'bleibt geschützt');

        await schmuggle(page, '<script nonce="AAAAAAAAAAAAAAAAAAAAAA==">window.eingeschmuggelt = true;</script>');

        await page.goto(geheimnis.pfad);

        expect(await page.evaluate(() => window.eingeschmuggelt)).toBeUndefined();
    });

    test('ein Skript von einem fremden Server läuft nicht', async ({ page }) => {
        const geheimnis = await erzeugeGeheimnis(page, 'bleibt geschützt');

        await schmuggle(page, '<script src="https://example.invalid/boese.js"></script>');

        const blockiert = [];
        page.on('requestfailed', (anfrage) => blockiert.push(anfrage.url()));

        await page.goto(geheimnis.pfad);
        await page.waitForTimeout(300);

        expect(await page.evaluate(() => window.eingeschmuggelt)).toBeUndefined();
    });

    test('die eigenen Skripte laufen weiterhin', async ({ page }) => {
        // Ohne diesen Test wären die drei oberen auch dann grün, wenn
        // überhaupt kein Skript mehr liefe.
        const geheimnis = await erzeugeGeheimnis(page, 'die Seite arbeitet');

        await page.goto(geheimnis.pfad);

        expect(await page.evaluate(() => typeof window.einmalpost)).toBe('object');

        const anzeige = await zeigeAn(page, geheimnis.pfad);
        expect(anzeige.inhalt).toBe('die Seite arbeitet');
    });

    test('die Kopfzeilen kommen tatsächlich an', async ({ request }) => {
        const antwort = await request.get('/s/AAAAAAAAAAAAAAAAAAAAAA');
        const kopf = antwort.headers();

        expect(kopf['content-security-policy']).toContain("'strict-dynamic'");
        expect(kopf['content-security-policy']).toContain("object-src 'none'");
        expect(kopf['content-security-policy']).toContain("base-uri 'none'");
        expect(kopf['content-security-policy']).toContain("require-trusted-types-for 'script'");
        // Nach dem Clickjacking-Befund ergänzt: die Anzeigeseite darf nicht
        // rahmbar sein, nichts darf ausgehen außer zu den eigenen /api/*.
        expect(kopf['content-security-policy']).toContain("frame-ancestors 'none'");
        expect(kopf['content-security-policy']).toContain("default-src 'none'");
        expect(kopf['content-security-policy']).toContain("connect-src 'self'");
        expect(kopf['referrer-policy']).toBe('no-referrer');
        expect(kopf['x-content-type-options']).toBe('nosniff');
        expect(kopf['permissions-policy']).toBeTruthy();
        expect(kopf['cache-control']).toContain('no-store');

        // HSTS gehört auf die nginx-Ebene. Aus PHP darf es nicht kommen -
        // sonst stünde es doppelt in der Antwort.
        expect(kopf['strict-transport-security']).toBeUndefined();
    });

    test('auch die Antwort der Schnittstelle trägt die Kopfzeilen', async ({ request }) => {
        const antwort = await request.post('/api/reveal', { data: { id: 'AAAAAAAAAAAAAAAAAAAAAA' } });
        const kopf = antwort.headers();

        expect(antwort.status()).toBe(404);
        expect(kopf['cache-control']).toContain('no-store');
        expect(kopf['referrer-policy']).toBe('no-referrer');
        expect(kopf['x-content-type-options']).toBe('nosniff');
    });
});
