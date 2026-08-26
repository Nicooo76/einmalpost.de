// Die Passphrase schließt die Lücke, die der Link allein offenlässt.
//
// Ohne sie gilt: Wer den Link abfängt, hat alles. Mit ihr braucht es zwei
// Dinge über zwei Wege - und der Server kennt keines von beiden.

import { test, expect } from '@playwright/test';
import { db, existiert, zeigeAn } from './helfer.js';

/**
 * Legt ein Geheimnis mit Passphrase an.
 */
async function mitPassphrase(page, klartext, passphrase) {
    await page.goto('/');
    await page.fill('#geheimnis', klartext);
    await page.fill('#passphrase', passphrase);
    await page.click('#absenden');
    await page.waitForSelector('#ergebnis:not([hidden])', { timeout: 30000 });

    const adresse = new URL(await page.textContent('#link'));

    return {
        pfad: adresse.pathname + adresse.hash,
        id: adresse.pathname.split('/').pop(),
        fragment: adresse.hash.replace(/^#/, '')
    };
}

test.describe('Passphrase', () => {
    test('der Link trägt eine Markierung, der Schlüssel bleibt 43 Zeichen', async ({ page }) => {
        const geheimnis = await mitPassphrase(page, 'doppelt gesichert', 'Regenschirm-7');

        expect(geheimnis.fragment).toMatch(/^p\./);
        expect(geheimnis.fragment.slice(2)).toHaveLength(43);

        // Und der Hinweis für den Absender steht da.
        await expect(page.locator('#passphraseHinweis')).toBeVisible();
    });

    test('ohne Passphrase gibt es keine Markierung', async ({ page }) => {
        await page.goto('/');
        await page.fill('#geheimnis', 'einfach');
        await page.click('#absenden');
        await page.waitForSelector('#ergebnis:not([hidden])');

        const fragment = new URL(await page.textContent('#link')).hash;

        expect(fragment).not.toContain('p.');
        expect(fragment.replace(/^#/, '')).toHaveLength(43);
        await expect(page.locator('#passphraseHinweis')).toBeHidden();
    });

    /**
     * Der wichtigste Test dieser Datei.
     *
     * Gefragt wird VOR dem Abruf. Würde erst abgerufen und dann gefragt,
     * wäre das Geheimnis verbraucht, während der Empfänger noch die
     * Passphrase sucht - und bei einem Tippfehler unwiederbringlich weg.
     */
    test('gefragt wird, bevor abgerufen wird', async ({ page }) => {
        const geheimnis = await mitPassphrase(page, 'noch unversehrt', 'Kennwort-42');

        const abrufe = [];
        await page.goto('about:blank');
        page.on('request', (a) => a.url().includes('/api/reveal') && abrufe.push(a.url()));

        await page.goto(geheimnis.pfad);
        await page.click('#anzeigen');
        await page.waitForSelector('#passphraseAbfrage:not([hidden])');
        await page.waitForTimeout(300);

        expect(abrufe, 'Es wurde abgerufen, bevor gefragt wurde').toHaveLength(0);
        expect(existiert(geheimnis.id), 'Das Geheimnis wurde verbraucht').toBe(true);

        // Und der Hinweis sagt, was ein Fehlversuch kostet.
        const text = await page.textContent('#passphraseAbfrage');
        expect(text).toContain('Ein Fehlversuch verbraucht den Inhalt');
    });

    test('mit der richtigen Passphrase kommt der Inhalt', async ({ page }) => {
        const geheimnis = await mitPassphrase(page, 'Kennwort: Regenschirm-7-Blau', 'oeffne-dich');

        await page.goto('about:blank');
        await page.goto(geheimnis.pfad);
        await page.click('#anzeigen');
        await page.waitForSelector('#passphraseAbfrage:not([hidden])');

        await page.fill('#passphraseEingabe', 'oeffne-dich');
        await page.click('#passphraseAbsenden');
        await page.waitForSelector('#ergebnis:not([hidden])', { timeout: 30000 });

        expect(await page.textContent('#inhalt')).toBe('Kennwort: Regenschirm-7-Blau');
        expect(existiert(geheimnis.id)).toBe(false);
    });

    test('mit der falschen Passphrase kommt nichts - und der Inhalt ist verbraucht', async ({ page }) => {
        const geheimnis = await mitPassphrase(page, 'streng geheim', 'richtig');

        await page.goto('about:blank');
        await page.goto(geheimnis.pfad);
        await page.click('#anzeigen');
        await page.waitForSelector('#passphraseAbfrage:not([hidden])');

        await page.fill('#passphraseEingabe', 'falsch');
        await page.click('#passphraseAbsenden');
        await page.waitForSelector('#fehlgeschlagen:not([hidden])', { timeout: 30000 });

        // Kein Teilinhalt.
        expect(await page.textContent('#inhalt')).toBe('');
        expect(await page.content()).not.toContain('streng geheim');

        // Und der Zustand sagt, was passiert ist - und nennt die Passphrase
        // als Ursache, nicht den Schlüssel.
        const text = (await page.textContent('#fehlgeschlagen')).replace(/\s+/g, ' ');

        expect(text).toContain('trotzdem gelöscht');
        expect(text).toContain('Die Passphrase passt nicht');
        await expect(page.locator('#grundSchluessel')).toBeHidden();

        expect(existiert(geheimnis.id)).toBe(false);
    });

    test('der Link allein genügt nicht', async ({ page }) => {
        const geheimnis = await mitPassphrase(page, 'ohne Passphrase unlesbar', 'zweiter-weg');

        // Jemand, der den Link mitgelesen hat, aber die Passphrase nicht
        // kennt: Er kommt bis zur Abfrage und nicht weiter.
        await page.goto('about:blank');
        await page.goto(geheimnis.pfad);
        await page.click('#anzeigen');
        await page.waitForSelector('#passphraseAbfrage:not([hidden])');

        await page.fill('#passphraseEingabe', 'geraten');
        await page.click('#passphraseAbsenden');
        await page.waitForSelector('#fehlgeschlagen:not([hidden])', { timeout: 30000 });

        expect(await page.content()).not.toContain('ohne Passphrase unlesbar');
    });

    test('die Passphrase erreicht den Server nie', async ({ page }) => {
        const gesehen = [];
        page.on('request', (anfrage) => {
            gesehen.push(anfrage.url() + ' ' + JSON.stringify(anfrage.headers()) + ' ' + (anfrage.postData() ?? ''));
        });

        const passphrase = 'PassphraseDieNiemalsGesendetWerdenDarf-8c2f';
        const geheimnis = await mitPassphrase(page, 'Inhalt', passphrase);

        await page.goto(geheimnis.pfad);
        await page.click('#anzeigen');
        await page.waitForSelector('#passphraseAbfrage:not([hidden])');
        await page.fill('#passphraseEingabe', passphrase);
        await page.click('#passphraseAbsenden');
        await page.waitForSelector('#ergebnis:not([hidden])', { timeout: 30000 });

        expect(gesehen.length).toBeGreaterThan(3);

        for (const eintrag of gesehen) {
            expect(eintrag, 'Die Passphrase ging hinaus').not.toContain(passphrase);
            expect(eintrag).not.toContain('PassphraseDieNiemals');
        }
    });

    test('auch die Passphrase steht in keiner Adresse', async ({ page }) => {
        const adressen = [];
        page.on('request', (a) => adressen.push(a.url()));

        const geheimnis = await mitPassphrase(page, 'Inhalt', 'geheim-im-kopf');
        await zeigeAn(page, geheimnis.pfad).catch(() => {});

        for (const adresse of adressen) {
            expect(adresse).not.toContain('geheim-im-kopf');
        }
    });

    test('in der Datenbank steht nichts von beidem', async ({ page }) => {
        const geheimnis = await mitPassphrase(page, 'InhaltMitEinmaligerZeichenfolge-3d9a', 'PassphraseEinmalig-5f1c');

        expect(db('suche', 'InhaltMitEinmaligerZeichenfolge-3d9a')).toBe('0');
        expect(db('suche', 'PassphraseEinmalig-5f1c')).toBe('0');
        expect(db('suche', 'PassphraseEinmalig')).toBe('0');
    });

    test('eine leere Eingabe wird nicht als Passphrase genommen', async ({ page }) => {
        const geheimnis = await mitPassphrase(page, 'Inhalt', 'wirklich-noetig');

        await page.goto('about:blank');
        await page.goto(geheimnis.pfad);
        await page.click('#anzeigen');
        await page.waitForSelector('#passphraseAbfrage:not([hidden])');

        await page.click('#passphraseAbsenden');
        await page.waitForSelector('#fehler:not([hidden])');

        expect(await page.textContent('#fehler')).toContain('Bitte geben Sie die Passphrase ein');
        // Nichts abgerufen, nichts verbraucht.
        expect(existiert(geheimnis.id)).toBe(true);
    });
});
