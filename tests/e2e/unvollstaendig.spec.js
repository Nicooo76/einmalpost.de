// Ein gekürzter Link darf das Geheimnis nicht verbrennen.
//
// Chat- und Mailprogramme kürzen lange Adressen beim Anzeigen. Die ID im
// Pfad ist dann meist noch vollständig, der Schlüssel hinter dem # nicht.
// Würde die Seite trotzdem abrufen, löschte der Server den Inhalt - und die
// Entschlüsselung scheiterte danach. Das Geheimnis wäre vernichtet, ohne
// dass es jemand gelesen hat.

import { test, expect } from '@playwright/test';
import { existiert, erzeugeGeheimnis, zeigeAn } from './helfer.js';

/**
 * Öffnet die Seite, drückt Anzeigen und schneidet dabei mit, ob eine
 * Anfrage an /api/reveal hinausging.
 */
async function versuchOhneAbruf(page, pfad) {
    const abrufe = [];

    await page.goto('about:blank');
    page.on('request', (anfrage) => {
        if (anfrage.url().includes('/api/reveal')) {
            abrufe.push(anfrage.url());
        }
    });

    await page.goto(pfad);
    await page.click('#anzeigen');
    await page.waitForSelector('#unvollstaendig:not([hidden])', { timeout: 10000 });

    // Kurz warten, falls doch noch etwas hinausginge.
    await page.waitForTimeout(300);

    return abrufe;
}

test.describe('Unvollständiger Link', () => {
    test('ohne Fragment wird nichts abgerufen und nichts verbraucht', async ({ page }) => {
        const geheimnis = await erzeugeGeheimnis(page, 'darf das überleben');

        const abrufe = await versuchOhneAbruf(page, '/s/' + geheimnis.id);

        expect(abrufe, 'Es wurde trotzdem abgerufen').toHaveLength(0);
        expect(existiert(geheimnis.id), 'Das Geheimnis wurde verbraucht').toBe(true);

        // Und der Empfänger bekommt es mit dem vollständigen Link noch.
        const anzeige = await zeigeAn(page, geheimnis.pfad);
        expect(anzeige.inhalt).toBe('darf das überleben');
    });

    test('ein leeres Fragment verbraucht nichts', async ({ page }) => {
        const geheimnis = await erzeugeGeheimnis(page, 'auch das überlebt');

        const abrufe = await versuchOhneAbruf(page, '/s/' + geheimnis.id + '#');

        expect(abrufe).toHaveLength(0);
        expect(existiert(geheimnis.id)).toBe(true);
    });

    /**
     * Ein Schlüssel sind genau 43 Zeichen base64url. Alles andere ist ein
     * gekürzter oder verfälschter Link.
     */
    const GEKUERZT = [
        ['ein Zeichen zu wenig', 42],
        ['zehn Zeichen zu wenig', 33],
        ['die Hälfte', 21],
        ['ein einzelnes Zeichen', 1]
    ];

    for (const [name, laenge] of GEKUERZT) {
        test(`${name} (${laenge} statt 43) verbraucht nichts`, async ({ page }) => {
            const geheimnis = await erzeugeGeheimnis(page, 'unversehrt');

            const gekuerzt = geheimnis.schluessel.slice(0, laenge);
            const abrufe = await versuchOhneAbruf(page, '/s/' + geheimnis.id + '#' + gekuerzt);

            expect(abrufe, 'Ein gekürzter Schlüssel löste einen Abruf aus').toHaveLength(0);
            expect(existiert(geheimnis.id)).toBe(true);
        });
    }

    test('ein zu langer Schlüssel verbraucht nichts', async ({ page }) => {
        const geheimnis = await erzeugeGeheimnis(page, 'unversehrt');

        const abrufe = await versuchOhneAbruf(page, '/s/' + geheimnis.id + '#' + geheimnis.schluessel + 'A');

        expect(abrufe).toHaveLength(0);
        expect(existiert(geheimnis.id)).toBe(true);
    });

    test('Zeichen außerhalb des Alphabets verbrauchen nichts', async ({ page }) => {
        const geheimnis = await erzeugeGeheimnis(page, 'unversehrt');

        // 43 Zeichen, aber mit einem Zeichen, das in base64url nicht vorkommt.
        const verfaelscht = '!' + geheimnis.schluessel.slice(1);
        const abrufe = await versuchOhneAbruf(page, '/s/' + geheimnis.id + '#' + verfaelscht);

        expect(abrufe).toHaveLength(0);
        expect(existiert(geheimnis.id)).toBe(true);
    });

    test('der Hinweis sagt, dass nichts abgerufen wurde', async ({ page }) => {
        const geheimnis = await erzeugeGeheimnis(page, 'egal');

        await page.goto('/s/' + geheimnis.id);
        await page.click('#anzeigen');
        await page.waitForSelector('#unvollstaendig:not([hidden])');

        const text = await page.textContent('#unvollstaendig');

        expect(text).toContain('UNVOLLSTÄNDIG');
        expect(text).toContain('Der Inhalt ist noch da');
        expect(text).toContain('#');
    });

    test('mit dem vollständigen Schlüssel wird sehr wohl abgerufen', async ({ page }) => {
        // Gegenprobe: Ohne sie wären die Tests oben auch dann grün, wenn der
        // Knopf überhaupt nichts täte.
        const geheimnis = await erzeugeGeheimnis(page, 'jetzt aber');

        const abrufe = [];
        await page.goto('about:blank');
        page.on('request', (a) => a.url().includes('/api/reveal') && abrufe.push(a.url()));

        await page.goto(geheimnis.pfad);
        await page.click('#anzeigen');
        await page.waitForSelector('#ergebnis:not([hidden])');

        expect(abrufe).toHaveLength(1);
        expect(await page.textContent('#inhalt')).toBe('jetzt aber');
        expect(existiert(geheimnis.id)).toBe(false);
    });

    test('ERNEUT VERSUCHEN lädt die Seite neu', async ({ page }) => {
        const geheimnis = await erzeugeGeheimnis(page, 'zweiter Anlauf');

        await page.goto('/s/' + geheimnis.id);
        await page.click('#anzeigen');
        await page.waitForSelector('#unvollstaendig:not([hidden])');

        await page.click('#erneut');
        await page.waitForSelector('#bestaetigung:not([hidden])');

        // Immer noch kein Schlüssel im Link, also immer noch unversehrt.
        expect(existiert(geheimnis.id)).toBe(true);
    });
});

test.describe('Kopieren, ohne anzuzeigen', () => {
    /**
     * Der Zustand nach dem Kopieren.
     *
     * Gelingt der Zugriff auf die Zwischenablage nicht - in WebKit und
     * Firefox ist er nach einem await nicht immer erlaubt -, zeigt die Seite
     * den Text bewusst doch an. Er wäre sonst verloren: Der Server hat ihn
     * beim Abruf gelöscht. Beides ist ein gültiger Ausgang, ein stiller
     * Verlust wäre es nicht.
     */
    function istGueltigerAusgang(zustand) {
        return zustand === 'nurKopiertFertig' || zustand === 'ergebnis';
    }

    test('der Text wird nicht angezeigt, wenn die Zwischenablage erreichbar ist', async ({ page, browserName }) => {
        const geheimnis = await erzeugeGeheimnis(page, 'nur in die Zwischenablage');

        const anzeige = await zeigeAn(page, geheimnis.pfad, '#nurKopieren');

        expect(istGueltigerAusgang(anzeige.zustand), `unerwarteter Zustand: ${anzeige.zustand}`).toBe(true);

        if (anzeige.zustand === 'nurKopiertFertig') {
            expect(anzeige.text).toContain('KOPIERT UND GELÖSCHT');
            // Der Klartext steht nirgends auf der Seite.
            expect(await page.content()).not.toContain('nur in die Zwischenablage');
            expect(await page.textContent('#inhalt')).toBe('');
        } else {
            // Der dokumentierte Rückfall: angezeigt, mit Begründung.
            expect(anzeige.fehler, `${browserName}: Rückfall ohne Hinweis`).toContain('Kopieren war nicht möglich');
            expect(anzeige.inhalt).toBe('nur in die Zwischenablage');
        }

        // In jedem Fall verbraucht.
        expect(existiert(geheimnis.id)).toBe(false);
    });

    test('auch dieser Weg verbraucht das Geheimnis', async ({ page }) => {
        const geheimnis = await erzeugeGeheimnis(page, 'einmal ist einmal');

        const erster = await zeigeAn(page, geheimnis.pfad, '#nurKopieren');
        expect(istGueltigerAusgang(erster.zustand)).toBe(true);

        const zweiter = await zeigeAn(page, geheimnis.pfad, '#nurKopieren');
        expect(zweiter.zustand).toBe('fortgeschrieben');
    });

    test('der Text landet tatsächlich in der Zwischenablage', async ({ page, browserName, context }) => {
        // Nur Chromium erlaubt es, den Inhalt der Zwischenablage zu lesen.
        // In Firefox und WebKit lässt sich diese Zusicherung nicht prüfen -
        // deshalb steht das hier und nicht in einem stillen Übersprung.
        test.skip(browserName !== 'chromium', 'Die Zwischenablage ist nur in Chromium auslesbar.');

        await context.grantPermissions(['clipboard-read', 'clipboard-write']);

        const geheimnis = await erzeugeGeheimnis(page, 'wortwoertlich hierhin');
        const anzeige = await zeigeAn(page, geheimnis.pfad, '#nurKopieren');

        expect(anzeige.zustand).toBe('nurKopiertFertig');
        expect(await page.evaluate(() => navigator.clipboard.readText())).toBe('wortwoertlich hierhin');
    });
});
