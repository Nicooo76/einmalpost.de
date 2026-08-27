// Erzeugt die Rasterfassungen der Bildmarke aus dem SVG-Master.
//
// Liest  public/assets/img/favicon.svg  und schreibt daneben:
//   favicon.ico          — 16, 32 und 48 Pixel als PNG-Einträge
//   apple-touch-icon.png — 180 × 180, deckend auf Markenblau (iOS rundet selbst)
//
// Aufruf:  node tools/bildmarke-rastern.mjs
//
// Die Bildmarke gehört nicht ins Repository (CLAUDE.md, Abschnitt 12);
// dieses Werkzeug läuft deshalb nur, wo der Master vorhanden ist, und
// gehört nicht auf den Server. Gerastert wird mit dem ohnehin
// vorhandenen Playwright-Chromium — keine zusätzliche Abhängigkeit.

import { chromium } from '@playwright/test';
import { readFileSync, writeFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';

const imgVerzeichnis = fileURLToPath(new URL('../public/assets/img/', import.meta.url));
const svg = readFileSync(imgVerzeichnis + 'favicon.svg', 'utf8');

const browser = await chromium.launch();
const page = await browser.newPage();

/**
 * Rendert das SVG randlos in der gewünschten Kantenlänge.
 * Deckend (für iOS) läuft der Kachelhintergrund in die Ecken aus;
 * transparent (für die ICO-Einträge) bleiben die Rundungen erhalten.
 */
async function rastern(kantenlaenge, deckend) {
    await page.setViewportSize({ width: kantenlaenge, height: kantenlaenge });
    const grund = deckend ? 'background:#141B2E;' : '';
    await page.setContent(
        `<style>*{margin:0}html,body{${grund}}svg{display:block;width:${kantenlaenge}px;height:${kantenlaenge}px}</style>${svg}`
    );
    return page.screenshot({ omitBackground: !deckend });
}

const eintraege = [];
for (const kantenlaenge of [16, 32, 48]) {
    eintraege.push([kantenlaenge, await rastern(kantenlaenge, false)]);
}
const appleTouch = await rastern(180, true);
await browser.close();

// ICO von Hand zusammensetzen: 6 Byte Kopf, je Eintrag 16 Byte Verzeichnis,
// danach die PNG-Daten. PNG-Einträge versteht jeder heutige Browser.
function icoAusPngs(bilder) {
    const kopf = Buffer.alloc(6);
    kopf.writeUInt16LE(1, 2); // Typ 1 = Icon
    kopf.writeUInt16LE(bilder.length, 4);

    let versatz = 6 + 16 * bilder.length;
    const verzeichnis = [];
    for (const [kantenlaenge, png] of bilder) {
        const eintrag = Buffer.alloc(16);
        eintrag.writeUInt8(kantenlaenge % 256, 0);  // Breite (0 hieße 256)
        eintrag.writeUInt8(kantenlaenge % 256, 1);  // Höhe
        eintrag.writeUInt16LE(1, 4);                // Farbebenen
        eintrag.writeUInt16LE(32, 6);               // Bit je Pixel
        eintrag.writeUInt32LE(png.length, 8);
        eintrag.writeUInt32LE(versatz, 12);
        versatz += png.length;
        verzeichnis.push(eintrag);
    }
    return Buffer.concat([kopf, ...verzeichnis, ...bilder.map(([, png]) => png)]);
}

writeFileSync(imgVerzeichnis + 'favicon.ico', icoAusPngs(eintraege));
writeFileSync(imgVerzeichnis + 'apple-touch-icon.png', appleTouch);

console.log('geschrieben: favicon.ico (16/32/48) und apple-touch-icon.png (180) in public/assets/img/');
