/**
 * Funktionsprobe gegen eine laufende Installation.
 *
 * verify-live prüft Kopfzeilen und Konfiguration - also das, was der Server
 * sagt. Diese Probe prüft, was er tut: einen vollständigen Durchlauf durch
 * einen echten Browser, mit Passphrase, QR-Code und zweitem Abruf.
 *
 * Sie legt dabei echte Geheimnisse an. Die verbraucht sie im selben Lauf
 * wieder; liegen bleibt nichts, was nicht ohnehin nach einer Stunde
 * verschwinden würde.
 *
 *   node tools/live-funktionsprobe.mjs https://einmalpost.de
 *
 * Rückgabewert 1, sobald eine Prüfung fehlschlägt.
 */

import { chromium } from 'playwright';

const ZIEL = (process.argv[2] || 'https://einmalpost.de').replace(/\/$/, '');
const MARKE = 'Funktionsprobe-' + process.hrtime.bigint().toString(36);
const PASS = 'Regenschirm-7-' + MARKE.slice(-6);

let beanstandet = 0;

function sage(ok, text) {
    console.log((ok ? '  ok   ' : '  FEHL ') + text);

    if (!ok) {
        beanstandet++;
    }
}

const browser = await chromium.launch();
const kontext = await browser.newContext();

// Jede Anfrage, die nicht an den Dienst selbst geht, wäre ein Bruch der
// Zusage - der Schlüssel steht im Fragment und darf nirgends hin.
const fremd = [];
const ziel = new URL(ZIEL).host;

kontext.on('request', (r) => {
    const u = new URL(r.url());

    if (u.host !== ziel && u.protocol !== 'data:') {
        fremd.push(r.url());
    }
});

try {
    // --- Anlegen, mit Passphrase ---------------------------------------
    const anlegen = await kontext.newPage();
    await anlegen.goto(ZIEL + '/', { waitUntil: 'networkidle' });
    await anlegen.fill('#geheimnis', MARKE);
    await anlegen.fill('#passphrase', PASS);
    await anlegen.click('#absenden');
    await anlegen.waitForSelector('#ergebnis:not([hidden])', { timeout: 30000 });

    const link     = (await anlegen.textContent('#link')).trim();
    const fragment = new URL(link).hash.replace(/^#/, '');

    sage(fragment.startsWith('p.'), 'der Link trägt die Passphrase-Markierung');
    sage(fragment.slice(2).length === 43, 'der Schlüssel misst 43 Zeichen (256 Bit)');

    await anlegen.click('#qrZeigen');
    await anlegen.waitForSelector('#qrFlaeche svg', { timeout: 15000 });

    const qr = await anlegen.locator('#qrFlaeche').innerHTML();

    sage(true, 'der QR-Code entsteht nach dem Knopfdruck');
    sage(!qr.includes('http'), 'und enthält keine Adresse im Markup');

    // --- Abruf mit der richtigen Passphrase -----------------------------
    const abruf = await kontext.newPage();
    await abruf.goto('about:blank');
    await abruf.goto(link, { waitUntil: 'networkidle' });
    await abruf.click('#anzeigen');
    await abruf.waitForSelector('#passphraseAbfrage:not([hidden])', { timeout: 20000 });
    await abruf.fill('#passphraseEingabe', PASS);
    await abruf.click('#passphraseAbsenden');
    await abruf.waitForSelector('#ergebnis:not([hidden])', { timeout: 30000 });

    sage((await abruf.textContent('#inhalt')).includes(MARKE), 'der Inhalt kommt zurück');

    // --- Und beim zweiten Mal ist nichts mehr da -------------------------
    // Die Passphrase wird auch hier zuerst abgefragt: Die Seite liest am
    // Fragment ab, dass eine nötig ist, und fragt danach, bevor sie den
    // Server überhaupt anspricht. Erst danach zeigt sich, dass dort nichts
    // mehr liegt.
    const nochmal = await kontext.newPage();
    await nochmal.goto('about:blank');
    await nochmal.goto(link, { waitUntil: 'networkidle' });
    await nochmal.click('#anzeigen');
    await nochmal.waitForSelector('#passphraseAbfrage:not([hidden])', { timeout: 20000 });
    await nochmal.fill('#passphraseEingabe', PASS);
    await nochmal.click('#passphraseAbsenden');
    await nochmal.waitForSelector('#fortgeschrieben:not([hidden])', { timeout: 30000 });

    sage(!(await nochmal.locator('body').innerText()).includes(MARKE), 'der zweite Abruf findet nichts mehr');

    // --- Ein zweites Geheimnis für den Fehlversuch -----------------------
    // Ein falscher Versuch verbraucht den Inhalt. Das ist so gewollt und
    // steht auch auf der Seite - deshalb ein eigenes Geheimnis dafür.
    const zweites = await kontext.newPage();
    await zweites.goto(ZIEL + '/', { waitUntil: 'networkidle' });
    await zweites.fill('#geheimnis', MARKE + '-zwei');
    await zweites.fill('#passphrase', PASS);
    await zweites.click('#absenden');
    await zweites.waitForSelector('#ergebnis:not([hidden])', { timeout: 30000 });

    const link2 = (await zweites.textContent('#link')).trim();

    const daneben = await kontext.newPage();
    await daneben.goto(link2, { waitUntil: 'networkidle' });
    await daneben.click('#anzeigen');
    await daneben.waitForSelector('#passphraseAbfrage:not([hidden])', { timeout: 20000 });
    await daneben.fill('#passphraseEingabe', 'daneben-geraten');
    await daneben.click('#passphraseAbsenden');
    await daneben.waitForSelector('#fehlgeschlagen:not([hidden])', { timeout: 30000 });

    const text = await daneben.locator('body').innerText();

    sage(!text.includes(MARKE + '-zwei'), 'die falsche Passphrase gibt nichts preis');
    sage((await daneben.textContent('#inhalt')).trim() === '', 'und keinen Teilinhalt');
    sage(text.includes('trotzdem gelöscht'), 'die Seite sagt, dass der Inhalt weg ist');

    // --- Englische Fassung ----------------------------------------------
    const englisch = await kontext.newPage();
    await englisch.goto(ZIEL + '/en', { waitUntil: 'networkidle' });

    sage(await englisch.getAttribute('html', 'lang') === 'en', 'die englische Fassung meldet lang="en"');

    // --- Und nichts davon ging nach außen --------------------------------
    sage(fremd.length === 0, `keine Anfrage an eine fremde Adresse (${fremd.length})`);

    if (fremd.length > 0) {
        fremd.slice(0, 5).forEach((u) => console.log('         ' + u));
    }
} finally {
    await browser.close();
}

console.log('');
console.log(beanstandet === 0
    ? `Funktionsprobe gegen ${ZIEL}: alles in Ordnung.`
    : `Funktionsprobe gegen ${ZIEL}: ${beanstandet} Beanstandung(en).`);

process.exit(beanstandet === 0 ? 0 : 1);
