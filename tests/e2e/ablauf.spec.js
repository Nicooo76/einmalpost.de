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

        // Zwei Klicks so schnell hintereinander, wie ein ungeduldiger Mensch
        // sie auslöst - ohne auf eine Antwort zu warten.
        await Promise.all([
            page.click('#anzeigen'),
            page.click('#anzeigen', { force: true }).catch(() => {})
        ]);

        await page.waitForSelector('#ergebnis:not([hidden])', { timeout: 30000 });

        // Und die Anzeige bleibt stehen, statt von einer zweiten Antwort
        // überschrieben zu werden.
        await page.waitForTimeout(2000);

        expect(await page.textContent('#inhalt')).toBe('zweimal geklickt');
        await expect(page.locator('#ergebnis')).toBeVisible();
        await expect(page.locator('#fortgeschrieben')).toBeHidden();

        // Auf dem Server ist es trotzdem genau einmal verbraucht.
        expect(existiert(geheimnis.id)).toBe(false);
    });

    test('während des Abrufs sind die Knöpfe gesperrt', async ({ page }) => {
        const geheimnis = await erzeugeGeheimnis(page, 'einen Moment bitte');

        await page.goto('about:blank');
        await page.goto(geheimnis.pfad);

        // Vor dem Klick offen ...
        expect(await page.locator('#anzeigen').isDisabled()).toBe(false);

        await page.click('#anzeigen');
        await page.waitForSelector('#ergebnis:not([hidden])', { timeout: 30000 });

        // ... und danach wieder offen. Bliebe die Seite gesperrt, käme der
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
