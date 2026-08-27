// Der Grundlauf und die Kernzusage: genau einmal.

import { test, expect } from '@playwright/test';
import { db, existiert, erzeugeGeheimnis, zeigeAn } from './helfer.js';

test.describe('Grundlauf', () => {
    test('Text eingeben, Link bekommen, einmal anzeigen', async ({ page }) => {
        const klartext = 'Zugang zum Kundenportal: hunter2';

        const geheimnis = await erzeugeGeheimnis(page, klartext);

        expect(geheimnis.id).toHaveLength(22);
        expect(geheimnis.schluessel.length).toBeGreaterThanOrEqual(43);
        expect(geheimnis.link).toContain('#');

        const anzeige = await zeigeAn(page, geheimnis.pfad);

        expect(anzeige.erfolgreich).toBe(true);
        expect(anzeige.inhalt).toBe(klartext);
    });

    test('Zusage 3: der zweite Abruf zeigt nichts mehr', async ({ page }) => {
        const geheimnis = await erzeugeGeheimnis(page, 'nur einmal');

        const erster = await zeigeAn(page, geheimnis.pfad);
        expect(erster.inhalt).toBe('nur einmal');

        const zweiter = await zeigeAn(page, geheimnis.pfad);
        expect(zweiter.erfolgreich).toBe(false);
        expect(zweiter.inhalt).toBeNull();
        expect(zweiter.zustand).toBe('fortgeschrieben');
        expect(zweiter.text).toContain('GIBT ES NICHT MEHR');
    });

    test('Zusage 3 am Zweck: ein Doppelklick verliert das Geheimnis nicht', async ({ page }) => {
        // Der Server hält die Zusage auch ohne Riegel - DELETE ... RETURNING
        // gibt genau einem der beiden Abrufe den Inhalt. Im Browser liefen
        // aber beide in dieselbe Anzeige: Die 404-Antwort des zweiten blendete
        // den gerade angezeigten Klartext wieder aus. Für den Empfänger war
        // das Geheimnis damit verloren, obwohl es korrekt ausgeliefert wurde.
        const geheimnis = await erzeugeGeheimnis(page, 'zweimal geklickt');

        await page.goto('about:blank');
        await page.goto(geheimnis.pfad);

        // Jede Anfrage an /api/reveal zählen. Zwei wären genau der Fehler.
        const abrufe = [];
        page.on('request', (r) => {
            if (r.url().includes('/api/reveal') && r.method() === 'POST') {
                abrufe.push(r.url());
            }
        });

        // Zwei Klicks ohne Umweg über Playwright, das sonst auf einen
        // klickbaren Knopf warten würde. Der zweite hebt die Sperre am Knopf
        // vorher auf - sonst prüfte der Test nur `disabled` und nicht den
        // Riegel dahinter.
        await page.evaluate(() => {
            const knopf = document.getElementById('anzeigen');

            knopf.click();
            knopf.disabled = false;
            knopf.click();
        });

        await page.waitForSelector('#ergebnis:not([hidden])', { timeout: 30000 });
        await page.waitForTimeout(2000);

        expect(abrufe).toHaveLength(1);
        expect(await page.textContent('#inhalt')).toBe('zweimal geklickt');
        await expect(page.locator('#ergebnis')).toBeVisible();
        await expect(page.locator('#fortgeschrieben')).toBeHidden();

        // Auf dem Server ist es trotzdem genau einmal verbraucht.
        expect(existiert(geheimnis.id)).toBe(false);
    });

    test('während des Abrufs ist der Knopf gesperrt', async ({ page }) => {
        const geheimnis = await erzeugeGeheimnis(page, 'einen Moment bitte');

        await page.goto('about:blank');
        await page.goto(geheimnis.pfad);

        expect(await page.locator('#anzeigen').isDisabled()).toBe(false);

        // Unmittelbar nach dem Klick, noch während der Abruf läuft.
        const gesperrt = await page.evaluate(() => {
            document.getElementById('anzeigen').click();

            return document.getElementById('anzeigen').disabled;
        });

        expect(gesperrt).toBe(true);

        await page.waitForSelector('#ergebnis:not([hidden])', { timeout: 30000 });

        // Und danach wieder offen. Bliebe die Seite gesperrt, käme der
        // Empfänger nach einem Fehler an nichts mehr heran.
        expect(await page.locator('#anzeigen').isDisabled()).toBe(false);
    });

    test('Zusage 2: der Klartext liegt nie in der Datenbank', async ({ page }) => {
        const klartext = 'ZeichenketteDieNirgendsSonstVorkommt-9c1f4b7a';

        const geheimnis = await erzeugeGeheimnis(page, klartext);

        // Solange das Geheimnis noch da liegt: der Klartext darf in keinem
        // gespeicherten payload zu finden sein.
        expect(existiert(geheimnis.id)).toBe(true);
        expect(db('suche', klartext)).toBe('0');
        expect(db('suche', 'ZeichenketteDieNirgendsSonstVorkommt')).toBe('0');

        // Auch Teilstücke nicht.
        expect(db('suche', '9c1f4b7a')).toBe('0');

        const anzeige = await zeigeAn(page, geheimnis.pfad);
        expect(anzeige.inhalt).toBe(klartext);
        expect(existiert(geheimnis.id)).toBe(false);
    });

    test('Zusage 9: ein abgelaufenes Geheimnis wird nicht ausgeliefert', async ({ page }) => {
        const geheimnis = await erzeugeGeheimnis(page, 'zu spät');

        // Kein Cron, kein Event - die Zeile liegt noch da.
        expect(db('verfallen', geheimnis.id)).toBe('1');
        expect(existiert(geheimnis.id)).toBe(true);

        const anzeige = await zeigeAn(page, geheimnis.pfad);

        expect(anzeige.erfolgreich).toBe(false);
        expect(anzeige.zustand).toBe('fortgeschrieben');
    });

    test('Zusage 4: die Anzeigeseite allein verbraucht nichts', async ({ page }) => {
        const geheimnis = await erzeugeGeheimnis(page, 'überlebt das Aufrufen');

        // Seite mehrfach öffnen, ohne den Knopf zu drücken.
        for (let i = 0; i < 5; i++) {
            await page.goto(geheimnis.pfad);
            await page.waitForSelector('#anzeigen');
        }

        expect(existiert(geheimnis.id)).toBe(true);

        const anzeige = await zeigeAn(page, geheimnis.pfad);
        expect(anzeige.inhalt).toBe('überlebt das Aufrufen');
    });
});
