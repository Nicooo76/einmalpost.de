// Befund: Die Anzeigeseite ließ sich in einen fremden Rahmen einbetten.
//
// Das greift Zusage 3 nicht am Wortlaut, sondern am Zweck an: Ein Angreifer
// bettet /s/{id} unsichtbar ein und bringt jemanden dazu, den Anzeigen-Knopf
// zu klicken. Er bekommt den Klartext nicht (der Schlüssel fehlt ihm, und die
// gleiche Ursprungsregel verwehrt ihm das Auslesen des Rahmens), aber er
// vernichtet das Geheimnis, bevor der Empfänger es sieht.
//
// Der Schutz ist frame-ancestors 'none' in der CSP.

import { test, expect } from '@playwright/test';
import { erzeugeGeheimnis, existiert } from './helfer.js';

test.describe('Clickjacking-Schutz', () => {
    test('die Anzeigeseite lässt sich nicht in einen fremden Rahmen einbetten', async ({ page }) => {
        const geheimnis = await erzeugeGeheimnis(page, 'darf nicht aus fremdem Rahmen verbrannt werden');

        // Eine angreifende Seite bettet die Anzeigeseite in einen Rahmen. Sie
        // wird per Route eingeschoben, damit sie eine echte Seite mit eigener
        // Herkunft ist (nicht about:blank, das ein Rahmennavigieren gesondert
        // behandelt). frame-ancestors 'none' verbietet jede Einbettung - auch
        // die aus gleicher Herkunft, die hier geprüft wird und die strengere
        // Probe ist als eine fremde Herkunft.
        await page.route('**/angreifer-clickjacking', (route) => route.fulfill({
            contentType: 'text/html',
            body: `<!doctype html><h1>Gewinnspiel</h1>`
                + `<iframe id="opfer" src="/s/${geheimnis.id}" width="400" height="300"></iframe>`,
        }));

        // Nur auf domcontentloaded warten: Das load-Ereignis schließt die
        // Unterrahmen ein, und ein durch die CSP blockierter Rahmen bleibt in
        // Firefox "pending" - goto liefe sonst in einen Timeout.
        await page.goto('/angreifer-clickjacking', { waitUntil: 'domcontentloaded' });
        await page.waitForTimeout(1500);

        // Hat der Rahmen die Anzeigeseite geladen, steht darin der
        // Anzeigen-Knopf; ein Klick darauf würde das Geheimnis verbrennen. Mit
        // frame-ancestors 'none' bleibt der Rahmen leer.
        //
        // Die drei Browser verhalten sich beim blockierten Rahmen verschieden:
        // Firefox lässt einen Zugriff hängen, WebKit behält die Rahmenadresse.
        // Deshalb wird der Inhalt geprüft, aber gegen einen Zeitpuffer -
        // liefert der Zugriff nicht binnen drei Sekunden, gilt der Rahmen als
        // leer (blockiert).
        const knopfImRahmen = async (frame) => Promise.race([
            frame.locator('#anzeigen').count().then((n) => n > 0).catch(() => false),
            new Promise((auf) => setTimeout(() => auf(false), 3000)),
        ]);

        const kinder = page.frames().filter((f) => f.parentFrame() === page.mainFrame());
        let geframt = false;
        for (const kind of kinder) {
            if (await knopfImRahmen(kind)) {
                geframt = true;
            }
        }

        expect(
            geframt,
            'Die Anzeigeseite ließ sich in einen fremden Rahmen einbetten - Clickjacking und '
            + 'unbeabsichtigtes Verbrennen des Geheimnisses sind möglich.'
        ).toBe(false);

        // Das Geheimnis darf durch den Einbettungsversuch nicht verbraucht sein.
        expect(existiert(geheimnis.id), 'Der Einbettungsversuch hat das Geheimnis verbraucht').toBe(true);
    });
});
