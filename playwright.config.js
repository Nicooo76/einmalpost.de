// Browsertests gegen einen echten Browser und einen echten PHP-Server.
// Playwright ist ein Entwicklungswerkzeug und wird nie ausgeliefert.

import { defineConfig, devices } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import { existsSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const WURZEL = path.dirname(fileURLToPath(import.meta.url));
const PORT = process.env.E2E_PORT ? Number(process.env.E2E_PORT) : 8737;
const BASE_URL = `http://127.0.0.1:${PORT}`;

// Der Server muss die Testdatenbank treffen, nicht die Entwicklungsdatenbank.
// Wird Playwright direkt statt über "make e2e" gestartet, fehlt die Angabe -
// dann wird sie hier erzeugt, statt den Server stumm ins Leere laufen zu
// lassen. Die Testdatenbank selbst legt "make testdb" an.
const KONFIG = process.env.EINMALPOST_CONFIG || path.join(WURZEL, 'build/config.test.php');

if (!existsSync(KONFIG)) {
    execFileSync('php', [path.join(WURZEL, 'tools/write-test-config.php'), KONFIG], { stdio: 'inherit' });
}

export default defineConfig({
    testDir: './tests/e2e',
    fullyParallel: true,
    forbidOnly: !!process.env.CI,
    retries: 0,
    workers: process.env.CI ? 1 : undefined,
    reporter: [['list']],

    use: {
        baseURL: BASE_URL,
        trace: 'retain-on-failure',
        // Der Schlüssel steht im Fragment. Playwright darf ihn nicht wegkürzen.
        ignoreHTTPSErrors: false,
    },

    // Jeder Test läuft in beiden Farbschemata. Ein Element, das nur in
    // einem davon lesbar ist - heller Text auf hellem Grund -, fällt sonst
    // niemandem auf, bis es jemandem auffällt.
    //
    // Dazu ein echtes Telefonprofil: schmaler Bildschirm, Berührung statt
    // Zeiger. Ein Knopf, den man mit dem Daumen nicht trifft, ist kaputt,
    // auch wenn er am großen Bildschirm gut aussieht.
    projects: [
        { name: 'chromium-hell', use: { ...devices['Desktop Chrome'], colorScheme: 'light' } },
        { name: 'chromium-dunkel', use: { ...devices['Desktop Chrome'], colorScheme: 'dark' } },
        { name: 'firefox-hell', use: { ...devices['Desktop Firefox'], colorScheme: 'light' } },
        { name: 'firefox-dunkel', use: { ...devices['Desktop Firefox'], colorScheme: 'dark' } },
        { name: 'webkit-hell', use: { ...devices['Desktop Safari'], colorScheme: 'light' } },
        { name: 'webkit-dunkel', use: { ...devices['Desktop Safari'], colorScheme: 'dark' } },
        { name: 'telefon', use: { ...devices['Pixel 5'], colorScheme: 'dark' } },
    ],

    webServer: {
        // PHP_CLI_SERVER_WORKERS: Ohne mehrere Arbeitsprozesse kann der eingebaute
        // Server gleichzeitige Anfragen nicht wirklich gleichzeitig bearbeiten -
        // und genau das muss für die Prüfung des atomaren Verbrauchs möglich sein.
        command: `PHP_CLI_SERVER_WORKERS=8 php -S 127.0.0.1:${PORT} -t public public/index.php`,
        url: BASE_URL,
        reuseExistingServer: !process.env.CI,
        stdout: 'pipe',
        stderr: 'pipe',
        // Der Server bekommt eine eigene Konfigurationsdatei untergeschoben,
        // damit er die Testdatenbank trifft und nicht die Entwicklungs-
        // datenbank. config/config.php hätte sonst Vorrang.
        env: {
            EINMALPOST_CONFIG: KONFIG,
        },
    },
});
