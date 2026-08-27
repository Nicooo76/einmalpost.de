// Deutsch unter /, Englisch unter /en/.
//
// Beide Fassungen tragen dieselbe Struktur - dieselben Kennungen, dieselben
// Abschnitte. Das ist keine Kosmetik: Die Skripte sind geteilt, und eine
// fehlende Kennung in einer Fassung macht sie stumm kaputt.

import { test, expect } from '@playwright/test';

const SEITENPAARE = [
    ['/', '/en'],
    ['/sicherheit', '/en/security'],
];

test.describe('Sprachfassungen', () => {
    test('die Startseite gibt es in beiden Sprachen', async ({ page }) => {
        await page.goto('/');
        expect(await page.getAttribute('html', 'lang')).toBe('de');
        await expect(page.locator('.hero__title')).toContainText('Passwörter');

        await page.goto('/en');
        expect(await page.getAttribute('html', 'lang')).toBe('en');
        await expect(page.locator('.hero__title')).toContainText('passwords');
    });

    for (const [de, en] of SEITENPAARE) {
        test(`${de} und ${en} tragen dieselben Kennungen`, async ({ page }) => {
            const kennungen = async (pfad) => {
                await page.goto(pfad);

                return page.evaluate(() => [...document.querySelectorAll('[id]')].map((e) => e.id).sort());
            };

            expect(await kennungen(en)).toEqual(await kennungen(de));
        });
    }

    test('auch die Anzeigeseite trägt in beiden Fassungen dieselben Kennungen', async ({ page }) => {
        const kennungen = async (pfad) => {
            await page.goto(pfad);

            return page.evaluate(() => [...document.querySelectorAll('[id]')].map((e) => e.id).sort());
        };

        expect(await kennungen('/en/s/AAAAAAAAAAAAAAAAAAAAAA'))
            .toEqual(await kennungen('/s/AAAAAAAAAAAAAAAAAAAAAA'));
    });

    test('hreflang verweist auf beide Fassungen', async ({ page }) => {
        for (const [de, en] of SEITENPAARE) {
            for (const pfad of [de, en]) {
                await page.goto(pfad);

                expect(await page.getAttribute('link[hreflang="de"]', 'href'), pfad).toBe(de);
                expect(await page.getAttribute('link[hreflang="en"]', 'href'), pfad).toBe(en);
                expect(await page.getAttribute('link[hreflang="x-default"]', 'href'), pfad).toBe(de);
            }
        }
    });

    test('der Sprachwechsel führt auf dieselbe Seite', async ({ page }) => {
        await page.goto('/sicherheit');
        await page.click('.site-footer__sprache');
        await expect(page).toHaveURL(/\/en\/security$/);
        expect(await page.getAttribute('html', 'lang')).toBe('en');

        await page.click('.site-footer__sprache');
        await expect(page).toHaveURL(/\/sicherheit$/);
        expect(await page.getAttribute('html', 'lang')).toBe('de');
    });

    /**
     * Auf der Anzeigeseite darf es keinen Sprachwechsel geben.
     *
     * Ein gewöhnlicher Link verliert das Fragment - und damit den Schlüssel.
     * Der Empfänger stünde dann vor einem unvollständigen Link und hätte
     * keine Möglichkeit mehr, an den Inhalt zu kommen.
     */
    test('auf der Anzeigeseite gibt es keinen Sprachwechsel', async ({ page }) => {
        await page.goto('/s/AAAAAAAAAAAAAAAAAAAAAA#' + 'A'.repeat(43));

        await expect(page.locator('.site-footer__sprache')).toHaveCount(0);

        await page.goto('/en/s/AAAAAAAAAAAAAAAAAAAAAA#' + 'A'.repeat(43));
        await expect(page.locator('.site-footer__sprache')).toHaveCount(0);
    });

    test('der englische Fußbereich kennzeichnet die deutschen Rechtstexte', async ({ page }) => {
        await page.goto('/en');

        const impressum = page.locator('.site-footer a[href="/impressum"]');

        await expect(impressum).toBeVisible();
        await expect(impressum).toContainText('German');
    });

    test('ein vollständiger Durchlauf auf Englisch', async ({ page }) => {
        await page.goto('/en');
        await page.fill('#geheimnis', 'credentials for the staging system');
        await page.click('#absenden');
        await page.waitForSelector('#ergebnis:not([hidden])', { timeout: 30000 });

        const adresse = new URL(await page.textContent('#link'));

        // Der Link zeigt auf die Fassung, auf der er erzeugt wurde. Sonst
        // bekäme der Empfänger eine deutsche Anzeigeseite - samt der
        // Erklärung, warum sein Inhalt trotz eines Fehlschlags verbraucht ist.
        expect(adresse.pathname).toMatch(/^\/en\/s\//);

        await page.goto('about:blank');

        // Kein zusätzliches Präfix mehr: Der Link trägt es selbst.
        await page.goto(adresse.pathname + adresse.hash);

        await expect(page.locator('#bestaetigung')).toContainText('SOMETHING CONFIDENTIAL');

        await page.click('#anzeigen');
        await page.waitForSelector('#ergebnis:not([hidden])', { timeout: 30000 });

        expect(await page.textContent('#inhalt')).toBe('credentials for the staging system');
        await expect(page.locator('#statuszeile')).toContainText('SHOWN AND DELETED');
    });

    test('die Meldungen des Skripts sind übersetzt', async ({ page }) => {
        await page.goto('/en');
        await page.click('#absenden');
        await page.waitForSelector('#fehler:not([hidden])');

        expect(await page.textContent('#fehler')).toContain('Please enter a text');

        await page.goto('/');
        await page.click('#absenden');
        await page.waitForSelector('#fehler:not([hidden])');

        expect(await page.textContent('#fehler')).toContain('Bitte geben Sie einen Text ein');
    });

    test('auch die Meldungen der Anzeigeseite sind übersetzt', async ({ page }) => {
        // create.js hatte von Anfang an ein Wörterbuch, reveal.js nicht -
        // auf der englischen Anzeigeseite stand deshalb Deutsch. Betroffen war
        // ausgerechnet der Satz, der erklärt, warum der Inhalt trotz
        // Fehlschlag verbraucht ist.
        await page.goto('/en');
        await page.fill('#geheimnis', 'english reveal messages');
        await page.click('#absenden');
        await page.waitForSelector('#ergebnis:not([hidden])', { timeout: 30000 });

        const adresse = new URL(await page.textContent('#link'));

        await page.goto('about:blank');
        await page.goto(adresse.pathname + adresse.hash);

        expect(await page.getAttribute('html', 'lang')).toBe('en');

        await page.click('#anzeigen');
        await page.waitForSelector('#ergebnis:not([hidden])', { timeout: 30000 });

        // Der Kopieren-Knopf setzt seine Beschriftung im Skript.
        await page.click('#kopieren');
        await page.waitForFunction(
            () => document.getElementById('kopieren').textContent.trim() !== 'COPY',
            null,
            { timeout: 15000 }
        );

        const beschriftung = (await page.textContent('#kopieren')).trim();

        expect(beschriftung).not.toContain('KOPIERT');
        expect(beschriftung).not.toContain('BITTE VON HAND');
        expect(beschriftung).toMatch(/COPIED|COPYING NOT POSSIBLE/);
    });

    test('die Passphrase-Abfrage meldet sich auf Englisch', async ({ page }) => {
        await page.goto('/en');
        await page.fill('#geheimnis', 'guarded by a passphrase');
        await page.fill('#passphrase', 'umbrella-7');
        await page.click('#absenden');
        await page.waitForSelector('#ergebnis:not([hidden])', { timeout: 30000 });

        const adresse = new URL(await page.textContent('#link'));

        await page.goto('about:blank');
        await page.goto(adresse.pathname + adresse.hash);
        await page.click('#anzeigen');
        await page.waitForSelector('#passphraseAbfrage:not([hidden])', { timeout: 20000 });

        // Leere Eingabe: Die Meldung kommt aus dem Skript.
        await page.click('#passphraseAbsenden');
        await page.waitForSelector('#fehler:not([hidden])');

        const meldung = await page.textContent('#fehler');

        expect(meldung).toContain('Please enter the passphrase');
        expect(meldung).not.toContain('Bitte geben Sie');
    });

    test('die englische Auszeichnung passt zur englischen Seite', async ({ page }) => {
        await page.goto('/en');

        const daten = JSON.parse(await page.locator('script[type="application/ld+json"]').textContent());

        expect(daten['@type']).toBe('FAQPage');

        for (const eintrag of daten.mainEntity) {
            await expect(page.getByText(eintrag.name, { exact: true })).toBeVisible();
        }
    });
});
