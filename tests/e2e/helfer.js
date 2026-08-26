// Gemeinsame Helfer für die Browsertests.

import { execFileSync } from 'node:child_process';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const WURZEL = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');

/**
 * Ruft das Datenbankwerkzeug als Prozess auf.
 *
 * Playwright läuft in Node und spricht nicht selbst mit MariaDB. Statt einen
 * Testendpunkt in den Dienst einzubauen - der dann in der Produktion
 * mitliefe - wird hier ein Skript aufgerufen.
 */
export function db(...argumente) {
    return execFileSync('php', [path.join(WURZEL, 'tests/Support/db-tool.php'), ...argumente], {
        encoding: 'utf8',
        env: { ...process.env, EINMALPOST_CONFIG: process.env.EINMALPOST_CONFIG ?? path.join(WURZEL, 'build/config.test.php') }
    }).trim();
}

/**
 * Liegt die Zeile noch in der Datenbank?
 */
export function existiert(id) {
    return db('existiert', id) === '1';
}

/**
 * Legt ein Geheimnis über die Oberfläche an und gibt Link, ID und Schlüssel
 * zurück. Kein Umweg an der Anwendung vorbei: verschlüsselt wird im Browser.
 */
export async function erzeugeGeheimnis(page, klartext, ttl = '3600') {
    await page.goto('/');
    await page.fill('#geheimnis', klartext);
    await page.selectOption('#ttl', ttl);
    await page.click('#absenden');

    await page.waitForSelector('#ergebnis:not([hidden])', { timeout: 15000 });

    const link = await page.textContent('#link');
    const adresse = new URL(link);

    return {
        link,
        pfad: adresse.pathname + adresse.hash,
        id: adresse.pathname.split('/').pop(),
        schluessel: adresse.hash.replace(/^#/, '')
    };
}

/**
 * Die Abschnitte der Anzeigeseite. Genau einer ist jeweils sichtbar.
 */
export const ZUSTAENDE = [
    'bestaetigung',
    'ergebnis',
    'nurKopiertFertig',
    'unvollstaendig',
    'fehlgeschlagen',
    'fortgeschrieben',
    'zuVieleAnfragen'
];

/**
 * Öffnet die Anzeigeseite und drückt einen Knopf.
 *
 * @param knopf '#anzeigen' oder '#nurKopieren'
 */
export async function zeigeAn(page, pfad, knopf = '#anzeigen') {
    // Erst weg von der aktuellen Seite. Ruft man dieselbe Adresse samt
    // Fragment noch einmal auf, springt der Browser nur zum Anker und lädt
    // nicht neu - die Seite bliebe im Zustand des vorigen Abrufs. Ein
    // Empfänger öffnet den Link ohnehin in einem frischen Tab.
    await page.goto('about:blank');
    await page.goto(pfad);
    await page.click(knopf);

    await page.waitForFunction(
        (zustaende) => zustaende.some((id) => {
            const abschnitt = document.getElementById(id);

            return abschnitt && id !== 'bestaetigung' && !abschnitt.hidden;
        }),
        ZUSTAENDE,
        { timeout: 15000 }
    );

    const zustand = await page.evaluate(
        (zustaende) => zustaende.find((id) => {
            const abschnitt = document.getElementById(id);

            return abschnitt && id !== 'bestaetigung' && !abschnitt.hidden;
        }),
        ZUSTAENDE
    );

    return {
        zustand,
        erfolgreich: zustand === 'ergebnis',
        inhalt: zustand === 'ergebnis' ? await page.textContent('#inhalt') : null,
        // Leerraum vereinheitlicht: Im Quelltext umbrochene Sätze sollen
        // Prüfungen auf Textstellen nicht scheitern lassen.
        text: (await page.textContent('#' + zustand) ?? '').replace(/\s+/g, ' ').trim(),
        fehler: await page.locator('#fehler').isVisible() ? await page.textContent('#fehler') : null
    };
}
