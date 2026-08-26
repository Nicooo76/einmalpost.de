// Was die Gestaltung nicht brechen darf.
//
// Diese Prüfungen laufen in beiden Farbschemata und auf dem Telefonprofil.
// Ein Knopf, der unter einer Fläche verschwindet, ist kein Schönheitsfehler
// - er macht den Dienst unbenutzbar.

import { test, expect } from '@playwright/test';
import { erzeugeGeheimnis, zeigeAn } from './helfer.js';

const SEITEN = ['/', '/impressum', '/datenschutz', '/sicherheit'];

test.describe('Fußbereich', () => {
    for (const pfad of SEITEN) {
        test(`steht auf ${pfad}`, async ({ page }) => {
            await page.goto(pfad);

            const verweis = page.locator('.site-footer a[href*="pixagentur.com"]');

            await expect(verweis).toBeVisible();
            await expect(verweis).toHaveText('pixagentur.com');

            // Ohne Zählparameter.
            const ziel = await verweis.getAttribute('href');
            expect(ziel).toBe('https://pixagentur.com');

            for (const name of ['FAQ', 'Sicherheit', 'Impressum', 'Datenschutz']) {
                await expect(page.locator('.site-footer').getByText(name, { exact: true })).toBeVisible();
            }

            // Der Quellcode-Verweis trägt ein Zeichen und zeigt nach außen.
            const quellcode = page.locator('.site-footer a[href*="github.com"]');
            await expect(quellcode).toBeVisible();
            await expect(quellcode.locator('svg.symbol')).toBeVisible();
            expect(await quellcode.getAttribute('rel')).toContain('noopener');
        });
    }

    test('steht auch auf der Anzeigeseite', async ({ page }) => {
        const geheimnis = await erzeugeGeheimnis(page, 'Fußbereich prüfen');

        await page.goto(geheimnis.pfad);

        const verweis = page.locator('.site-footer a[href*="pixagentur.com"]');
        await expect(verweis).toBeVisible();
        expect(await verweis.getAttribute('href')).toBe('https://pixagentur.com');
    });

    test('steht auch, nachdem das Geheimnis angezeigt wurde', async ({ page }) => {
        const geheimnis = await erzeugeGeheimnis(page, 'auch danach');

        await zeigeAn(page, geheimnis.pfad);

        await expect(page.locator('.site-footer a[href*="pixagentur.com"]')).toBeVisible();
    });
});

test.describe('Die Aufklapper laufen ohne JavaScript', () => {
    test.use({ javaScriptEnabled: false });

    test('alle FAQ-Einträge lassen sich öffnen', async ({ page }) => {
        await page.goto('/');

        const eintraege = page.locator('.faq__item');
        const anzahl = await eintraege.count();

        expect(anzahl, 'Es gibt keine FAQ-Einträge').toBeGreaterThanOrEqual(5);

        for (let i = 0; i < anzahl; i++) {
            const eintrag = eintraege.nth(i);
            const antwort = eintrag.locator('.faq__answer');

            // Zu: Die Antwort ist nicht sichtbar.
            await expect(antwort).toBeHidden();

            await eintrag.locator('.faq__question').click();

            // Auf: ohne eine Zeile JavaScript.
            await expect(antwort).toBeVisible();
            expect((await antwort.textContent())?.trim().length).toBeGreaterThan(20);
        }
    });

    /**
     * Der Hinweis steht im <noscript> und wird ausgeliefert.
     *
     * Geprüft wird der Inhalt, nicht die Sichtbarkeit: Playwright parst
     * <noscript> auch bei abgeschaltetem JavaScript als Textknoten und nicht
     * als sichtbares DOM, sodass eine Sichtbarkeitsprüfung hier nichts
     * aussagt. Dass ein echter Browser ohne JavaScript den Inhalt darstellt,
     * ist Verhalten des Browsers und nicht dieses Projekts.
     */
    test('der Hinweis auf fehlendes JavaScript wird ausgeliefert', async ({ page }) => {
        await page.goto('/');

        const hinweis = await page.locator('noscript').textContent();

        expect(hinweis).toContain('Ohne JavaScript geht es nicht');
        expect(hinweis).toContain('in Ihrem Browser statt');
    });

    test('auch die Anzeigeseite trägt den Hinweis', async ({ page }) => {
        await page.goto('/s/AAAAAAAAAAAAAAAAAAAAAA');

        expect(await page.locator('noscript').textContent()).toContain('Ohne JavaScript geht es nicht');
    });
});

test.describe('Lesbarkeit in beiden Schemata', () => {
    /**
     * Liest die tatsächlich gerenderten Farben und rechnet den Kontrast.
     * Der Wert aus dem Stylesheet nützt nichts, wenn eine Regel ihn
     * überschreibt.
     */
    async function kontrastVon(page, auswahl) {
        return page.evaluate((sel) => {
            const element = document.querySelector(sel);

            if (!element) {
                return null;
            }

            const zuRgb = (wert) => wert.match(/\d+(\.\d+)?/g).slice(0, 3).map(Number);

            const luminanz = ([r, g, b]) => {
                const k = [r, g, b].map((c) => {
                    const v = c / 255;

                    return v <= 0.03928 ? v / 12.92 : ((v + 0.055) / 1.055) ** 2.4;
                });

                return 0.2126 * k[0] + 0.7152 * k[1] + 0.0722 * k[2];
            };

            // Den ersten Vorfahren mit deckender Fläche suchen.
            let hintergrund = null;
            let knoten = element;

            while (knoten && !hintergrund) {
                const farbe = getComputedStyle(knoten).backgroundColor;

                if (farbe && !farbe.includes('rgba(0, 0, 0, 0)') && farbe !== 'transparent') {
                    hintergrund = zuRgb(farbe);
                }

                knoten = knoten.parentElement;
            }

            if (!hintergrund) {
                hintergrund = [255, 255, 255];
            }

            const vorn = luminanz(zuRgb(getComputedStyle(element).color));
            const hinten = luminanz(hintergrund);

            return (Math.max(vorn, hinten) + 0.05) / (Math.min(vorn, hinten) + 0.05);
        }, auswahl);
    }

    const ZU_PRUEFEN = [
        ['Fließtext', '.hero__lead'],
        ['Gedämpfter Hinweis', '.note'],
        ['Merkmal', '.badge'],
        ['Schritttext', '.step__text'],
        ['Fußzeile', '.site-footer__credit'],
    ];

    for (const [name, auswahl] of ZU_PRUEFEN) {
        test(`${name} ist lesbar`, async ({ page }) => {
            await page.goto('/');

            const wert = await kontrastVon(page, auswahl);

            expect(wert, `${name} (${auswahl}) nicht gefunden`).not.toBeNull();
            expect(wert, `${name}: nur ${wert?.toFixed(2)}:1`).toBeGreaterThanOrEqual(4.5);
        });
    }

    test('der Platzhalter im Eingabefeld ist lesbar', async ({ page }) => {
        await page.goto('/');

        const wert = await page.evaluate(() => {
            const feld = document.getElementById('geheimnis');
            const stil = getComputedStyle(feld, '::placeholder');
            const zuRgb = (w) => w.match(/\d+(\.\d+)?/g).slice(0, 3).map(Number);
            const lum = ([r, g, b]) => {
                const k = [r, g, b].map((c) => {
                    const v = c / 255;
                    return v <= 0.03928 ? v / 12.92 : ((v + 0.055) / 1.055) ** 2.4;
                });
                return 0.2126 * k[0] + 0.7152 * k[1] + 0.0722 * k[2];
            };
            const a = lum(zuRgb(stil.color));
            const b = lum(zuRgb(getComputedStyle(feld).backgroundColor));

            return (Math.max(a, b) + 0.05) / (Math.min(a, b) + 0.05);
        });

        expect(wert, `Platzhalter: nur ${wert.toFixed(2)}:1`).toBeGreaterThanOrEqual(4.5);
    });

    test('der Geheimnistext ist lesbar', async ({ page }) => {
        const geheimnis = await erzeugeGeheimnis(page, 'Lesbarkeit des Inhalts');
        await zeigeAn(page, geheimnis.pfad);

        const wert = await kontrastVon(page, '#inhalt');

        expect(wert, `Geheimnistext: nur ${wert?.toFixed(2)}:1`).toBeGreaterThanOrEqual(4.5);
    });
});

test.describe('Bedienbarkeit', () => {
    test('nichts schiebt die Seite seitlich weg', async ({ page }) => {
        await page.goto('/');

        const ueberstand = await page.evaluate(
            () => document.documentElement.scrollWidth - document.documentElement.clientWidth
        );

        expect(ueberstand, 'Die Seite lässt sich seitlich schieben').toBeLessThanOrEqual(1);
    });

    test('auch ein sehr langer Link schiebt nichts weg', async ({ page }) => {
        await erzeugeGeheimnis(page, 'kurz');

        const ueberstand = await page.evaluate(
            () => document.documentElement.scrollWidth - document.documentElement.clientWidth
        );

        expect(ueberstand).toBeLessThanOrEqual(1);
    });

    test('die Knöpfe sind groß genug zum Antippen', async ({ page }) => {
        await page.goto('/');

        const knopf = await page.locator('#absenden').boundingBox();

        expect(knopf, 'Der Knopf ist nicht sichtbar').not.toBeNull();
        // 44 Pixel ist die kleinste Fläche, die sich mit dem Daumen sicher
        // treffen lässt.
        expect(knopf.height, `nur ${knopf?.height}px hoch`).toBeGreaterThanOrEqual(44);
    });

    test('die Knöpfe auf der Anzeigeseite sind groß genug', async ({ page }) => {
        const geheimnis = await erzeugeGeheimnis(page, 'Knopfgröße');

        await page.goto(geheimnis.pfad);

        for (const auswahl of ['#anzeigen', '#nurKopieren']) {
            const kasten = await page.locator(auswahl).boundingBox();

            expect(kasten, auswahl + ' nicht sichtbar').not.toBeNull();
            expect(kasten.height, auswahl + `: nur ${kasten?.height}px`).toBeGreaterThanOrEqual(44);
        }
    });

    test('der Anzeigen-Knopf ist wirklich anklickbar, nicht verdeckt', async ({ page }) => {
        const geheimnis = await erzeugeGeheimnis(page, 'nicht verdeckt');

        await page.goto(geheimnis.pfad);

        // Trifft der Klick an der Mitte des Knopfes auch den Knopf?
        const trifft = await page.evaluate(() => {
            const knopf = document.getElementById('anzeigen');
            const k = knopf.getBoundingClientRect();
            const getroffen = document.elementFromPoint(k.left + k.width / 2, k.top + k.height / 2);

            return knopf.contains(getroffen) || getroffen === knopf;
        });

        expect(trifft, 'Etwas liegt über dem Anzeigen-Knopf').toBe(true);
    });
});

test.describe('Die Anzeigeseite bleibt karg', () => {
    test('sie enthält nur, was vorgesehen ist', async ({ page }) => {
        const geheimnis = await erzeugeGeheimnis(page, 'kargheit');

        await page.goto(geheimnis.pfad);

        // Kein Werbetext, keine FAQ, keine Schritte, kein Fließtext.
        for (const auswahl of ['.faq', '.steps', '.prose', '.hero', '.badges']) {
            await expect(page.locator(auswahl)).toHaveCount(0);
        }

        // Genau zwei Skripte: die gemeinsamen Bausteine und die Seitenlogik.
        const skripte = await page.locator('script').count();
        expect(skripte, 'Auf der Anzeigeseite liegen zusätzliche Skripte').toBe(2);

        // Keine Auszeichnung für Suchmaschinen - hier gibt es nichts zu finden.
        await expect(page.locator('script[type="application/ld+json"]')).toHaveCount(0);
    });

    test('sie wird nicht indexiert und nicht als Vorschau geteilt', async ({ page, request }) => {
        const geheimnis = await erzeugeGeheimnis(page, 'nicht indexieren');

        const antwort = await request.get('/s/' + geheimnis.id);

        expect(antwort.headers()['x-robots-tag']).toContain('noindex');

        const html = await antwort.text();
        expect(html).not.toContain('og:title');
        expect(html).not.toContain('og:description');
    });

    test('die Startseite dagegen wird indexiert und trägt die Auszeichnung', async ({ page, request }) => {
        // Gegenprobe: Ohne sie wäre der Test oben auch grün, wenn gar keine
        // Seite je indexiert würde.
        const antwort = await request.get('/');

        expect(antwort.headers()['x-robots-tag']).toBeUndefined();

        await page.goto('/');

        const schema = await page.locator('script[type="application/ld+json"]').textContent();
        const daten = JSON.parse(schema);

        expect(daten['@type']).toBe('FAQPage');
        expect(daten.mainEntity.length).toBeGreaterThanOrEqual(5);

        // Die Auszeichnung muss dasselbe sagen wie die sichtbare Seite.
        for (const eintrag of daten.mainEntity) {
            await expect(page.getByText(eintrag.name, { exact: true })).toBeVisible();
        }
    });
});

test.describe('Eindeutige Kennungen', () => {
    /**
     * Eine doppelt vergebene ID ist kein Schönheitsfehler.
     *
     * getElementById liefert das erste Element. Trägt der Sprunganker
     * dieselbe Kennung wie der Behälter für den Klartext, schreibt das
     * Skript den Klartext in den falschen Knoten - und löscht dabei alles
     * darin. Genau das ist beim Bauen passiert.
     */
    const SEITEN = ['/', '/impressum', '/datenschutz', '/sicherheit'];

    for (const pfad of SEITEN) {
        test(`${pfad} vergibt jede Kennung nur einmal`, async ({ page }) => {
            await page.goto(pfad);

            const doppelte = await page.evaluate(() => {
                const alle = [...document.querySelectorAll('[id]')].map((e) => e.id);

                return [...new Set(alle.filter((wert, i) => alle.indexOf(wert) !== i))];
            });

            expect(doppelte, 'Doppelt vergebene Kennungen').toEqual([]);
        });
    }

    test('die Anzeigeseite vergibt jede Kennung nur einmal', async ({ page }) => {
        await page.goto('/s/AAAAAAAAAAAAAAAAAAAAAA');

        const doppelte = await page.evaluate(() => {
            const alle = [...document.querySelectorAll('[id]')].map((e) => e.id);

            return [...new Set(alle.filter((wert, i) => alle.indexOf(wert) !== i))];
        });

        expect(doppelte, 'Doppelt vergebene Kennungen').toEqual([]);
    });

    test('der Klartext landet im vorgesehenen Behälter, nicht im Hauptbereich', async ({ page }) => {
        const geheimnis = await erzeugeGeheimnis(page, 'genau hierhin und nirgends sonst');

        await zeigeAn(page, geheimnis.pfad);

        // Im <pre>, und der Hauptbereich hat weiterhin seine Kindelemente.
        expect(await page.locator('pre#inhalt').textContent()).toBe('genau hierhin und nirgends sonst');
        expect(await page.locator('#hauptbereich > *').count()).toBeGreaterThan(3);
    });
});
