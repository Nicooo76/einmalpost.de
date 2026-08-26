/**
 * Die Browserkonsole muss still bleiben.
 *
 * Ohne diese Prüfung könnte ein Skript in einem Randbereich stillschweigend
 * scheitern: Die sichtbare Seite sähe richtig aus, ein Teil der Bedienung
 * wäre tot, und kein Test würde es bemerken. Gerade hier wiegt das schwer -
 * scheitert die Verschlüsselung im Browser an einer Stelle, die niemand
 * ansieht, ist die Zusage gebrochen, ohne dass es auffällt.
 *
 * Geprüft werden echte Ausnahmen (pageerror) und console.error. Warnungen
 * bleiben außen vor: Browser warnen auch über Dinge, die niemand hier zu
 * verantworten hat.
 */

import { test, expect } from '@playwright/test';
import { erzeugeGeheimnis, zeigeAn } from './helfer.js';

// Impressum und Datenschutz gibt es nur auf Deutsch - das sind Pflichtangaben
// nach deutschem Recht und keine Übersetzungsaufgabe.
const SEITEN = [
    '/', '/impressum', '/datenschutz', '/sicherheit',
    '/en', '/en/security'
];

/**
 * Die einzige Meldung, die stehen bleiben darf.
 *
 * Ein Geheimnis, das es nicht mehr gibt, beantwortet der Dienst mit 404 -
 * absichtlich derselben Antwort wie für einen erfundenen und einen
 * abgelaufenen Link, damit die drei Fälle von außen nicht zu unterscheiden
 * sind. WebKit schreibt jede 404-Antwort in die Konsole; das lässt sich nicht
 * abstellen, ohne genau diese Ununterscheidbarkeit aufzugeben.
 *
 * Erlaubt ist deshalb nur diese eine Adresse mit diesem einen Status. Ein 404
 * auf eine fehlende Datei fällt weiterhin auf - und genau darum geht es.
 */
function istErwartet(meldung) {
    return meldung.includes('/api/reveal')
        && meldung.includes('404');
}

/**
 * Hängt sich an eine Seite und sammelt, was schiefgeht.
 */
function beobachte(page) {
    const meldungen = [];

    page.on('pageerror', (fehler) => meldungen.push('Ausnahme: ' + fehler.message));
    page.on('console', (m) => {
        if (m.type() !== 'error') {
            return;
        }

        const ort     = m.location() ? m.location().url : '';
        const meldung = 'console.error [' + ort + ']: ' + m.text();

        if (!istErwartet(meldung)) {
            meldungen.push(meldung);
        }
    });

    return meldungen;
}

test.describe('Browserkonsole', () => {
    for (const pfad of SEITEN) {
        test(`${pfad} lädt ohne Fehler`, async ({ page }) => {
            const meldungen = beobachte(page);

            // Nicht 'networkidle': Das wartet nach der letzten Anfrage noch
            // eine halbe Sekunde ab und macht diese acht Prüfungen über sechs
            // Browserprofile zum langsamsten Teil des ganzen Laufs. Für
            // Skriptfehler genügt 'load' - danach ist alles ausgeführt, was
            // beim Laden ausgeführt wird.
            await page.goto(pfad);
            await page.waitForLoadState('load');

            expect(meldungen, meldungen.join(' | ')).toEqual([]);
        });
    }

    test('ein vollständiger Durchlauf bleibt still', async ({ page }) => {
        const meldungen = beobachte(page);

        await page.goto('/');
        await page.fill('#geheimnis', 'ohne einen Mucks');
        await page.click('#absenden');
        await page.waitForSelector('#ergebnis:not([hidden])');

        // Auch der QR-Code, der erst auf Knopfdruck entsteht.
        await page.click('#qrZeigen');
        await page.waitForSelector('#qrFlaeche svg');

        const adresse = new URL(await page.textContent('#link'));
        const abruf = await zeigeAn(page, adresse.pathname + adresse.hash);

        expect(abruf.zustand).toBe('ergebnis');
        expect(abruf.inhalt).toBe('ohne einen Mucks');
        expect(meldungen, meldungen.join(' | ')).toEqual([]);
    });

    test('auch der Fehlweg bleibt still', async ({ page }) => {
        const meldungen = beobachte(page);

        // Ein Link, hinter dem nichts mehr liegt: Der Dienst muss das
        // ordentlich anzeigen, nicht mit einer Ausnahme abbrechen.
        const geheimnis = await erzeugeGeheimnis(page, 'einmal und weg');

        expect((await zeigeAn(page, geheimnis.pfad)).zustand).toBe('ergebnis');

        // Beim zweiten Mal ist nichts mehr da. Das ist ein anderer Zustand als
        // eine misslungene Entschlüsselung - und beide müssen ohne Ausnahme
        // in der Konsole auskommen.
        expect((await zeigeAn(page, geheimnis.pfad)).zustand).toBe('fortgeschrieben');

        expect(meldungen, meldungen.join(' | ')).toEqual([]);
    });
});
