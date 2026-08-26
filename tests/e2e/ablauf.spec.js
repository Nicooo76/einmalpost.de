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
