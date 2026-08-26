// Zusage 5: Vorschau-Bots verbrennen nichts.
//
// Slack, Teams und Microsoft Safe Links rufen Links automatisch ab. Würde
// GET das Geheimnis verbrauchen, wäre es weg, bevor der Empfänger es sieht.

import { test, expect } from '@playwright/test';
import { existiert, erzeugeGeheimnis, zeigeAn } from './helfer.js';

const BOTS = [
    ['Slackbot', 'Slackbot-LinkExpanding 1.0 (+https://api.slack.com/robots)'],
    ['Twitterbot', 'Twitterbot/1.0'],
    ['Facebook', 'facebookexternalhit/1.1'],
    ['Outlook / Safe Links', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120 Safari/537.36 BingPreview/1.0b'],
    ['Microsoft Office', 'Microsoft Office Word 2014'],
    ['Discord', 'Mozilla/5.0 (compatible; Discordbot/2.0; +https://discordapp.com)'],
    ['WhatsApp', 'WhatsApp/2.23'],
    ['curl', 'curl/8.4.0'],
    ['wget', 'Wget/1.21.4'],
    ['Googlebot', 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)']
];

test.describe('Vorschau-Bots', () => {
    for (const [name, kennung] of BOTS) {
        test(`${name} verbrennt das Geheimnis nicht`, async ({ page, request }) => {
            const geheimnis = await erzeugeGeheimnis(page, `überlebt ${name}`);

            // So wie der Bot es täte: GET und HEAD auf die Anzeigeseite,
            // ohne JavaScript, ohne POST.
            const holen = await request.get('/s/' + geheimnis.id, { headers: { 'User-Agent': kennung } });
            expect(holen.status()).toBe(200);

            const kopf = await request.head('/s/' + geheimnis.id, { headers: { 'User-Agent': kennung } });
            expect(kopf.status()).toBe(200);

            // Auch die Startseite und ein Nachfassen mit weiteren Abrufen.
            await request.get('/s/' + geheimnis.id, { headers: { 'User-Agent': kennung } });
            await request.get('/s/' + geheimnis.id + '?utm_source=test', { headers: { 'User-Agent': kennung } });

            expect(existiert(geheimnis.id), `${name} hat das Geheimnis verbraucht`).toBe(true);

            // Und der Empfänger bekommt es danach vollständig.
            const anzeige = await zeigeAn(page, geheimnis.pfad);
            expect(anzeige.inhalt).toBe(`überlebt ${name}`);
        });
    }

    test('die Anzeigeseite gibt Bots nichts Verwertbares', async ({ page, request }) => {
        const geheimnis = await erzeugeGeheimnis(page, 'GeheimerInhaltFuerBots-7a3c');

        const antwort = await request.get('/s/' + geheimnis.id, {
            headers: { 'User-Agent': 'Slackbot-LinkExpanding 1.0' }
        });

        const html = await antwort.text();

        expect(html).not.toContain('GeheimerInhaltFuerBots');
        expect(html).not.toContain(geheimnis.schluessel);
        // Die Seite kennt nicht einmal die ID - sie steht nur in der Adresse.
        expect(html).not.toContain('payload');
    });
});
