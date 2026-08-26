// Zusage 6: Falscher Schlüssel liefert keinen Teilklartext.
// Zusage 7: Ein gekipptes Bit im payload lässt den Abruf fehlschlagen.

import { test, expect } from '@playwright/test';
import { db, erzeugeGeheimnis, zeigeAn } from './helfer.js';

const KLARTEXT = 'Streng vertraulich: Zugang zum Abrechnungssystem, Kennwort Regenschirm42';

/**
 * Erzeugt einen anderen, gültig aufgebauten Schlüssel.
 */
function andererSchluessel() {
    const bytes = new Uint8Array(32);
    for (let i = 0; i < 32; i++) {
        bytes[i] = (i * 7 + 3) % 256;
    }

    return Buffer.from(bytes).toString('base64url');
}

test.describe('Manipulation', () => {
    test('Zusage 6: ein falscher Schlüssel liefert nichts, auch keinen Teil', async ({ page }) => {
        const geheimnis = await erzeugeGeheimnis(page, KLARTEXT);

        const anzeige = await zeigeAn(page, '/s/' + geheimnis.id + '#' + andererSchluessel());

        expect(anzeige.erfolgreich).toBe(false);
        expect(anzeige.inhalt).toBeNull();
        expect(anzeige.zustand).toBe('fehlgeschlagen');
        expect(anzeige.text).toContain('trotzdem gelöscht');
        // Ohne Passphrase im Link ist der Schlüssel die richtige Erklärung.
        expect(anzeige.text).toContain('Der Schlüssel im Link passt nicht');

        // Nirgends auf der Seite darf ein Bruchstück des Klartexts stehen.
        const seite = await page.content();
        expect(seite).not.toContain('Regenschirm42');
        expect(seite).not.toContain('Abrechnungssystem');
        expect(seite).not.toContain('Streng vertraulich');

        // Auch das Anzeigefeld selbst bleibt leer.
        expect(await page.locator('#inhalt').textContent()).toBe('');
    });

    test('Zusage 6: ein um ein Zeichen veränderter Schlüssel liefert nichts', async ({ page }) => {
        const geheimnis = await erzeugeGeheimnis(page, KLARTEXT);

        // Nur das erste Zeichen des Schlüssels austauschen.
        const erstes = geheimnis.schluessel[0] === 'A' ? 'B' : 'A';
        const verbogen = erstes + geheimnis.schluessel.slice(1);

        const anzeige = await zeigeAn(page, '/s/' + geheimnis.id + '#' + verbogen);

        expect(anzeige.erfolgreich).toBe(false);
        expect(await page.locator('#inhalt').textContent()).toBe('');
    });

    test('Zusage 7: ein gekipptes Bit im Tag lässt den Abruf fehlschlagen', async ({ page }) => {
        const geheimnis = await erzeugeGeheimnis(page, KLARTEXT);

        expect(db('kippe-bit', geheimnis.id)).toBe('1');

        const anzeige = await zeigeAn(page, geheimnis.pfad);

        expect(anzeige.erfolgreich).toBe(false);
        expect(anzeige.inhalt).toBeNull();
        expect(await page.locator('#inhalt').textContent()).toBe('');
    });

    test('Zusage 7: ein gekipptes Bit im IV lässt den Abruf fehlschlagen', async ({ page }) => {
        const geheimnis = await erzeugeGeheimnis(page, KLARTEXT);

        expect(db('kippe-iv', geheimnis.id)).toBe('1');

        const anzeige = await zeigeAn(page, geheimnis.pfad);

        expect(anzeige.erfolgreich).toBe(false);
        expect(await page.locator('#inhalt').textContent()).toBe('');
    });

    test('ein manipuliertes Geheimnis ist danach trotzdem verbraucht', async ({ page }) => {
        const geheimnis = await erzeugeGeheimnis(page, KLARTEXT);
        db('kippe-bit', geheimnis.id);

        const erster = await zeigeAn(page, geheimnis.pfad);
        expect(erster.erfolgreich).toBe(false);

        // Der Server hat beim Abruf gelöscht - unabhängig davon, ob der
        // Browser damit etwas anfangen konnte. Das ist Absicht: Sonst
        // ließe sich durch Manipulation ein zweiter Versuch erzwingen.
        const zweiter = await zeigeAn(page, geheimnis.pfad);
        expect(zweiter.erfolgreich).toBe(false);
        expect(zweiter.zustand).toBe('fortgeschrieben');
    });

    test('eine erfundene ID liefert dieselbe Meldung wie eine verbrauchte', async ({ page }) => {
        const erfunden = await zeigeAn(page, '/s/AAAAAAAAAAAAAAAAAAAAAA#' + andererSchluessel());

        expect(erfunden.erfolgreich).toBe(false);
        expect(erfunden.zustand).toBe('fortgeschrieben');
    });
});
