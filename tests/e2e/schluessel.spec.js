// Zusage 1: Der Schlüssel erreicht den Server nie.

import { test, expect } from '@playwright/test';
import { erzeugeGeheimnis, zeigeAn } from './helfer.js';

test.describe('Der Schlüssel bleibt im Browser', () => {
    test('kein Netzwerkverkehr enthält den Schlüssel', async ({ page }) => {
        const gesehen = [];

        // Alles mitschneiden, was den Browser verlässt: Adresse, Kopfzeilen
        // und Rumpf jeder einzelnen Anfrage.
        page.on('request', (anfrage) => {
            gesehen.push({
                url: anfrage.url(),
                methode: anfrage.method(),
                kopf: JSON.stringify(anfrage.headers()),
                rumpf: anfrage.postData() ?? ''
            });
        });

        const geheimnis = await erzeugeGeheimnis(page, 'streng geheim');
        const anzeige = await zeigeAn(page, geheimnis.pfad);

        expect(anzeige.inhalt).toBe('streng geheim');
        expect(geheimnis.schluessel.length).toBeGreaterThanOrEqual(43);
        expect(gesehen.length).toBeGreaterThan(3);

        // Der Schlüssel in der Form, in der er im Link steht ...
        const schluessel = geheimnis.schluessel;

        // ... und in den Formen, in die man ihn leicht umrechnen könnte.
        const alsBase64 = schluessel.replace(/-/g, '+').replace(/_/g, '/');
        const teilstueck = schluessel.slice(0, 16);

        for (const anfrage of gesehen) {
            const alles = anfrage.url + ' ' + anfrage.kopf + ' ' + anfrage.rumpf;

            expect(alles, `Schlüssel in ${anfrage.methode} ${anfrage.url}`).not.toContain(schluessel);
            expect(alles).not.toContain(alsBase64);
            expect(alles, 'Auch kein Teilstück des Schlüssels').not.toContain(teilstueck);
        }
    });

    test('das Fragment steht in keiner angefragten Adresse', async ({ page }) => {
        const adressen = [];
        page.on('request', (anfrage) => adressen.push(anfrage.url()));

        const geheimnis = await erzeugeGeheimnis(page, 'noch ein Geheimnis');
        await zeigeAn(page, geheimnis.pfad);

        for (const adresse of adressen) {
            expect(adresse, 'Eine Adresse enthält ein Fragment').not.toContain('#');
        }
    });

    test('der Klartext verlässt den Browser nicht', async ({ page }) => {
        const klartext = 'KlartextDerNiemalsGesendetWerdenDarf-4f2e';
        const rumpfe = [];

        page.on('request', (anfrage) => rumpfe.push(anfrage.postData() ?? ''));

        await erzeugeGeheimnis(page, klartext);

        for (const rumpf of rumpfe) {
            expect(rumpf).not.toContain(klartext);
            expect(rumpf).not.toContain('KlartextDerNiemals');
        }
    });

    test('der Schlüssel landet in keinem Browserspeicher', async ({ page }) => {
        const geheimnis = await erzeugeGeheimnis(page, 'geheim für den Speichertest');
        const anzeige = await zeigeAn(page, geheimnis.pfad);

        expect(anzeige.inhalt).toBe('geheim für den Speichertest');

        const schluessel = geheimnis.schluessel;

        // Nach dem vollständigen Durchlauf: nichts vom Schlüssel darf in einem
        // dauerhaften oder wiederauslesbaren Speicher liegen. localStorage
        // überlebt sogar das Schließen des Browsers - dort hätte der Schlüssel
        // nichts zu suchen.
        const speicher = await page.evaluate(async () => {
            const auslesen = (s) => {
                const raus = {};
                for (let i = 0; i < s.length; i++) {
                    const k = s.key(i);
                    raus[k] = s.getItem(k);
                }
                return raus;
            };

            let idbNamen = [];
            try {
                if (indexedDB.databases) {
                    idbNamen = (await indexedDB.databases()).map((d) => d.name);
                }
            } catch (e) {
                idbNamen = [];
            }

            return {
                local: auslesen(localStorage),
                session: auslesen(sessionStorage),
                idbNamen,
                cookie: document.cookie
            };
        });

        const alsText = JSON.stringify(speicher);
        expect(alsText, 'Der Schlüssel steht in einem Browserspeicher').not.toContain(schluessel);
        expect(alsText, 'Ein Teilstück des Schlüssels steht in einem Browserspeicher')
            .not.toContain(schluessel.slice(0, 16));

        // Die Anwendung schreibt gar nichts in diese Speicher.
        expect(Object.keys(speicher.local), 'localStorage ist nicht leer').toHaveLength(0);
        expect(Object.keys(speicher.session), 'sessionStorage ist nicht leer').toHaveLength(0);
        expect(speicher.idbNamen, 'Es wurde eine IndexedDB angelegt').toHaveLength(0);
        expect(speicher.cookie, 'Es wurde ein Cookie gesetzt').toBe('');

        // Gegenprobe: Der Schlüssel steht nur dort, wo er hingehört - im
        // Fragment der aktuellen Adresse, das den Browser nie verlässt.
        const hash = await page.evaluate(() => window.location.hash);
        expect(hash).toContain(schluessel);
    });

    test('ohne Schlüssel im Link gibt es keinen Inhalt', async ({ page }) => {
        const geheimnis = await erzeugeGeheimnis(page, 'ohne Schlüssel nutzlos');

        // Derselbe Link, aber ohne alles hinter dem #.
        const anzeige = await zeigeAn(page, '/s/' + geheimnis.id);

        expect(anzeige.erfolgreich).toBe(false);
        expect(anzeige.zustand).toBe('unvollstaendig');
    });
});
